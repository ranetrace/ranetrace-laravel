<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Analytics;

/**
 * Canonical bot / non-browser user-agent signal patterns, shared by the
 * page-visit middleware (hard reject) and the human-probability scorer
 * (score penalty). Matched case-insensitively via `mb_stripos`. Kept in one
 * place so the two consumers cannot drift apart.
 *
 * @see Middleware\TrackPageVisit
 * @see HumanProbabilityScorer
 */
final class BotSignals
{
    /**
     * @var array<int, string>
     */
    public const array SUSPICIOUS_USER_AGENT_PATTERNS = [
        'suspicious', 'fake', 'test', 'localhost', 'postman',
        'curl/', 'wget/', 'python-requests', 'empty', 'unknown',
        'clearly-fake', 'not-a-browser',
        'bot', 'crawler', 'spider', 'scrapy',
        'headless', 'HeadlessChrome', 'Puppeteer', 'Playwright', 'Cypress',
        'phantom', 'selenium', 'webdriver', 'automation', 'scripting',
        'Go-http-client', 'libwww-perl', 'Apache-HttpClient', 'http-client', 'http_request',
        'node-fetch', 'axios/', 'okhttp', 'requests/',
        'java/', 'php/', 'ruby', 'perl/',
    ];
}
