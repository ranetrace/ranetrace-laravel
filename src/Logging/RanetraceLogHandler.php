<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Utilities\DataSanitizer;
use Throwable;

/**
 * Per-field caps below combine with the batch ceiling to keep a 1000-log
 * batch under the API's 5MB request limit. NOT user-tunable: raising any
 * of these risks 413 batch rejections.
 */
class RanetraceLogHandler extends AbstractProcessingHandler
{
    private const string TRUNCATION_SUFFIX = '... (truncated)';

    private const int MAX_MESSAGE_LENGTH = 50_000;

    private const int MAX_CONTEXT_BYTES = 51_200; // 50 KB

    private const int MAX_EXTRA_BYTES = 10_240; // 10 KB

    /**
     * Writes the record down to the log of the implementing handler.
     *
     * The entire body is wrapped in try/catch: this handler sits in the host
     * application's `Log::error(...)` path, so it MUST never throw back into
     * the caller's business code. (Failure-isolation Core Rule.)
     */
    protected function write(LogRecord $record): void
    {
        try {
            // Skip if Ranetrace is not enabled globally
            if (! config('ranetrace.enabled', true)) {
                return;
            }

            // Skip if logging is not enabled
            if (! config('ranetrace.logging.enabled', false)) {
                return;
            }

            // Skip if the channel should be excluded
            $channel = $record->channel;
            $excludedChannels = config('ranetrace.logging.excluded_channels', []);
            if (in_array($channel, $excludedChannels, true)) {
                return;
            }

            // Truncate message to stay within API per-item limits
            $message = $record->message;
            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - mb_strlen(self::TRUNCATION_SUFFIX)).self::TRUNCATION_SUFFIX;
            }

            // Cap context/extra sizes (replace mid-structure rather than
            // truncate, since partial JSON is invalid).
            $context = DataSanitizer::sanitizeForSerialization($record->context);
            if (mb_strlen((string) json_encode($context), '8bit') > self::MAX_CONTEXT_BYTES) {
                $context = ['_truncated' => 'Context exceeded 50KB limit and was removed'];
            }

            $extra = DataSanitizer::sanitizeForSerialization(array_merge($record->extra, [
                'environment' => config('app.env'),
                'laravel_version' => app()->version(),
                'php_version' => phpversion(),
            ]));
            if (mb_strlen((string) json_encode($extra), '8bit') > self::MAX_EXTRA_BYTES) {
                $extra = ['_truncated' => 'Extra data exceeded 10KB limit and was removed'];
            }

            $logData = [
                'level' => mb_strtolower($record->level->name),
                'message' => $message,
                'context' => $context,
                'channel' => $channel,
                'timestamp' => $record->datetime->format('c'), // ISO 8601
                'extra' => $extra,
            ];

            if (config('ranetrace.logging.queue', true)) {
                HandleLogJob::dispatch($logData);
            } else {
                HandleLogJob::dispatchSync($logData);
            }
        } catch (Throwable $e) {
            // Fail silently — the handler must never propagate exceptions into
            // the host's logging call site. Diagnose via the internal channel.
            InternalLogger::warning('Failed to capture log to Ranetrace', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
