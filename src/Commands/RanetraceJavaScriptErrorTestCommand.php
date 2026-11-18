<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Ranetrace\Laravel\Commands\Concerns\RunsFeatureTest;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Support\BatchConfig;
use Ranetrace\Laravel\Support\Core;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Php\JavaScript\ErrorItemBuilder;

class RanetraceJavaScriptErrorTestCommand extends Command
{
    use RunsFeatureTest;

    /**
     * A real desktop Chrome user agent, so the test item carries the browser
     * `name` and `version` a real report derives from it.
     */
    private const string TEST_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    protected $signature = 'ranetrace:test-javascript-errors';

    protected $description = 'Display JavaScript error tracking configuration and usage instructions';

    public function handle(): int
    {
        $this->info('🔍 Ranetrace JavaScript error tracking test');
        $this->newLine();

        // Display current configuration
        $this->line('📋 <fg=cyan>Current configuration:</>');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', config('ranetrace.javascript_errors.enabled') ? '✅ Yes' : '❌ No'],
                ['Sample rate', config('ranetrace.javascript_errors.sample_rate', 1.0) * 100 .'%'],
                ['Queue enabled', config('ranetrace.javascript_errors.queue', true) ? '✅ Yes' : '❌ No'],
                ['Queue name', BatchConfig::describeFeatureQueue('ranetrace.javascript_errors')],
                ['Max breadcrumbs', config('ranetrace.javascript_errors.max_breadcrumbs', 20)],
                ['Capture console errors', config('ranetrace.javascript_errors.capture_console_errors') ? '✅ Yes' : '❌ No'],
                ['Ignored errors', count(config('ranetrace.javascript_errors.ignored_errors', [])).' pattern(s)'],
            ]
        );

        $this->newLine();

        $stop = $this->stopBeforeCapture('javascript_errors', function (): void {
            $this->warn('⚠️  JavaScript error tracking is currently disabled.');
            $this->info('💡 To enable it, set in the environment that reports to Ranetrace (on a server, in its .env):');
            $this->line('   RANETRACE_JAVASCRIPT_ERRORS_ENABLED=true');
            $this->newLine();
        });

        if ($stop !== null) {
            return $stop;
        }

        $this->info('✅ JavaScript error tracking is enabled and configured!');
        $this->newLine();

        // A synthetic JavaScript error, built by the same builder the browser
        // endpoint uses, so the test item has a real item's shape.
        $errorData = (new ErrorItemBuilder(CoreConfig::make(), Core::scrubber()))->build(
            payload: [
                'message' => 'Test JavaScript error from ranetrace:test-javascript-errors',
                'stack' => "TestError: Test JavaScript error from ranetrace:test-javascript-errors\n    at ranetrace:test-javascript-errors (artisan)",
                'type' => 'TestError',
                'filename' => 'artisan',
                'line' => 0,
                'column' => 0,
                'url' => 'cli://ranetrace:test-javascript-errors',
                'breadcrumbs' => [],
                'context' => ['source' => 'cli-test'],
            ],
            userAgent: self::TEST_USER_AGENT,
            userId: null,
            sessionId: null,
            sensitivePathValues: null,
            // Carbon rather than the builder's own clock, so a frozen clock is honored.
            timestampFallback: now()->format('c'),
        );

        $buffered = $this->countBuffered('javascript_errors', 'javascript_errors', function () use ($errorData): void {
            config('ranetrace.javascript_errors.queue', true)
                ? HandleJavaScriptErrorJob::dispatch($errorData)
                : HandleJavaScriptErrorJob::dispatchSync($errorData);
        });

        if (! $this->reportCapturedOne('javascript_errors', 'test JavaScript error', $buffered)) {
            return self::FAILURE;
        }

        $this->newLine();

        // Display usage instructions
        $this->line('📖 <fg=cyan>Usage instructions:</>');
        $this->newLine();

        $this->line('<fg=green>Step 1:</> Add the tracking script to your layout');
        $this->line('Add this directive to your main layout file (e.g., <fg=yellow>resources/views/layouts/app.blade.php</>):');
        $this->newLine();
        $this->line('<fg=white><!DOCTYPE html>');
        $this->line('<html>');
        $this->line('  <head>');
        $this->line('    <title>My App</title>');
        $this->line('    ...');
        $this->line('  </head>');
        $this->line('  <body>');
        $this->line('    @yield(\'content\')');
        $this->line('    ');
        $this->line('    <fg=cyan>@ranetraceErrorTracking</>');
        $this->line('  </body>');
        $this->line('</html></>');
        $this->newLine(2);

        $this->line('<fg=green>Step 2:</> Test error tracking in your browser');
        $this->line('Open your browser console and run:');
        $this->newLine();
        $this->line('<fg=yellow>throw new Error("Test error from Ranetrace");</>');
        $this->newLine(2);

        $this->line('<fg=green>Step 3:</> Use the manual API (optional)');
        $this->line('You can manually capture errors or add breadcrumbs:');
        $this->newLine();
        $this->line('<fg=yellow>// Capture a custom error');
        $this->line('try {');
        $this->line('  // Your code here');
        $this->line('} catch (error) {');
        $this->line('  window.Ranetrace.captureError(error, {');
        $this->line('    custom_field: "value"');
        $this->line('  });');
        $this->line('}');
        $this->newLine();
        $this->line('// Add a breadcrumb for debugging context');
        $this->line('window.Ranetrace.addBreadcrumb("user", "Button clicked", {');
        $this->line('  button_id: "submit-form"');
        $this->line('});</>');
        $this->newLine(2);

        $this->line('🎉 <fg=green>Features included:</>');
        $this->line('   ✅ Automatic error capture (window.onerror)');
        $this->line('   ✅ Unhandled promise rejection capture');
        $this->line('   ✅ Breadcrumbs (clicks, form submissions, HTTP requests)');
        $this->line('   ✅ Browser info (screen size, memory, connection type)');
        $this->line('   ✅ Error deduplication');
        $this->line('   ✅ Stack traces');
        $this->line('   ✅ User and session tracking');
        $this->newLine();

        $this->info('📚 For more information, visit: https://ranetrace.com/docs');
        $this->newLine();

        return self::SUCCESS;
    }
}
