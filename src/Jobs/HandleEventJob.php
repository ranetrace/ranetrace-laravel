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
        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $this->bufferOrRelease($buffer, 'events', $this->bufferedItem());
    }

    /**
     * The item this job buffers: the captured data held to the allowed keys
     * and the per-item byte budget, or null when it is over that budget.
     *
     * @internal Shared with `ranetrace:test`, which sends this item straight to the API instead.
     *
     * @return array<string, mixed>|null
     */
    public function bufferedItem(): ?array
    {
        return $this->filterPayload($this->eventData);
    }

    protected function getConfigPath(): string
    {
        return 'ranetrace.events';
    }

    /**
     * @return array<int, string>
     */
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
