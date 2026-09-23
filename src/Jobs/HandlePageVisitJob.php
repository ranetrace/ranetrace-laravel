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
     * `view_token`, `verified_human = false` and `held_until`, the unix time
     * its hold ends. This job is delayed until then, so by the time it runs
     * the beacon has either marked that token or it never will, and the mark
     * decides the flag. `pull()` reads and forgets in one call, because the
     * mark exists for exactly this one run.
     *
     * Three outcomes:
     *
     * - A mark: `verified_human` is true, however early or late the job ran.
     * - No mark, and the job runs before the hold ended: the connection did
     *   not honour the delay (a failover connection that fell through to a
     *   target that runs jobs at once, such as the `deferred` its stock config
     *   ends in), so no beacon has had time to answer. The visit goes out with
     *   no `verified_human` field at all, exactly like a visit on a connection
     *   known up front not to hold it: unknown, which the app judges by the
     *   score, rather than false, which it drops.
     * - No mark, and the hold has ended: the beacon never came, so false.
     *
     * `held_until` is stamped by the web machine's clock and read against the
     * worker's. Skew between them is not corrected for and needs no tolerance:
     * a worker clock behind the web clock only makes an on-time run look early,
     * which sends the visit as unknown and lets the score decide, never as a
     * false that drops a visitor. A worker clock ahead of it could make an
     * early run look on time, but a connection that honours the delay does not
     * run it early to begin with.
     *
     * The token and the hold end are local handles, never fields: neither is
     * on the allow-list, and both are unset here as well so nothing
     * downstream can mistake them for one.
     */
    public function handle(RanetraceBatchBuffer $buffer): void
    {
        $visitData = $this->visitData;

        if (isset($visitData['view_token'])) {
            $isMarked = VisitVerificationMark::pull((string) $visitData['view_token']);
            $ranBeforeHoldEnded = isset($visitData['held_until'])
                && now()->getTimestamp() < (int) $visitData['held_until'];

            if ($isMarked) {
                $visitData['verified_human'] = true;
            } elseif ($ranBeforeHoldEnded) {
                unset($visitData['verified_human']);
            } else {
                $visitData['verified_human'] = false;
            }
        }

        unset($visitData['view_token'], $visitData['held_until']);

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
            // `view_token` and `held_until`, which decide it, are deliberately
            // absent: they are local handles and must never reach the wire.
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
