<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ranetrace\Laravel\Support\BatchConfig;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Laravel\Support\GuardsFeatureTest;
use Throwable;

class RanetraceLogTestCommand extends Command
{
    use GuardsFeatureTest;

    protected $signature = 'ranetrace:test-logging';

    protected $description = 'Test Ranetrace logging functionality';

    public function handle(): int
    {
        $this->info('Testing Ranetrace logging...');

        $stop = $this->stopBeforeCapture('logging', function (): void {
            $this->warn('⚠ Ranetrace logging is disabled. Set RANETRACE_LOGGING_ENABLED=true in your .env file');
            $this->info('Configuration check completed.');
        });

        if ($stop !== null) {
            return $stop;
        }

        $this->info('✅ Ranetrace logging is enabled');
        $this->info('✅ Ranetrace logging channel is auto-registered by the service provider');

        $buffered = $this->countBuffered('logging', 'logs', function (): void {
            // Test Laravel logging integration
            $this->info('1. Testing Laravel Log integration...');

            Log::channel('ranetrace')->emergency('Test emergency via Laravel Log', [
                'test_context' => 'laravel_log_test',
                'timestamp' => now()->toISOString(),
            ]);
            $this->info('   ✓ Emergency log written via Laravel Log');

            Log::channel('ranetrace')->error('Test error via Laravel Log', [
                'test_context' => 'laravel_log_test',
                'error_code' => 'TEST_001',
            ]);
            $this->info('   ✓ Error log written via Laravel Log');

            // Test stack logging if available
            if (config('logging.channels.production') || config('logging.channels.development')) {
                try {
                    Log::stack(['ranetrace'])->critical('Test stack logging', [
                        'test_context' => 'stack_test',
                        'component' => 'testing',
                    ]);
                    $this->info('   ✓ Stack log written');
                } catch (Throwable $e) {
                    $this->warn('   ⚠ Stack logging failed: '.$e->getMessage());
                }
            }

            // Test different log levels
            $this->info('2. Testing different log levels...');

            Log::channel('ranetrace')->warning('Test warning via Laravel Log', [
                'test_context' => 'level_test',
                'level' => 'warning',
            ]);
            $this->info('   ✓ Warning log written');

            Log::channel('ranetrace')->notice('Test notice via Laravel Log', [
                'test_context' => 'level_test',
                'level' => 'notice',
            ]);
            $this->info('   ✓ Notice log written');

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
            $this->info('   ✓ Context-rich log written');

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
            $this->info('   ✓ Log with closures written (closures should be replaced with [Closure])');

            // Test multiple logs (simulating batch)
            $this->info('5. Testing multiple log entries...');
            Log::channel('ranetrace')->error('First error', ['sequence' => 1]);
            Log::channel('ranetrace')->warning('Second warning', ['sequence' => 2]);
            Log::channel('ranetrace')->critical('Third critical', ['sequence' => 3]);
            $this->info('   ✓ Multiple logs written');
        });

        $this->newLine();

        if (! $this->reportCaptured('logging', 'test logs', null, $buffered)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Check your Ranetrace dashboard once the logs have been sent.');

        $this->newLine();
        $this->info('Add the channel to your log stack to capture all application logs:');
        $this->line("'stack' => [");
        $this->line("    'driver' => 'stack',");
        $this->line("    'channels' => [...explode(',', (string) env('LOG_STACK', 'single')), 'ranetrace'],");
        $this->line("    'ignore_exceptions' => false,");
        $this->line('],');

        $this->newLine();
        $this->info('Logging configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Logging enabled', config('ranetrace.logging.enabled') ? 'Yes' : 'No'],
                ['Queue enabled', config('ranetrace.logging.queue') ? 'Yes' : 'No'],
                ['Queue name', BatchConfig::describeFeatureQueue('ranetrace.logging')],
                ['Minimum level', config('ranetrace.logging.level', 'notice')],
                ['Excluded channels', implode(', ', config('ranetrace.logging.excluded_channels', [])) ?: '(none)'],
                ['API key set', CoreConfig::hasIngestKey() ? 'Yes' : 'No'],
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

        return self::SUCCESS;
    }
}
