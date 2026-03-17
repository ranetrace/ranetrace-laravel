<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RanetraceLogTestCommand extends Command
{
    protected $signature = 'ranetrace:test-logging';

    protected $description = 'Test Ranetrace logging functionality';

    public function handle(): void
    {
        $this->info('Testing Ranetrace Logging...');

        // Check if logging is enabled
        if (! config('ranetrace.logging.enabled', false)) {
            $this->warn('⚠ Ranetrace logging is disabled. Set RANETRACE_LOGGING_ENABLED=true in your .env file');
            $this->info('Configuration check completed.');

            return;
        }

        $this->info('✅ Ranetrace logging is enabled');

        // Check if the ranetrace channel is defined
        if (! config('logging.channels.ranetrace')) {
            $this->warn('⚠ Ranetrace logging channel is not defined in config/logging.php');
            $this->info('Add the ranetrace channel configuration to logging.php channels array:');
            $this->line("'ranetrace' => [");
            $this->line("    'driver' => 'ranetrace',");
            $this->line("    'level' => env('LOG_LEVEL', 'notice'),");
            $this->line('],');
            $this->info('Configuration check completed.');

            return;
        }

        $this->info('✅ Ranetrace logging channel is defined');

        // Test Laravel logging integration
        $this->info('1. Testing Laravel Log integration...');

        Log::channel('ranetrace')->emergency('Test emergency via Laravel Log', [
            'test_context' => 'laravel_log_test',
            'timestamp' => now()->toISOString(),
        ]);
        $this->info('   ✓ Emergency log sent via Laravel Log');

        Log::channel('ranetrace')->error('Test error via Laravel Log', [
            'test_context' => 'laravel_log_test',
            'error_code' => 'TEST_001',
        ]);
        $this->info('   ✓ Error log sent via Laravel Log');

        // Test stack logging if available
        if (config('logging.channels.production') || config('logging.channels.development')) {
            try {
                Log::stack(['ranetrace'])->critical('Test stack logging', [
                    'test_context' => 'stack_test',
                    'component' => 'testing',
                ]);
                $this->info('   ✓ Stack log sent');
            } catch (Exception $e) {
                $this->warn('   ⚠ Stack logging failed: '.$e->getMessage());
            }
        }

        // Test different log levels
        $this->info('2. Testing different log levels...');

        Log::channel('ranetrace')->warning('Test warning via Laravel Log', [
            'test_context' => 'level_test',
            'level' => 'warning',
        ]);
        $this->info('   ✓ Warning log sent');

        Log::channel('ranetrace')->notice('Test notice via Laravel Log', [
            'test_context' => 'level_test',
            'level' => 'notice',
        ]);
        $this->info('   ✓ Notice log sent');

        // Test with context
        $this->info('3. Testing with context data...');
        Log::channel('ranetrace')->error('Error with rich context', [
            'user_id' => 123,
            'order_id' => 'ORD-456',
            'payment_method' => 'stripe',
            'error_code' => 'PAYMENT_FAILED',
            'metadata' => [
                'attempt' => 3,
                'max_attempts' => 5,
            ],
        ]);
        $this->info('   ✓ Context-rich log sent');

        // Test closure serialization fix
        $this->info('4. Testing closure serialization handling...');
        $closure = function () {
            return 'test';
        };
        Log::channel('ranetrace')->error('Error with closure in context', [
            'closure_test' => $closure,
            'nested_data' => [
                'another_closure' => $closure,
                'normal_data' => 'This should work fine',
            ],
        ]);
        $this->info('   ✓ Log with closures sent (closures should be replaced with [Closure])');

        // Test multiple logs (simulating batch)
        $this->info('5. Testing multiple log entries...');
        Log::channel('ranetrace')->error('First error', ['sequence' => 1]);
        Log::channel('ranetrace')->warning('Second warning', ['sequence' => 2]);
        Log::channel('ranetrace')->critical('Third critical', ['sequence' => 3]);
        $this->info('   ✓ Multiple logs sent');

        $this->info('✅ All test logs have been sent to Ranetrace!');
        $this->info('Check your Ranetrace dashboard to see the log entries.');

        $this->newLine();
        $this->info('Recommended Laravel logging configuration:');
        $this->line('Add this to your config/logging.php channels array:');
        $this->line('');
        $this->line("'ranetrace' => [");
        $this->line("    'driver' => 'ranetrace',");
        $this->line("    'level' => env('LOG_LEVEL', 'notice'),");
        $this->line('],');
        $this->line('');
        $this->line("'production' => [");
        $this->line("    'driver' => 'stack',");
        $this->line("    'channels' => array_merge(explode(',', env('LOG_STACK', 'single')), ['ranetrace']),");
        $this->line("    'ignore_exceptions' => false,");
        $this->line('],');
        $this->line('');
        $this->line('Then set LOG_CHANNEL=production in your .env file');

        $this->newLine();
        $this->info('Logging configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Logging Enabled', config('ranetrace.logging.enabled') ? 'Yes' : 'No'],
                ['Queue Enabled', config('ranetrace.logging.queue') ? 'Yes' : 'No'],
                ['Queue Name', config('ranetrace.logging.queue_name')],
                ['Allowed Levels', $this->getFormattedLevels()],
                ['Excluded Channels', implode(', ', config('ranetrace.logging.excluded_channels', []))],
                ['API Key Set', config('ranetrace.key') ? 'Yes' : 'No'],
            ]
        );

        $this->newLine();
        $this->info('Primary usage methods:');
        $this->table(
            ['Method', 'Usage', 'Recommended'],
            [
                ['Log::channel(\'ranetrace\')', 'Direct Ranetrace channel', 'For Ranetrace-only logs'],
                ['Log::stack([\'single\', \'ranetrace\'])', 'Multiple destinations', 'For important logs'],
                ['Log::error() with stack config', 'Automatic dual logging', '✅ Primary method'],
            ]
        );
    }

    private function getFormattedLevels(): string
    {
        $levels = config('ranetrace.logging.levels');

        if (is_string($levels)) {
            return $levels;
        }

        if (is_array($levels)) {
            return implode(', ', $levels);
        }

        return 'error, critical, alert, emergency (default)';
    }
}
