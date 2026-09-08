<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\VisitVerificationMark;

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

    /**
     * Resolve the human-verification flag, then buffer the visit.
     *
     * A visit captured while the beacon is enabled arrives carrying a
     * `view_token` and `verified_human = false`. This job runs `wait_seconds`
     * after the page was served, so by then the beacon has either marked that
     * token or it never will, and the mark decides the flag. `pull()` reads and
     * forgets in one call, because the mark exists for exactly this one run.
     *
     * The token is a local correlation handle, never a field: it is not on the
     * allow-list, and it is unset here as well so nothing downstream can
     * mistake it for one.
     */
    public function handle(RanetraceBatchBuffer $buffer): void
    {
        $visitData = $this->visitData;

        if (isset($visitData['view_token'])) {
            $visitData['verified_human'] = VisitVerificationMark::pull((string) $visitData['view_token']);
            unset($visitData['view_token']);
        }

        $payload = $this->filterPayload($visitData);

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
            // Present only while the human-verification beacon is enabled.
            // `view_token`, which carries it, is deliberately absent: it is a
            // local correlation handle and must never reach the wire.
            'verified_human',
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
