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

class HandleJavaScriptErrorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected array $errorData
    ) {
        // Optionally assign queue name from config
        $this->onQueue(config('ranetrace.javascript_errors.queue_name', 'default'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrorData(): array
    {
        return $this->errorData;
    }

    public function handle(RanetraceBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->errorData);

        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $buffer->addItem('javascript_errors', $payload);
    }

    /**
     * Handle job failure after all retries exhausted.
     * Logs to 'ranetrace_internal' channel to prevent infinite error loops (never logs to Ranetrace).
     */
    public function failed(Throwable $exception): void
    {
        // Use 'ranetrace_internal' channel
        // to prevent infinite loops by bypassing Ranetrace's own capture
        InternalLogger::critical('Ranetrace job failed after all retries', [
            'job_class' => static::class,
            'exception' => $exception->getMessage(),
        ]);
    }

    protected function filterPayload(array $data): array
    {
        $allowedKeys = [
            'message',
            'stack',
            'type',
            'filename',
            'line',
            'column',
            'user_agent',
            'url',
            'timestamp',
            'environment',
            'user_id',
            'session_id',
            'breadcrumbs',
            'context',
            'browser_info',
        ];

        return collect($data)
            ->only($allowedKeys)
            ->toArray();
    }
}
