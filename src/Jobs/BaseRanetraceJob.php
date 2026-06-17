<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\InternalLogger;
use Throwable;

/**
 * Base class for all Ranetrace jobs providing common functionality.
 */
abstract class BaseRanetraceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Seconds to wait before re-attempting a capture job that could not acquire
     * the buffer lock, giving the contended lock time to clear.
     */
    protected const int BUFFER_RETRY_DELAY = 5;

    /**
     * Total attempts for a capture job. Lets bufferOrRelease() re-queue an item
     * a couple of times when the buffer lock is briefly contended, instead of
     * dropping it on the first miss. Bounded so a permanently stuck lock cannot
     * loop a job forever.
     */
    public int $tries = 3;

    /**
     * Get the config path for this job type.
     */
    abstract protected function getConfigPath(): string;

    /**
     * Get the allowed keys for payload filtering.
     *
     * @return array<int, string>
     */
    abstract protected function getAllowedKeys(): array;

    /**
     * Handle job failure after all retries exhausted.
     * Logs to 'ranetrace_internal' channel to prevent infinite error loops (never logs to Ranetrace).
     */
    public function failed(Throwable $exception): void
    {
        InternalLogger::critical('Ranetrace job failed after all retries', [
            'job_class' => static::class,
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * Buffer a captured item, re-queuing this job if the buffer lock could not
     * be acquired within its wait window.
     *
     * The buffer already blocks briefly for the lock, so a miss here is rare
     * (effectively only a stuck/crashed holder). Re-queuing rather than dropping
     * keeps a captured item from being lost to transient contention. The attempt
     * cap ($tries) bounds the retries so a permanently stuck lock cannot loop the
     * job forever — at which point the item is dropped, matching the package's
     * "lose data before crashing the host" contract. release() never throws into
     * the host (and is a no-op for inline/sync dispatch).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function bufferOrRelease(RanetraceBatchBuffer $buffer, string $type, array $payload): void
    {
        if ($buffer->addItem($type, $payload)) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $this->release(self::BUFFER_RETRY_DELAY);
        }
    }

    /**
     * Filter payload to only include allowed keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function filterPayload(array $data): array
    {
        return collect($data)
            ->only($this->getAllowedKeys())
            ->toArray();
    }

    /**
     * Assign the job to the configured queue.
     */
    protected function assignQueue(): void
    {
        $queueName = config($this->getConfigPath().'.queue_name', 'default');
        $this->onQueue($queueName);
    }
}
