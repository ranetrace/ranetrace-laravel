<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

class HandleLogJob extends BaseRanetraceJob
{
    public function __construct(
        protected array $logData
    ) {
        $this->assignQueue();
    }

    /**
     * @return array<string, mixed>
     */
    public function getLogData(): array
    {
        return $this->logData;
    }

    public function handle(RanetraceBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->logData);

        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $buffer->addItem('logs', $payload);
    }

    protected function getConfigPath(): string
    {
        return 'ranetrace.logging';
    }

    /**
     * @return array<int, string>
     */
    protected function getAllowedKeys(): array
    {
        return [
            'level',
            'message',
            'context',
            'channel',
            'timestamp',
            'extra',
        ];
    }
}
