<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

class HandleEventJob extends BaseRanetraceJob
{
    public function __construct(
        protected array $eventData
    ) {
        $this->assignQueue();
    }

    /**
     * @return array<string, mixed>
     */
    public function getEventData(): array
    {
        return $this->eventData;
    }

    public function handle(RanetraceBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->eventData);

        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $buffer->addItem('events', $payload);
    }

    protected function getConfigPath(): string
    {
        return 'ranetrace.events';
    }

    protected function getAllowedKeys(): array
    {
        return [
            'event_name',
            'properties',
            'user',
            'timestamp',
            'url',
            'user_agent_hash',
            'session_id_hash',
        ];
    }
}
