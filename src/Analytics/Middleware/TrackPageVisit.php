<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Analytics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Ranetrace\Laravel\Analytics\Contracts\RequestFilter;
use Ranetrace\Laravel\Analytics\HumanProbabilityScorer;
use Ranetrace\Laravel\Analytics\VisitDataCollector;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Support\InternalLogger;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TrackPageVisit
{
    /**
     * Default bot/crawler user-agent substrings to filter. The middleware
     * matches case-insensitively (`mb_stripos`). Users can add their own via
     * `ranetrace.website_analytics.extra_bot_user_agents` (merged with these
     * defaults at request time).
     *
     * @var array<int, string>
     */
    protected const array DEFAULT_BOT_USER_AGENTS = [
        'SaaSHub',
        'Mozilla/5.0 (compatible; InternetMeasurement/1.0; +https://internet-measurement.com/)',
        'ALittle Client',
        'Applebot',
        'Baiduspider',
        'BingPreview',
        'Bytespider',
        'CCBot',
        'ChatGPT-User',
        'Claude-Web',
        'ClaudeBot',
        'DataForSeoBot',
        'DotBot',
        'Facebot',
        'facebookexternalhit',
        'GPTBot',
        'ia_archiver',
        'ImagesiftBot',
        'LinkedInBot',
        'MJ12bot',
        'PetalBot',
        'Pinterestbot',
        'SemrushBot',
        'Slackbot',
        'Slurp',
        'TelegramBot',
        'Twitterbot',
        'WhatsApp',
        'YandexBot',
        'Amazon CloudFront',
        'HeadlessChrome',
        'Puppeteer',
        'Playwright',
        'PhantomJS',
        'Electron',
        'Cypress',
        'nightwatch',
        'ZoominfoBot',
        'ahrefsbot',
        'DuckDuckBot',
        'Screaming Frog',
        'serpstatbot',
        'MojeekBot',
    ];

    /**
     * Per-worker cache of the CrawlerDetect instance — the library's internal
     * data structures are non-trivial to construct, and the middleware runs on
     * every request. One instance per worker is plenty.
     */
    private static ?CrawlerDetect $crawlerDetect = null;

    public function handle(Request $request, Closure $next): Response
    {
        // The middleware sits in every web request's path. It MUST NEVER throw
        // back into the host application — a failure here would 500 every
        // page. Per the package's Core Rule, capture is wrapped and the
        // request continues regardless.
        try {
            $this->captureVisit($request);
        } catch (Throwable $e) {
            InternalLogger::error('Failed to capture page visit', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }

    private function captureVisit(Request $request): void
    {
        if (! config('ranetrace.enabled', true) || ! config('ranetrace.website_analytics.enabled', false)) {
            return;
        }

        $userAgent = $request->userAgent();
        if (! $userAgent) {
            return;
        }

        if ($request->header('X-Client-Mode') === 'passive') {
            return;
        }

        $excludedPaths = config('ranetrace.website_analytics.excluded_paths', []);
        $firstSegment = explode('/', mb_ltrim($request->path(), '/'))[0];

        if (in_array($firstSegment, $excludedPaths, true)) {
            return;
        }

        $filterClass = config('ranetrace.website_analytics.request_filter');

        if ($filterClass && class_exists($filterClass)) {
            /** @var RequestFilter $filter */
            $filter = app($filterClass);

            if ($filter->shouldSkip($request)) {
                return;
            }
        }

        $minLength = config('ranetrace.website_analytics.user_agent.min_length', 10);
        $maxLength = config('ranetrace.website_analytics.user_agent.max_length', 1000);

        if (mb_strlen($userAgent) < $minLength || mb_strlen($userAgent) > $maxLength) {
            return;
        }

        $suspiciousPatterns = [
            'suspicious', 'fake', 'test', 'localhost', 'postman',
            'curl/', 'wget/', 'python-requests', 'empty',
            'clearly-fake', 'not-a-browser', 'unknown',
            'Go-http-client', 'libwww-perl', 'Apache-HttpClient',
            'node-fetch', 'axios/', 'okhttp', 'java/', 'ruby/',
            'perl/', 'scrapy', 'requests/', 'http_request',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (mb_stripos($userAgent, $pattern) !== false) {
                return;
            }
        }

        // CrawlerDetect is cached per-worker — its internal data structures
        // are non-trivial to construct and this runs on every request.
        self::$crawlerDetect ??= new CrawlerDetect;
        if (self::$crawlerDetect->isCrawler($userAgent)) {
            return;
        }

        $extraBots = array_merge(
            self::DEFAULT_BOT_USER_AGENTS,
            (array) config('ranetrace.website_analytics.extra_bot_user_agents', [])
        );

        foreach ($extraBots as $botUserAgent) {
            if (mb_stripos($userAgent, $botUserAgent) !== false) {
                return;
            }
        }

        $humanScore = HumanProbabilityScorer::score($request);

        if (in_array($humanScore['classification'], ['definitely_bot', 'probably_bot'], true)) {
            return;
        }

        // Real browsers almost always send Accept-Language; bots often
        // send "*/*" or empty Accept headers.
        if (! $request->header('Accept-Language')) {
            return;
        }

        $acceptHeader = $request->header('Accept');
        if (! $acceptHeader || $acceptHeader === '*/*') {
            return;
        }

        $visitData = VisitDataCollector::collect($request);
        $visitData['human_probability_score'] = $humanScore['score'];
        $visitData['human_probability_reasons'] = $humanScore['reasons'];

        // Throttle key buckets by IP + path + minute.
        $cacheKey = 'ranetrace:visit:'.md5(
            $request->ip().'|'.
            $visitData['path'].'|'.
            now()->format('Y-m-d-H-i')
        );

        $throttleSeconds = config('ranetrace.website_analytics.throttle_seconds', 30);

        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, true, now()->addSeconds($throttleSeconds));

            if (config('ranetrace.website_analytics.queue', true)) {
                HandlePageVisitJob::dispatch($visitData);
            } else {
                HandlePageVisitJob::dispatchSync($visitData);
            }
        }
    }
}
