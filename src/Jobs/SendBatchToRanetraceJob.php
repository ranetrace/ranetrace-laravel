<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Support\InternalLogger;
use RuntimeException;
use Throwable;

class SendBatchToRanetraceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cache key prefix for the per-type "last successful batch" timestamp,
     * read by ranetrace:status to detect a stalled/unscheduled work command.
     */
    public const string LAST_BATCH_PREFIX = 'ranetrace:last_batch:';

    /**
     * Soft byte budget for one batch request. The API hard-limits requests to
     * 5MB; we trim to ~4.5MB and re-buffer the rest, leaving headroom for the
     * JSON envelope so an oversize 413 (whole-batch discard + 15-min pause) is
     * impossible. Single items are separately bounded by the per-field caps in
     * Ranetrace / RanetraceLogHandler.
     */
    protected const int MAX_BATCH_BYTES = 4_500_000;

    /**
     * Total attempts: 1 initial + 3 retries. Combined with backoff() of
     * 60/300/900s this produces the 21-minute retry window mandated by
     * client-response-handling.md.
     */
    public int $tries = 4;

    /**
     * Keep the uniqueness lock alive across the full retry envelope (backoff
     * 60+300+900s plus per-attempt runtime) so a concurrent ranetrace:work run
     * cannot dispatch a duplicate batch job for the same type during a retry gap.
     */
    public int $uniqueFor = 1500;

    /** @var array<int, array{id: string, data: array, timestamp: int}> */
    protected array $items = [];

    public function __construct(
        public string $type,
        public ?int $maxItems = null
    ) {
        $queueName = config('ranetrace.batch.queue_name', 'default');
        $this->onQueue($queueName);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "ranetrace:batch:{$this->type}";
    }

    public function handle(RanetraceApiClient $client, RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager): void
    {
        $maxItems = $this->maxItems ?? $this->getMaxBatchSize();

        // Get items from buffer (atomically removes them)
        $this->items = $buffer->getItems($this->type, $maxItems);

        if (empty($this->items)) {
            return;
        }

        // Pre-flight size guard: trim the batch to the byte budget and re-buffer
        // the overflow so an oversize request (413 → whole-batch discard + pause)
        // is impossible. The remainder drains on the next ranetrace:work run.
        $deferred = $this->trimToByteBudget();
        if ($deferred !== []) {
            $buffer->addItems($this->type, array_map(fn (array $item): array => $item['data'], $deferred));
            $this->logInfo('Deferred items to keep the batch under the size limit', [
                'type' => $this->type,
                'sent' => count($this->items),
                'deferred' => count($deferred),
            ]);
        }

        // Extract just the data payloads for the API
        $payloads = array_map(fn ($item) => $item['data'], $this->items);

        // Send batch to Ranetrace API
        $result = match ($this->type) {
            'errors' => $client->sendErrorBatch($payloads),
            'events' => $client->sendEventBatch($payloads),
            'logs' => $client->sendLogBatch($payloads),
            'page_visits' => $client->sendPageVisitBatch($payloads),
            'javascript_errors' => $client->sendJavaScriptErrorBatch($payloads),
            default => throw new InvalidArgumentException("Unknown batch type: {$this->type}"),
        };

        // Handle response based on status code (throws on retryable failures)
        $this->handleResponse($result, $buffer, $pauseManager);
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        $this->logError('Batch job failed after all retries', [
            'type' => $this->type,
            'exception' => $exception->getMessage(),
        ]);

        // Set feature pause for 15 minutes after final retry
        $pauseManager = app(RanetracePauseManager::class);
        $pauseManager->setFeaturePause($this->type, 900, '500');
    }

    /**
     * Calculate backoff time based on attempt number.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900]; // 1min, 5min, 15min
    }

    /**
     * Handle API response according to spec.
     */
    protected function handleResponse(array $result, RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager): void
    {
        $status = $result['status'] ?? 0;
        $data = $result['data'] ?? [];

        // Network-level errors (status 0)
        if ($status === 0) {
            $this->logError('Network error during batch send', [
                'type' => $this->type,
                'error' => $result['error'] ?? 'Unknown network error',
                'items_count' => count($this->items),
            ]);

            // Re-add all items and rethrow to trigger retry
            $this->reAddAllItemsToBuffer($buffer);

            throw new RuntimeException($result['error'] ?? 'Network error');
        }

        // Handle based on HTTP status code
        match ($status) {
            200 => $this->handle200Response($data, $buffer),
            401 => $this->handle401Response($buffer, $pauseManager, $data),
            403 => $this->handle403Response($buffer, $pauseManager, $data),
            413 => $this->handle413Response($pauseManager, $data),
            422 => $this->handle422Response($pauseManager, $data),
            429 => $this->handle429Response($buffer, $pauseManager, $result['headers'] ?? []),
            500 => $this->handle500Response($buffer),
            default => $this->handleUnknownResponse($status, $buffer),
        };
    }

    /**
     * Handle 200 OK response.
     */
    protected function handle200Response(array $data, RanetraceBatchBuffer $buffer): void
    {
        // Record a successful drain so ranetrace:status can detect a stalled worker.
        $this->recordLastBatch();

        $items = $data['items'] ?? [];
        $received = $items['received'] ?? 0;
        $processed = $items['processed'] ?? 0;
        $ignored = $items['ignored'] ?? 0;
        $failed = $items['failed'] ?? 0;
        $unprocessed = $items['unprocessed'] ?? 0;
        $unprocessedIndexes = $data['unprocessed_indexes'] ?? [];

        // Log non-zero failed counts
        if ($failed > 0) {
            $this->logWarning('Some items failed during processing', [
                'type' => $this->type,
                'received' => $received,
                'processed' => $processed,
                'ignored' => $ignored,
                'failed' => $failed,
            ]);
        }

        // Log unprocessed items (timeout scenario)
        if ($unprocessed > 0) {
            $this->logInfo('Some items were not processed due to timeout', [
                'type' => $this->type,
                'received' => $received,
                'processed' => $processed,
                'unprocessed' => $unprocessed,
            ]);

            // Re-add only unprocessed items to buffer
            $this->reAddUnprocessedItemsToBuffer($buffer, $unprocessedIndexes);
        }
    }

    /**
     * Handle 401 Unauthorized response.
     */
    protected function handle401Response(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager, array $data): void
    {
        $this->logError('API authentication failed - invalid or revoked API key', [
            'type' => $this->type,
            'message' => ($data['error'] ?? [])['message'] ?? 'Unauthorized',
        ]);

        // Set global pause for 15 minutes
        $pauseManager->setGlobalPause(900, '401');

        // Re-add all items to buffer
        $this->reAddAllItemsToBuffer($buffer);
    }

    /**
     * Handle 403 Forbidden response.
     */
    protected function handle403Response(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager, array $data): void
    {
        $this->logError('API request forbidden', [
            'type' => $this->type,
            'message' => ($data['error'] ?? [])['message'] ?? 'Forbidden',
        ]);

        // Set feature pause for 15 minutes
        $pauseManager->setFeaturePause($this->type, 900, '403');

        // Re-add all items to buffer
        $this->reAddAllItemsToBuffer($buffer);
    }

    /**
     * Handle 413 Payload Too Large response.
     */
    protected function handle413Response(RanetracePauseManager $pauseManager, array $data): void
    {
        $this->logCritical('Payload too large - indicates client bug', [
            'type' => $this->type,
            'items_count' => count($this->items),
            'message' => ($data['error'] ?? [])['message'] ?? 'Payload Too Large',
        ]);

        // Set feature pause for 15 minutes
        $pauseManager->setFeaturePause($this->type, 900, '413');

        // Do NOT re-add items (they're too large)
    }

    /**
     * Handle 422 Unprocessable Entity response.
     */
    protected function handle422Response(RanetracePauseManager $pauseManager, array $data): void
    {
        $this->logError('Validation failed - indicates schema drift or malformed items', [
            'type' => $this->type,
            'items_count' => count($this->items),
            'message' => ($data['error'] ?? [])['message'] ?? 'Unprocessable Entity',
        ]);

        // Set feature pause for 15 minutes
        $pauseManager->setFeaturePause($this->type, 900, '422');

        // Do NOT re-add items (they're invalid)
    }

    /**
     * Handle 429 Too Many Requests response.
     */
    protected function handle429Response(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager, array $headers): void
    {
        $retryAfter = (int) ($headers['retry-after'] ?? 60);

        $this->logWarning('Rate limit exceeded', [
            'type' => $this->type,
            'retry_after' => $retryAfter,
        ]);

        // Set feature pause based on Retry-After header
        $pauseManager->setFeaturePause($this->type, $retryAfter, '429');

        // Re-add all items to buffer
        $this->reAddAllItemsToBuffer($buffer);
    }

    /**
     * Handle 500 Internal Server Error response.
     */
    protected function handle500Response(RanetraceBatchBuffer $buffer): void
    {
        $this->logError('Server error during batch processing', [
            'type' => $this->type,
            'items_count' => count($this->items),
            'attempt' => $this->attempts(),
        ]);

        // Re-add all items and rethrow to trigger retry
        $this->reAddAllItemsToBuffer($buffer);

        throw new RuntimeException('Server returned 500 error');
    }

    /**
     * Handle unknown status code response.
     */
    protected function handleUnknownResponse(int $status, RanetraceBatchBuffer $buffer): void
    {
        $this->logError('Unexpected API response status', [
            'type' => $this->type,
            'status' => $status,
            'items_count' => count($this->items),
        ]);

        // Re-add all items and rethrow to trigger retry
        $this->reAddAllItemsToBuffer($buffer);

        throw new RuntimeException("Unexpected status code: {$status}");
    }

    /**
     * Re-add all items to the buffer in a single locked operation.
     */
    protected function reAddAllItemsToBuffer(RanetraceBatchBuffer $buffer): void
    {
        $buffer->addItems(
            $this->type,
            array_map(fn (array $item): array => $item['data'], $this->items)
        );
    }

    /**
     * Re-add only unprocessed items to the buffer using their indexes.
     *
     * @param  array<int, int>  $indexes
     */
    protected function reAddUnprocessedItemsToBuffer(RanetraceBatchBuffer $buffer, array $indexes): void
    {
        $dataItems = [];

        foreach ($indexes as $index) {
            if (isset($this->items[$index])) {
                $dataItems[] = $this->items[$index]['data'];
            }
        }

        $buffer->addItems($this->type, $dataItems);
    }

    /**
     * Record the timestamp of a successful batch send for this type, so
     * ranetrace:status can warn when buffers hold items but no recent drain
     * has occurred (a sign ranetrace:work is not scheduled).
     */
    protected function recordLastBatch(): void
    {
        $cacheDriver = config('ranetrace.batch.cache_driver', 'file');

        Cache::store($cacheDriver)->put(
            self::LAST_BATCH_PREFIX.$this->type,
            now()->timestamp,
            now()->addWeek()
        );
    }

    /**
     * Get the maximum batch size for this type.
     */
    protected function getMaxBatchSize(): int
    {
        return 1000; // Per API spec
    }

    /**
     * Trim items off the tail of $this->items so the serialized batch stays
     * within MAX_BATCH_BYTES, returning the removed items for re-buffering.
     * Always keeps at least one item — a single over-budget item can't be split
     * (per-field caps bound single items).
     *
     * @return array<int, array{id: string, data: array, timestamp: int}>
     */
    protected function trimToByteBudget(): array
    {
        $bytes = 0;

        foreach ($this->items as $index => $item) {
            $bytes += mb_strlen((string) json_encode($item['data']), '8bit');

            if ($index > 0 && $bytes > self::MAX_BATCH_BYTES) {
                $deferred = array_slice($this->items, $index);
                $this->items = array_slice($this->items, 0, $index);

                return $deferred;
            }
        }

        return [];
    }

    /**
     * Log to ranetrace_internal channel at error level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logError(string $message, array $context = []): void
    {
        InternalLogger::error($message, $context);
    }

    /**
     * Log to ranetrace_internal channel at warning level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logWarning(string $message, array $context = []): void
    {
        InternalLogger::warning($message, $context);
    }

    /**
     * Log to ranetrace_internal channel at info level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logInfo(string $message, array $context = []): void
    {
        InternalLogger::info($message, $context);
    }

    /**
     * Log to ranetrace_internal channel at critical level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logCritical(string $message, array $context = []): void
    {
        InternalLogger::critical($message, $context);
    }
}
