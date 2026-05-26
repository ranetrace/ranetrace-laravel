<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Exception;
use Illuminate\Console\Command;
use Ranetrace\Laravel\Facades\Ranetrace;
use RuntimeException;

class RanetraceErrorTestCommand extends Command
{
    protected $signature = 'ranetrace:test-errors';

    protected $description = 'Test Ranetrace error reporting functionality';

    private const int TEST_COUNT = 4;

    public function handle(): void
    {
        $this->info('Testing Ranetrace Error Reporting...');

        // Check if error reporting is enabled
        if (! config('ranetrace.errors.enabled', true)) {
            $this->warn('⚠ Ranetrace error reporting is disabled. Set RANETRACE_ERRORS_ENABLED=true in your .env file');
            $this->info('Configuration check completed.');

            return;
        }

        $this->info('✅ Ranetrace error reporting is enabled');
        $this->newLine();

        // Test 1: Simple Exception
        $this->info('1. Testing simple exception...');
        try {
            throw new Exception('Test exception from Ranetrace');
        } catch (Exception $e) {
            Ranetrace::report($e);
            $this->info('   ✓ Simple exception reported');
        }

        // Test 2: RuntimeException
        $this->info('2. Testing RuntimeException...');
        try {
            throw new RuntimeException('Test runtime exception', 500);
        } catch (RuntimeException $e) {
            Ranetrace::report($e);
            $this->info('   ✓ RuntimeException reported');
        }

        // Test 3: Exception with context (simulated by adding more stack depth)
        $this->info('3. Testing exception with deeper stack trace...');
        try {
            $this->simulateDeepError();
        } catch (Exception $e) {
            Ranetrace::report($e);
            $this->info('   ✓ Exception with stack trace reported');
        }

        // Test 4: Custom exception with context
        $this->info('4. Testing exception with custom message...');
        try {
            throw new Exception('Database connection failed: Connection refused on localhost:5432');
        } catch (Exception $e) {
            Ranetrace::report($e);
            $this->info('   ✓ Custom exception reported');
        }

        $this->newLine();
        if (config('ranetrace.errors.queue', true)) {
            $this->info('✅ '.self::TEST_COUNT.' test errors have been queued for Ranetrace.');
            $this->info('They will be sent the next time the ranetrace:work command runs.');
            $this->info('To send them immediately, run: php artisan ranetrace:work');
        } else {
            $this->info('✅ '.self::TEST_COUNT.' test errors have been sent to Ranetrace.');
        }
        $this->newLine();
        $this->info('Check your Ranetrace dashboard once the errors have been sent.');

        $this->newLine();
        $this->info('What gets reported:');
        $this->table(
            ['Data Point', 'Description'],
            [
                ['Exception Message', 'The error message'],
                ['Exception Type', 'The exception class name'],
                ['File & Line', 'Where the error occurred'],
                ['Stack Trace', 'Full execution path (truncated if too long)'],
                ['Code Context', '11 lines around the error (5 before, error, 5 after)'],
                ['Request Info', 'URL, method, headers (sensitive data masked)'],
                ['User Info', 'Authenticated user ID and email (if available)'],
                ['Environment', 'Application environment (production, local, etc.)'],
                ['Versions', 'PHP and Laravel versions'],
            ]
        );

        $this->newLine();
        $this->info('Error reporting configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Errors Enabled', config('ranetrace.errors.enabled') ? 'Yes' : 'No'],
                ['Queue Enabled', config('ranetrace.errors.queue') ? 'Yes' : 'No'],
                ['Queue Name', config('ranetrace.errors.queue_name')],
                ['Timeout', config('ranetrace.errors.timeout').' seconds'],
                ['API Key Set', config('ranetrace.key') ? 'Yes' : 'No'],
            ]
        );

        $this->newLine();
        $this->info('Privacy & Security:');
        $this->table(
            ['Item', 'How It\'s Handled'],
            [
                ['Request Headers', 'Only an allowlist of safe headers (accept, user-agent, referer, host, ...) is sent; every other header is masked with ***'],
                ['Code Context', 'Only included if file is readable and under size limit'],
                ['Stack Trace', 'Truncated if exceeds max length'],
                ['User Data', 'Only ID and email (no passwords or sensitive data)'],
            ]
        );

        $this->newLine();
        $this->info('Usage in your code:');
        $this->line('<fg=yellow>try {');
        $this->line('    // Your code here');
        $this->line('} catch (Exception $e) {');
        $this->line('    Ranetrace::report($e);');
        $this->line('    throw $e; // Re-throw if needed');
        $this->line('}</>');
        $this->newLine();

        $this->line('<fg=green>For automatic capture of all unhandled exceptions (recommended):</>');
        $this->line('Add to <fg=yellow>bootstrap/app.php</> in the <fg=yellow>->withExceptions()</> block:');
        $this->newLine();
        $this->line(<<<'WIRING'
            <fg=yellow>use Ranetrace\Laravel\Facades\Ranetrace;
            use Illuminate\Foundation\Configuration\Exceptions;

            ->withExceptions(function (Exceptions $exceptions) {
                Ranetrace::handles($exceptions);
            })</>
            WIRING);
        $this->newLine();
        $this->warn('Without this wiring, the package will NOT auto-capture unhandled exceptions.');
    }

    /**
     * Simulate a deeper call stack for testing.
     */
    protected function simulateDeepError(): void
    {
        $this->levelOne();
    }

    protected function levelOne(): void
    {
        $this->levelTwo();
    }

    protected function levelTwo(): void
    {
        $this->levelThree();
    }

    protected function levelThree(): void
    {
        throw new Exception('Test exception with deeper stack trace');
    }
}
