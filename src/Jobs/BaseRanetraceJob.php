<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ranetrace\Laravel\Support\InternalLogger;
use Throwable;

/**
 * Base class for all Ranetrace jobs providing common functionality.
 */
abstract class BaseRanetraceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
