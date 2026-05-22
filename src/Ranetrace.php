<?php

declare(strict_types=1);

namespace Ranetrace\Laravel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Ranetrace\Laravel\Analytics\FingerprintGenerator;
use Ranetrace\Laravel\Events\EventTracker;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Utilities\DataSanitizer;
use Throwable;

class Ranetrace
{
    /**
     * Request headers considered safe to capture. Every other header is masked,
     * so a header carrying a secret that we did not anticipate is masked by
     * default rather than leaked into the error backend.
     *
     * @var array<int, string>
     */
    protected const array SAFE_HEADERS = [
        'accept',
        'accept-charset',
        'accept-encoding',
        'accept-language',
        'cache-control',
        'connection',
        'content-length',
        'content-type',
        'host',
        'referer',
        'user-agent',
        'x-requested-with',
        'x-forwarded-for',
        'x-forwarded-proto',
        'x-forwarded-host',
    ];

    public function report(Throwable $exception): void
    {
        if (! $this->isCaptureEnabled('errors')) {
            return;
        }

        // Ranetrace must never throw from its capture path — losing a single
        // error event is acceptable, breaking the host application is not.
        // See specs/.../client-internal-error-handling.md (Core Rule).
        try {
            $data = $this->buildErrorPayload($exception);

            if (config('ranetrace.errors.queue', true)) {
                HandleErrorJob::dispatch($data);
            } else {
                HandleErrorJob::dispatchSync($data);
            }
        } catch (Throwable $e) {
            InternalLogger::error('Failed to capture exception', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function trackEvent(string $eventName, array $properties = [], ?int $userId = null, bool $validate = true): void
    {
        if (! $this->isCaptureEnabled('events')) {
            return;
        }

        // Validation stays OUTSIDE the try/catch: an invalid event name is a
        // developer mistake and should fail loudly during development.
        if ($validate) {
            EventTracker::ensureValidEventName($eventName);
        }

        // Everything past validation must never throw into the caller's
        // business logic — fail silently per the package's Core Rule.
        try {
            $user = $userId ? ['id' => $userId] : (Auth::user() ? ['id' => Auth::id()] : null);

            $eventData = [
                'event_name' => $eventName,
                'properties' => DataSanitizer::sanitizeForSerialization($properties),
                'user' => $user,
                'timestamp' => Carbon::now()->toISOString(),
                'url' => request()->fullUrl(),
                'user_agent_hash' => FingerprintGenerator::generateUserAgentHash(),
                'session_id_hash' => FingerprintGenerator::generateSessionIdHash(),
            ];

            if (config('ranetrace.events.queue', true)) {
                HandleEventJob::dispatch($eventData);
            } else {
                HandleEventJob::dispatchSync($eventData);
            }
        } catch (Throwable $e) {
            InternalLogger::error('Failed to track event', [
                'event_name' => $eventName,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine whether capture is enabled for the given feature: the package
     * must be enabled, the feature itself enabled, and an API key configured.
     */
    private function isCaptureEnabled(string $feature): bool
    {
        return config('ranetrace.enabled', true)
            && config("ranetrace.{$feature}.enabled", true)
            && ! empty(config('ranetrace.key'));
    }

    /**
     * Mask every request header not on the safe-header allowlist, so a header
     * carrying a secret we did not anticipate is masked rather than leaked.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function maskUnsafeHeaders(array $headers): array
    {
        foreach ($headers as $header => &$value) {
            if (! in_array($header, self::SAFE_HEADERS, true)) {
                $value = '***';
            }
        }
        unset($value);

        return $headers;
    }

    /**
     * Build the error payload sent to Ranetrace.
     *
     * @return array<string, mixed>
     */
    private function buildErrorPayload(Throwable $exception): array
    {
        $request = Request::instance();
        $user = Auth::user();

        $phpVersion = phpversion();
        $laravelVersion = app()->version();

        $headers = null;
        $url = null;
        $method = null;

        // Determine if the error occurred in a console command
        $isConsole = app()->runningInConsole();

        // If the error occurred via HTTP, gather request data
        if (! $isConsole) {
            $headers = json_encode($this->maskUnsafeHeaders($request->headers->all()));

            $url = $request->fullUrl();
            $method = $request->method();
        }

        // Get code context
        $file = $exception->getFile();
        $line = $exception->getLine();
        $context = null;
        $highlightLine = null;

        $maxFileSize = 1048576; // 1MB
        if (is_readable($file) && filesize($file) < $maxFileSize) {
            $lines = file($file);
            if (is_array($lines)) {
                $startLine = max(0, $line - 6); // 5 lines before the error line
                $contextLines = array_slice($lines, $startLine, 11, true); // Total 11 lines
                $context = $this->cleanCode(implode('', $contextLines));
                $highlightLine = $line - $startLine; // Relative line to highlight
            }
        }

        // Gather console-specific data if applicable
        $consoleCommand = null;
        $consoleArguments = null;
        $consoleOptions = null;

        if ($isConsole) {
            if (defined('ARTISAN_BINARY')) {
                $consoleCommand = implode(' ', $_SERVER['argv'] ?? []);
            }

            $consoleArguments = json_encode(request()->server('argv') ?? []);
        }

        // Trace
        $trace = $exception->getTraceAsString();
        $maxTraceLength = 5000;
        $truncationSuffix = '... (truncated)';

        if (mb_strlen($trace) > $maxTraceLength) {
            $trace = mb_substr($trace, 0, $maxTraceLength - mb_strlen($truncationSuffix)).$truncationSuffix;
        }

        // Truncate fields to stay within API 5MB request limit
        $message = $exception->getMessage();
        if (mb_strlen($message) > 10000) {
            $message = mb_substr($message, 0, 10000 - mb_strlen($truncationSuffix)).$truncationSuffix;
        }

        if ($file && mb_strlen($file) > 500) {
            $file = mb_substr($file, -500); // Keep last 500 chars (end of path)
        }

        if ($url && mb_strlen($url) > 2000) {
            $url = mb_substr($url, 0, 2000 - mb_strlen($truncationSuffix)).$truncationSuffix;
        }

        if ($headers && mb_strlen($headers) > 5000) {
            $headers = mb_substr($headers, 0, 5000 - mb_strlen($truncationSuffix)).$truncationSuffix;
        }

        $time = Carbon::now()->toDateTimeString();

        return [
            'for' => 'ranetrace',
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'type' => get_class($exception),
            'environment' => config('app.env'),
            'trace' => $trace,
            'headers' => $headers,
            'context' => $context,
            'highlight_line' => $highlightLine,
            'user' => $user ? ['id' => $user->id, 'email' => $user->email] : null,
            'time' => $time,
            'url' => $url,
            'method' => $method,
            'php_version' => $phpVersion,
            'laravel_version' => $laravelVersion,
            'is_console' => $isConsole,
            'console_command' => $consoleCommand,
            'console_arguments' => $consoleArguments,
            'console_options' => $consoleOptions,
        ];
    }

    private function cleanCode(string $code): string
    {
        // Split the code into individual lines
        $lines = explode("\n", $code);

        // Trim each line to remove leading/trailing whitespace
        $trimmedLines = array_map('rtrim', $lines);

        // Find the first line with actual content and determine the minimum indentation
        $minIndent = null;
        foreach ($trimmedLines as $line) {
            if (mb_trim($line) !== '') { // Skip empty lines
                $indent = mb_strlen($line) - mb_strlen(mb_ltrim($line));
                if ($minIndent === null || $indent < $minIndent) {
                    $minIndent = $indent;
                }
            }
        }

        // Remove the minimum indentation from all lines
        if ($minIndent > 0) {
            foreach ($trimmedLines as &$line) {
                if (mb_trim($line) !== '') {
                    $line = mb_substr($line, $minIndent);
                }
            }
        }

        // Rejoin the lines and return the cleaned-up code
        return implode("\n", $trimmedLines);
    }
}
