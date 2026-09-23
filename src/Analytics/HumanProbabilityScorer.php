<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HumanProbabilityScorer
{
    /**
     * Default thresholds for determining if a request is human
     */
    protected const array DEFAULT_THRESHOLDS = [
        'likely_human' => 70,    // Score >= 70: Likely human
        'possibly_human' => 50,  // Score >= 50: Possibly human
        'likely_bot' => 30,      // Score < 30: Likely bot
    ];

    /**
     * Default scoring weights for different factors
     */
    protected const array DEFAULT_WEIGHTS = [
        // User agent characteristics
        'user_agent_length' => 10,        // User agent with reasonable length
        'user_agent_suspicious' => -30,   // User agent contains suspicious patterns
        'user_agent_variety' => 15,       // User agent has a varied / complex structure
        'user_agent_non_standard' => -25, // User agent lacks typical browser structure

        // Request behavior
        'has_referer' => 15,             // Request has a referer header
        'valid_referer' => 10,           // Request has a valid/reasonable referer
        'request_headers' => 15,         // Request has a normal set of headers

        // Request frequency
        'request_frequency' => -25,      // Makes too many requests in a short time
    ];

    /**
     * Reasons for scoring adjustments
     */
    protected array $reasons = [];

    /**
     * Score the probability that a request is from a human
     */
    public static function score(Request $request): array
    {
        return (new self)->scoreRequest($request);
    }

    /**
     * Classify the score into human/bot determination
     */
    protected static function classifyScore(int $score): string
    {
        return match (true) {
            $score >= self::DEFAULT_THRESHOLDS['likely_human'] => 'likely_human',
            $score >= self::DEFAULT_THRESHOLDS['possibly_human'] => 'possibly_human',
            $score >= self::DEFAULT_THRESHOLDS['likely_bot'] => 'probably_bot',
            default => 'definitely_bot',
        };
    }

    /**
     * Instance method to score the request
     */
    protected function scoreRequest(Request $request): array
    {
        $score = 50; // Start with a neutral score
        $this->reasons = []; // Initialize the reason array

        // 1. User-Agent Analysis
        $score = $this->scoreUserAgent($request, $score);

        // 2. Referrer Analysis
        $score = $this->scoreReferrer($request, $score);

        // 3. Request Headers Analysis
        $score = $this->scoreRequestHeaders($request, $score);

        // 4. Request Frequency Analysis
        $score = $this->scoreRequestFrequency($request, $score);

        // Ensure score is within 0-100 range
        $score = max(0, min(100, $score));

        // Determine classification based on thresholds
        $classification = self::classifyScore($score);

        return [
            'score' => $score,
            'classification' => $classification,
            'reasons' => $this->reasons,
        ];
    }

    /**
     * Score based on user agent characteristics
     */
    protected function scoreUserAgent(Request $request, int $score): int
    {
        $userAgent = $request->userAgent();

        if (! $userAgent) {
            $this->reasons[] = 'Missing user agent';

            return $score - 40;
        }

        // Check length - too short or too long is suspicious
        $length = mb_strlen($userAgent);
        if ($length < 30) {
            $this->reasons[] = 'User agent suspiciously short';
            $score -= self::DEFAULT_WEIGHTS['user_agent_length'];
        } elseif ($length > 500) {
            $this->reasons[] = 'User agent suspiciously long';
            $score -= self::DEFAULT_WEIGHTS['user_agent_length'] / 2;
        } else {
            $this->reasons[] = 'User agent has reasonable length';
            $score += self::DEFAULT_WEIGHTS['user_agent_length'];
        }

        // Check for suspicious patterns (shared with the page-visit middleware)
        foreach (BotSignals::SUSPICIOUS_USER_AGENT_PATTERNS as $pattern) {
            if (mb_stripos($userAgent, $pattern) !== false) {
                $this->reasons[] = "User agent contains suspicious term: {$pattern}";
                $score += self::DEFAULT_WEIGHTS['user_agent_suspicious'];
                break;
            }
        }

        // Check for browser fingerprint structure
        // Real browsers typically have a structure with browser name, version, platform info
        if (preg_match('/(?:Mozilla|AppleWebKit|Chrome|Safari|Firefox|Edge|MSIE|Trident).*(?:Windows NT|Macintosh|Linux|Android|iPhone|iPad).*(?:Chrome|Safari|Firefox|Edge|MSIE)/', $userAgent)) {
            $this->reasons[] = 'User agent has typical browser structure';
            $score += self::DEFAULT_WEIGHTS['user_agent_variety'];
        } else {
            $this->reasons[] = 'User agent lacks typical browser structure';
            $score += self::DEFAULT_WEIGHTS['user_agent_non_standard']; // More severe penalty for non-standard structure
        }

        return $score;
    }

    /**
     * Score based on referrer analysis
     */
    protected function scoreReferrer(Request $request, int $score): int
    {
        $referrer = $request->header('referer');

        if (! $referrer) {
            // No referrer is neutral - could be direct traffic or privacy settings
            return $score;
        }

        // Has some referrer - that's positive
        $this->reasons[] = 'Request includes a referrer';
        $score += self::DEFAULT_WEIGHTS['has_referer'];

        // Check if referrer is valid-looking URL
        if (filter_var($referrer, FILTER_VALIDATE_URL)) {
            $this->reasons[] = 'Referrer is a valid URL';
            $score += self::DEFAULT_WEIGHTS['valid_referer'];
        }

        // Check if the referrer is from search engines or major sites
        $commonReferrers = [
            'google.com', 'bing.com', 'yahoo.com', 'facebook.com',
            'twitter.com', 'instagram.com', 'linkedin.com', 'youtube.com',
        ];

        foreach ($commonReferrers as $domain) {
            if (mb_stripos($referrer, $domain) !== false) {
                $this->reasons[] = "Referrer is from common source ({$domain})";
                $score += 5;
                break;
            }
        }

        return $score;
    }

    /**
     * Score based on request headers
     */
    protected function scoreRequestHeaders(Request $request, int $score): int
    {
        // Check for common headers sent by real browsers
        $browserHeaders = ['accept', 'accept-language', 'accept-encoding'];
        $foundHeaders = 0;

        foreach ($browserHeaders as $header) {
            if ($request->header($header)) {
                $foundHeaders++;
            }
        }

        if ($foundHeaders >= 2) {
            $this->reasons[] = 'Request contains typical browser headers';
            $score += self::DEFAULT_WEIGHTS['request_headers'];
        }

        // Check if the client accepts cookies (most bots don't)
        if ($request->header('cookie')) {
            $this->reasons[] = 'Request includes cookies';
            $score += 10;
        }

        // Check for DNT (Do Not Track) header - real browsers might set this
        if ($request->header('dnt')) {
            $this->reasons[] = 'Request includes DNT header typical of browsers';
            $score += 5;
        }

        // Check for Accept-Language header (almost all browsers send this)
        if (! $request->header('accept-language')) {
            $this->reasons[] = 'Missing Accept-Language header (typical of bots)';
            $score -= 40; // Strong penalty - almost all browsers send this
        }

        // Check for suspicious Accept header values
        $acceptHeader = $request->header('accept');
        if ($acceptHeader === '*/*') {
            $this->reasons[] = 'Generic Accept header (*/*) typical of bots';
            $score -= 15;
        } elseif (empty($acceptHeader)) {
            $this->reasons[] = 'Missing Accept header';
            $score -= 20;
        }

        // Check for Connection header (keep-alive is typical for browsers)
        $connection = $request->header('connection');
        if ($connection && mb_stripos($connection, 'keep-alive') !== false) {
            $this->reasons[] = 'Connection: keep-alive header present';
            $score += 5;
        }

        // Check for Sec-Fetch-* headers (modern browser security feature)
        if ($request->header('sec-fetch-site') || $request->header('sec-fetch-mode')) {
            $this->reasons[] = 'Modern browser security headers present';
            $score += 15;
        }

        // User-Agent Client Hints (Sec-CH-UA) are emitted by modern Chromium
        // browsers and are awkward for simple HTTP clients to forge in a way
        // consistent with the claimed user agent.
        if ($request->header('sec-ch-ua')) {
            $this->reasons[] = 'User-Agent Client Hints (Sec-CH-UA) present';
            $score += 10;
        }

        return $score;
    }

    /**
     * Score based on request frequency: more than 10 earlier requests from
     * the same IP inside one fixed one-minute window, which puts the penalty
     * on the 12th request of a window and every one after it.
     *
     * The window is fixed from its first request. It used to slide: every
     * request rewrote the counter with a fresh one-minute expiry, so the count
     * only reset after a full quiet minute, and an IP that kept coming back at
     * least once a minute took the penalty on every request after its eleventh
     * forever. That pushed browsers with weaker signals (no Sec-Fetch, no
     * cookie on a first view, no Sec-CH-UA outside Chromium) under the
     * `min_human_score` floor, worst on a shared IP where many people add up.
     *
     * `add()` is an atomic put-if-absent and sets the expiry once, when the
     * window opens; `increment()` is atomic and leaves the expiry alone on the
     * stores Laravel ships (array and database update the value only, redis
     * and memcached keep the key's TTL, the file store rewrites the payload
     * with its remaining seconds). That replaces the old get-then-put, which
     * also lost counts to concurrent requests.
     */
    protected function scoreRequestFrequency(Request $request, int $score): int
    {
        // Use the Ranetrace cache store (same as the buffer/pause manager and the
        // page-visit throttle) so the per-IP frequency counter is shared across
        // workers: the host's default cache may be `array`, a per-process no-op.
        $store = Cache::store(config('ranetrace.batch.cache_driver', 'file'));

        $cacheKey = 'ranetrace:request_frequency:'.$request->ip();
        $window = now()->addMinute();

        if ($store->add($cacheKey, 1, $window)) {
            $requestCount = 1;
        } else {
            $requestCount = $store->increment($cacheKey);

            // The window can expire between the add() above and this
            // increment(). Array, file and redis then recreate the key at 1
            // with NO expiry (database answers false instead), and a counter
            // without an expiry would never reset again. A 1 here can only
            // mean that, since a live window already held at least 1, so the
            // window is reopened with its expiry.
            if ($requestCount === false || (int) $requestCount === 1) {
                $store->put($cacheKey, 1, $window);
                $requestCount = 1;
            }
        }

        // Counted including this request, so "more than 10 before it" is
        // "more than 11 with it".
        if ((int) $requestCount > 11) {
            $this->reasons[] = 'High request frequency detected';
            $score += self::DEFAULT_WEIGHTS['request_frequency'];
        }

        return $score;
    }
}
