<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Support\Core;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Php\JavaScript\ErrorItemBuilder;

class RanetraceJavaScriptErrorTestCommand extends Command
{
    /**
     * A real desktop Chrome user agent, so the test item carries the browser
     * `name` and `version` a real report derives from it.
     */
    private const string TEST_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    protected $signature = 'ranetrace:test-javascript-errors';

    protected $description = 'Display JavaScript error tracking configuration and usage instructions';

    public function handle(): int
    {
        $this->info('🔍 Ranetrace JavaScript Error Tracking Test');
        $this->newLine();

        // Display current configuration
        $this->line('📋 <fg=cyan>Current Configuration:</>');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', config('ranetrace.javascript_errors.enabled') ? '✅ Yes' : '❌ No'],
                ['Sample Rate', config('ranetrace.javascript_errors.sample_rate', 1.0) * 100 .'%'],
                ['Queue Enabled', config('ranetrace.javascript_errors.queue') ? '✅ Yes' : '❌ No'],
                ['Queue Name', config('ranetrace.javascript_errors.queue_name', 'default')],
                ['Max Breadcrumbs', config('ranetrace.javascript_errors.max_breadcrumbs', 20)],
                ['Capture Console Errors', config('ranetrace.javascript_errors.capture_console_errors') ? '✅ Yes' : '❌ No'],
                ['Ignored Errors', count(config('ranetrace.javascript_errors.ignored_errors', [])).' pattern(s)'],
            ]
        );

        $this->newLine();

        if (! config('ranetrace.javascript_errors.enabled')) {
            $this->warn('⚠️  JavaScript error tracking is currently disabled.');
            $this->info('💡 To enable it, add to your .env file:');
            $this->line('   RANETRACE_JAVASCRIPT_ERRORS_ENABLED=true');
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

        $this->info('✅ JavaScript error tracking is enabled and configured!');
        $this->newLine();

        // Dispatch a synthetic JavaScript error so the user can verify the
        // job → buffer → API path end-to-end without a browser. Mirrors the
        // behavior of ranetrace:test-errors, test-events, and test-logging.
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

        if (config('ranetrace.javascript_errors.queue', true)) {
            HandleJavaScriptErrorJob::dispatch($errorData);
            $this->info('✅ Test JavaScript error queued for Ranetrace.');
            $this->info('Once a queue worker runs it, it will be sent the next time the ranetrace:work command runs.');
            $this->info('To send it immediately after that, run: php artisan ranetrace:work');
        } else {
            HandleJavaScriptErrorJob::dispatchSync($errorData);
            $this->info('✅ Test JavaScript error buffered for Ranetrace.');
            $this->info('To send it now, run: php artisan ranetrace:work');
        }
        $this->newLine();

        // Display usage instructions
        $this->line('📖 <fg=cyan>Usage Instructions:</>');
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
