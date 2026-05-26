<?php

declare(strict_types=1);

namespace Ranetrace\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
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
     * Truncation suffix appended to fields that exceed their length limit.
     */
    protected const string TRUNCATION_SUFFIX = '... (truncated)';

    /**
     * Per-field caps. These keep each error item small enough that a 1000-item
     * batch fits comfortably under the API's 5MB request limit. NOT user-tunable:
     * raising any of these risks 413 batch rejections.
     */
    protected const int MAX_MESSAGE_LENGTH = 10_000;

    protected const int MAX_TRACE_LENGTH = 5_000;

    protected const int MAX_FILE_PATH_LENGTH = 500;

    protected const int MAX_URL_LENGTH = 2_000;

    protected const int MAX_SOURCE_FILE_BYTES = 1_048_576; // 1 MB

    /**
     * Bounded shape for the `headers` field (now sent as a nested object, not a
     * JSON-encoded string).
     */
    protected const int MAX_HEADER_COUNT = 50;

    protected const int MAX_HEADER_VALUE_LENGTH = 500;

    /**
     * Bounded shape for the `console_arguments` field (now sent as an array,
     * not a JSON-encoded string).
     */
    protected const int MAX_CONSOLE_ARGV_COUNT = 50;

    protected const int MAX_CONSOLE_ARGV_LENGTH = 500;

    /**
     * Request headers considered safe to capture in plaintext. Every other
     * header is masked, so a header carrying a secret that we did not
     * anticipate is masked by default rather than leaked.
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

    public function trackEvent(string $eventName, array $properties = [], int|string|null $userId = null, bool $validate = true): void
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
            // getAuthIdentifier() is the safe contract method — works for any
            // Authenticatable, Eloquent or not. Skip the Auth lookup entirely
            // when the caller already provided a userId.
            if ($userId !== null) {
                $user = ['id' => $userId];
            } else {
                $authenticated = Auth::user();
                $user = $authenticated !== null
                    ? ['id' => $authenticated->getAuthIdentifier()]
                    : null;
            }

            $eventData = [
                'event_name' => $eventName,
                'properties' => DataSanitizer::sanitizeForSerialization($properties),
                'user' => $user,
                'timestamp' => Carbon::now()->toISOString(),
                'url' => app()->runningInConsole() ? null : request()->fullUrl(),
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
     * Mask every request header not on the safe-header allowlist AND cap the
     * header count + per-value length. Returns a nested object shape (header
     * name → array of values), which goes on the wire directly (no JSON string
     * encoding).
     *
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string, array<int, string>>
     */
    private function maskAndBoundHeaders(array $headers): array
    {
        $bounded = [];

        foreach (array_slice($headers, 0, self::MAX_HEADER_COUNT, true) as $name => $values) {
            $values = is_array($values) ? $values : [$values];

            if (! in_array($name, self::SAFE_HEADERS, true)) {
                $bounded[$name] = ['***'];

                continue;
            }

            $bounded[$name] = array_map(
                fn (mixed $v): string => $this->truncate((string) $v, self::MAX_HEADER_VALUE_LENGTH),
                $values
            );
        }

        return $bounded;
    }

    /**
     * Bound `console_arguments` shape: cap argv count + per-value length.
     * Returns an array (no JSON string encoding).
     *
     * @param  array<int, mixed>  $argv
     * @return array<int, string>
     */
    private function boundConsoleArgv(array $argv): array
    {
        $argv = array_slice($argv, 0, self::MAX_CONSOLE_ARGV_COUNT);

        return array_map(
            fn (mixed $v): string => $this->truncate((string) $v, self::MAX_CONSOLE_ARGV_LENGTH),
            $argv
        );
    }

    /**
     * Truncate a string to at most $maxLength characters, including the
     * truncation suffix. The final length never exceeds $maxLength.
     */
    private function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength - mb_strlen(self::TRUNCATION_SUFFIX)).self::TRUNCATION_SUFFIX;
    }

    /**
     * Shape the authenticated user into the error payload's `user` field.
     *
     * - getAuthIdentifier() is on the Authenticatable contract — safe across
     *   every implementation, Eloquent or not.
     * - getAttribute() returns null when the column is missing, so a host app
     *   whose User model has no `email` does not break error capture. The
     *   method_exists guard covers non-Eloquent custom Authenticatables;
     *   PHPStan doesn't know the host app may not use Eloquent.
     *
     * @return array{id: mixed, email: mixed}|null
     */
    private function buildUserPayload(?Authenticatable $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getAuthIdentifier(),
            // @phpstan-ignore function.alreadyNarrowedType
            'email' => method_exists($user, 'getAttribute')
                ? $user->getAttribute('email')
                : null,
        ];
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

        $headers = null;
        $url = null;
        $method = null;

        // Determine if the error occurred in a console command
        $isConsole = app()->runningInConsole();

        // If the error occurred via HTTP, gather request data
        if (! $isConsole) {
            $headers = $this->maskAndBoundHeaders($request->headers->all());
            $url = $this->truncate($request->fullUrl(), self::MAX_URL_LENGTH);
            $method = $request->method();
        }

        // Get code context (only for readable, reasonably-sized source files)
        $file = $exception->getFile();
        $line = $exception->getLine();
        $context = null;
        $highlightLine = null;

        if (is_readable($file) && filesize($file) < self::MAX_SOURCE_FILE_BYTES) {
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

        if ($isConsole) {
            if (defined('ARTISAN_BINARY')) {
                $consoleCommand = implode(' ', $_SERVER['argv'] ?? []);
            }

            $consoleArguments = $this->boundConsoleArgv(request()->server('argv') ?? []);
        }

        // Truncate variable-length string fields to per-field caps
        $message = $this->truncate($exception->getMessage(), self::MAX_MESSAGE_LENGTH);
        $trace = $this->truncate($exception->getTraceAsString(), self::MAX_TRACE_LENGTH);

        // File paths are truncated from the LEFT (keep the tail), unlike every
        // other field — the filename + last segments matter more than the
        // repo root prefix when debugging.
        if ($file && mb_strlen($file) > self::MAX_FILE_PATH_LENGTH) {
            $file = mb_substr($file, -self::MAX_FILE_PATH_LENGTH);
        }

        return [
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'type' => get_class($exception),
            'environment' => config('app.env'),
            'trace' => $trace,
            'headers' => $headers,
            'context' => $context,
            'highlight_line' => $highlightLine,
            'user' => $this->buildUserPayload($user),
            'time' => Carbon::now()->toDateTimeString(),
            'url' => $url,
            'method' => $method,
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
            'is_console' => $isConsole,
            'console_command' => $consoleCommand,
            'console_arguments' => $consoleArguments,
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
