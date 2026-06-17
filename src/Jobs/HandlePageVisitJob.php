<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

class HandlePageVisitJob extends BaseRanetraceJob
{
    public function __construct(
        protected array $visitData
    ) {
        $this->assignQueue();
    }

    /**
     * @return array<string, mixed>
     */
    public function getVisitData(): array
    {
        return $this->visitData;
    }

    public function handle(RanetraceBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->visitData);

        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $this->bufferOrRelease($buffer, 'page_visits', $payload);
    }

    protected function getConfigPath(): string
    {
        return 'ranetrace.website_analytics';
    }

    /**
     * @return array<int, string>
     */
    protected function getAllowedKeys(): array
    {
        $keys = [
            'url',
            'path',
            'timestamp',
            'referrer',
            'country_code',
            'device_type',
            'browser_name',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'session_id_hash',
            'user_agent_hash',
            'human_probability_score',
            'human_probability_reasons',
        ];

        // Development mode that preserves the unhashed user agent.
        // Using this setting in production is pointless and unsafe;
        // Ranetrace will ignore non-hashed user agents.
        if (config('ranetrace.website_analytics.debug.preserve_user_agent', false)) {
            $keys[] = 'user_agent';
        }

        return $keys;
    }
}
