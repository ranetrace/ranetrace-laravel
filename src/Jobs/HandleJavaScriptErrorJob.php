<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

class HandleJavaScriptErrorJob extends BaseRanetraceJob
{
    public function __construct(
        protected array $errorData
    ) {
        $this->assignQueue();
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

    protected function getConfigPath(): string
    {
        return 'ranetrace.javascript_errors';
    }

    /**
     * @return array<int, string>
     */
    protected function getAllowedKeys(): array
    {
        return [
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
    }
}
