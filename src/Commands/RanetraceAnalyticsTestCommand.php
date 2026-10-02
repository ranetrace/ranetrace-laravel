<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Ranetrace\Laravel\Analytics\HumanProbabilityScorer;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;

class RanetraceAnalyticsTestCommand extends Command
{
    private const string TEST_PATH = '/ranetrace-test-analytics';

    /**
     * The headers desktop Chrome sends when a page is typed into the address
     * bar. The Sec-Fetch and client hint headers among them are what lift the
     * scorer's answer over `min_human_score`, the floor the middleware drops
     * a visit under.
     *
     * @var array<string, string>
     */
    private const array DESKTOP_CHROME_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Accept-Encoding' => 'gzip, deflate, br, zstd',
        'Connection' => 'keep-alive',
        'Upgrade-Insecure-Requests' => '1',
        'Sec-Fetch-Site' => 'none',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-User' => '?1',
        'Sec-Fetch-Dest' => 'document',
        'Sec-CH-UA' => '"Chromium";v="140", "Not=A?Brand";v="24", "Google Chrome";v="140"',
        'Sec-CH-UA-Mobile' => '?0',
        'Sec-CH-UA-Platform' => '"macOS"',
    ];

    protected $signature = 'ranetrace:test-analytics';

    protected $description = 'Display website analytics configuration and usage instructions';

    public function handle(): int
    {
        $this->info('🔍 Ranetrace Website Analytics Test');
        $this->newLine();

        // Display current configuration
        $this->line('📋 <fg=cyan>Current Configuration:</>');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', config('ranetrace.website_analytics.enabled') ? '✅ Yes' : '❌ No'],
                ['Queue Enabled', config('ranetrace.website_analytics.queue') ? '✅ Yes' : '❌ No'],
                ['Queue Name', config('ranetrace.website_analytics.queue_name', 'default')],
                ['Timeout', config('ranetrace.website_analytics.timeout', 10).' seconds'],
                ['Throttle', config('ranetrace.website_analytics.throttle_seconds', 30).' seconds'],
                ['User Agent Min Length', config('ranetrace.website_analytics.user_agent.min_length', 10)],
                ['User Agent Max Length', config('ranetrace.website_analytics.user_agent.max_length', 1000)],
                ['Excluded Paths', count(config('ranetrace.website_analytics.excluded_paths', [])).' path(s)'],
            ]
        );

        $this->newLine();

        if (! config('ranetrace.website_analytics.enabled')) {
            $this->warn('⚠️  Website analytics is currently disabled.');
            $this->info('💡 To enable it, add to your .env file:');
            $this->line('   RANETRACE_WEBSITE_ANALYTICS_ENABLED=true');
            $this->newLine();

            return self::SUCCESS;
        }

        if (empty(config('ranetrace.key'))) {
            $this->error('❌ Ranetrace API key is not set!');
            $this->info('💡 Add your API key to .env:');
            $this->line('   RANETRACE_KEY=your-api-key-here');
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('✅ Website analytics is enabled and configured!');
        $this->newLine();

        // A synthetic browser request taken through the middleware's own
        // build step, so the test visit carries every key a real one does and
        // names the browser the way a real one is named.
        $request = $this->desktopChromeNavigation();

        $visitData = TrackPageVisit::buildVisitData($request, HumanProbabilityScorer::score($request));

        if (config('ranetrace.website_analytics.queue', true)) {
            HandlePageVisitJob::dispatch($visitData);
            $this->info('✅ Test page visit queued for Ranetrace. Once a queue worker runs it, run php artisan ranetrace:work to send it.');
        } else {
            HandlePageVisitJob::dispatchSync($visitData);
            $this->info('✅ Test page visit buffered for Ranetrace. Run php artisan ranetrace:work to send it.');
        }
        $this->newLine();

        // How it works
        $this->line('🚀 <fg=cyan>How It Works:</>');
        $this->newLine();
        $this->line('Analytics are automatically tracked via middleware when enabled.');
        $this->line('The <fg=yellow>TrackPageVisit</> middleware is added to the <fg=cyan>web</> middleware group.');
        $this->newLine();

        // What gets tracked
        $this->line('📊 <fg=cyan>What Gets Tracked:</>');
        $this->table(
            ['Data Point', 'Description'],
            [
                ['URL & Path', 'Full URL (sensitive query params redacted) and path'],
                ['Timestamp', 'When the visit occurred (ISO 8601)'],
                ['Referrer', 'Where the visitor came from'],
                ['Device Type', 'mobile, tablet, desktop, or console'],
                ['Browser', 'Chrome, Firefox, Safari, Edge, etc.'],
                ['UTM Parameters', 'source, medium, campaign, content, term'],
                ['Session ID (hashed)', 'HMAC-SHA256 (salted) for privacy'],
                ['User Agent (hashed)', 'HMAC-SHA256 (salted) for privacy'],
                ['Human Probability', 'Bot detection score (0-100, integer)'],
            ]
        );

        $this->newLine();

        // Bot detection
        $this->line('🤖 <fg=cyan>Bot Detection & Filtering:</>');
        $this->table(
            ['Detection Method', 'Description'],
            [
                ['User Agent Length', 'Filters too short (<10) or too long (>1000) UAs'],
                ['Suspicious Patterns', 'Filters "test", "curl", "wget", "bot", etc.'],
                ['CrawlerDetect Library', 'Detects 40+ known bots and crawlers'],
                ['Extra Bot List', 'GoogleBot, ChatGPT, ClaudeBot, Puppeteer, etc.'],
                ['Header Validation', 'Requires Accept-Language, validates Accept header'],
                ['Human Probability', 'Requests scored as "bot" are excluded'],
            ]
        );

        $this->newLine();

        // Excluded paths
        $excludedPaths = config('ranetrace.website_analytics.excluded_paths', []);
        if (! empty($excludedPaths)) {
            $this->line('🚫 <fg=cyan>Excluded Paths (visits to these are NOT tracked):</>');
            foreach ($excludedPaths as $path) {
                $this->line('   • /'.$path.'/*');
            }
            $this->newLine();
        }

        // Testing
        $this->line('🧪 <fg=cyan>Testing Analytics:</>');
        $this->newLine();
        $this->line('1. Visit your website with a regular browser');
        $this->line('2. Navigate through different pages');
        $this->line('3. Check your Ranetrace dashboard to see the visits');
        $this->newLine();

        $this->line('<fg=yellow>Note:</> Analytics are tracked automatically, no code changes needed!');
        $this->newLine();

        // Privacy
        $this->line('🔒 <fg=cyan>Privacy Features:</>');
        $this->table(
            ['Item', 'How It\'s Handled'],
            [
                ['IP Address', 'NOT sent to Ranetrace (privacy-first)'],
                ['User Agent', 'Hashed with HMAC-SHA256, salted (not stored raw)'],
                ['Session ID', 'HMAC-SHA256 of IP + UA + Date (daily rotation, salted)'],
                ['Personal Data', 'No personal information is collected'],
            ]
        );

        $this->newLine();

        // Configuration options
        $this->line('⚙️  <fg=cyan>Configuration Options:</>');
        $this->newLine();
        $this->line('Add to <fg=yellow>.env</> file:');
        $this->line('');
        $this->line('<fg=yellow># Enable/disable analytics');
        $this->line('RANETRACE_WEBSITE_ANALYTICS_ENABLED=true');
        $this->line('');
        $this->line('# Queue visits (recommended for performance)');
        $this->line('RANETRACE_WEBSITE_ANALYTICS_QUEUE=true');
        $this->line('');
        $this->line('# Throttle duplicate visits (seconds)');
        $this->line('RANETRACE_WEBSITE_ANALYTICS_THROTTLE_SECONDS=30');
        $this->line('');
        $this->line('# User agent validation');
        $this->line('RANETRACE_WEBSITE_ANALYTICS_UA_MIN_LENGTH=10');
        $this->line('RANETRACE_WEBSITE_ANALYTICS_UA_MAX_LENGTH=1000</>');
        $this->newLine();

        $this->line('Add to <fg=yellow>config/ranetrace.php</>:');
        $this->line('');
        $this->line("<fg=yellow>'website_analytics' => [");
        $this->line("    'excluded_paths' => [");
        $this->line("        'admin',      // Don't track /admin/*");
        $this->line("        'api',        // Don't track /api/*");
        $this->line("        'telescope',  // Don't track /telescope/*");
        $this->line('    ],');
        $this->line(']</>');

        return self::SUCCESS;
    }

    /**
     * Only the scheme, host and port of `app.url` are used. An `app.url` that
     * names no host (empty, or written without a scheme) leaves the request on
     * the framework's default `http://localhost` rather than turning the host
     * into part of the path.
     */
    private function desktopChromeNavigation(): Request
    {
        $appUrl = parse_url((string) config('app.url'));
        $origin = '';

        if (is_array($appUrl) && isset($appUrl['host'])) {
            $origin = ($appUrl['scheme'] ?? 'http').'://'.$appUrl['host'];

            if (isset($appUrl['port'])) {
                $origin .= ':'.$appUrl['port'];
            }
        }

        $request = Request::create($origin.self::TEST_PATH);

        foreach (self::DESKTOP_CHROME_HEADERS as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
