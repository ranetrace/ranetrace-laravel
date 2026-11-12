<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceTestRun;
use Ranetrace\Laravel\Support\BatchConfig;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Php\Worker\TestResult;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class RanetraceTestCommand extends Command
{
    /**
     * Width of a round trip line's label, as `ranetrace/ranetrace-php`'s
     * test report sets it.
     */
    private const int LABEL_WIDTH = 9;

    /**
     * The features `--feature` and `--all` test, each with its test command
     * and its key under `ranetrace.`.
     *
     * @var array<string, array{command: string, config: string}>
     */
    private const array FEATURES = [
        'errors' => ['command' => 'ranetrace:test-errors', 'config' => 'errors'],
        'events' => ['command' => 'ranetrace:test-events', 'config' => 'events'],
        'logging' => ['command' => 'ranetrace:test-logging', 'config' => 'logging'],
        'javascript_errors' => ['command' => 'ranetrace:test-javascript-errors', 'config' => 'javascript_errors'],
        'analytics' => ['command' => 'ranetrace:test-analytics', 'config' => 'website_analytics'],
    ];

    public $signature = 'ranetrace:test
                        {--feature= : Test specific feature(s) - comma-separated or use "all"}
                        {--all : Test all features}';

    public $description = 'Check the Ranetrace configuration, send a test error and a test event to the API, and optionally test specific features';

    public function handle(): int
    {
        $feature = $this->option('feature');
        $all = $this->option('all');

        // If --all flag or feature=all, test everything
        if ($all || $feature === 'all') {
            return $this->testAllFeatures();
        }

        // If specific feature(s) requested
        if ($feature) {
            // Support comma-separated features
            if (str_contains($feature, ',')) {
                return $this->testMultipleFeatures(explode(',', $feature));
            }

            return $this->testSpecificFeature($feature);
        }

        // Otherwise, run the general configuration validation
        return $this->validateConfiguration();
    }

    protected function testAllFeatures(): int
    {
        $this->info('🧪 Testing all Ranetrace features...');
        $this->newLine();

        return $this->testFeatures(array_keys(self::FEATURES), announceEach: true);
    }

    /**
     * @param  list<string>  $features
     */
    protected function testMultipleFeatures(array $features): int
    {
        $features = array_map('trim', $features);

        $this->info('🧪 Testing multiple features: '.implode(', ', $features));
        $this->newLine();

        return $this->testFeatures($features, announceEach: false);
    }

    /**
     * Run each feature's test command, then print one summary of them.
     *
     * A feature that is switched off, or every feature while the package is,
     * shows as skipped and does not fail the run, by the rule capture itself
     * follows. Any other feature passes or fails by its command's exit code.
     *
     * @param  list<string>  $features
     */
    protected function testFeatures(array $features, bool $announceEach): int
    {
        $results = [];

        foreach ($features as $feature) {
            if ($announceEach) {
                $this->line("Running test for: <fg=cyan>{$feature}</>");
            }

            $exitCode = $this->testSpecificFeature($feature, false);

            $results[$feature] = match (true) {
                isset(self::FEATURES[$feature]) && ! Ranetrace::isSwitchedOn(self::FEATURES[$feature]['config']) => 'skipped',
                $exitCode === self::SUCCESS => 'passed',
                default => 'failed',
            };

            $this->newLine();
        }

        $this->info('📊 Test summary:');
        $this->table(
            ['Feature', 'Result'],
            collect($results)->map(fn (string $result, string $feature): array => [
                ucfirst(str_replace('_', ' ', $feature)),
                match ($result) {
                    'skipped' => '<fg=yellow>○ Skipped: disabled</>',
                    'passed' => '<fg=green>✓ Passed</>',
                    default => '<fg=red>✗ Failed</>',
                },
            ])->values()->toArray()
        );

        return in_array('failed', $results, true) ? self::FAILURE : self::SUCCESS;
    }

    protected function testSpecificFeature(string $feature, bool $showHeader = true): int
    {
        if (! isset(self::FEATURES[$feature])) {
            $this->error("❌ Unknown feature: {$feature}");
            $this->info('💡 Available features: '.implode(', ', array_keys(self::FEATURES)));

            return self::FAILURE;
        }

        if ($showHeader) {
            $this->info("Running feature test: {$feature}");
            $this->newLine();
        }

        return $this->call(self::FEATURES[$feature]['command']);
    }

    protected function validateConfiguration(): int
    {
        $this->info('🔍 Testing Ranetrace configuration...');
        $this->newLine();

        $config = config('ranetrace');

        // Test the structure of the config file
        if (! is_array($config)) {
            $this->error('❌ Ranetrace configuration file is not valid.');

            return self::FAILURE;
        }

        // Check the ingest key. This is the credential that sends captured data
        // to Ranetrace, and every feature validated below depends on it, so a
        // missing one fails the command.
        if (blank($config['key'] ?? null)) {
            $this->error('❌ Ranetrace ingest API key is not set.');
            $this->info('💡 Add to your .env file: RANETRACE_KEY=your-api-key-here');

            return self::FAILURE;
        }

        $this->info('✅ Ingest API key configured: '.mb_substr($config['key'], 0, 4).'******');

        $this->newLine();

        // Check each feature configuration
        $features = [
            'errors' => 'Error reporting',
            'events' => 'Event tracking',
            'website_analytics' => 'Website analytics',
            'javascript_errors' => 'JavaScript errors',
            'logging' => 'Centralized logging',
        ];

        $this->line('📋 <fg=cyan>Feature configuration:</>');
        $rows = [];

        foreach ($features as $key => $name) {
            if (! isset($config[$key])) {
                $this->warn("⚠️  Feature '{$key}' is missing from config");

                continue;
            }

            $feature = $config[$key];
            $enabled = $feature['enabled'] ?? false;
            $queue = $feature['queue'] ?? false;

            $rows[] = [
                $name,
                $enabled ? '✅ Enabled' : '❌ Disabled',
                $queue ? '✅ Queued' : '⚡ Sync',
                BatchConfig::describeFeatureQueue("ranetrace.{$key}"),
            ];

            // Validate structure
            if (! is_bool($enabled)) {
                $this->warn("⚠️  {$name}: 'enabled' should be boolean, got ".gettype($enabled));
            }

            if (! is_bool($queue)) {
                $this->warn("⚠️  {$name}: 'queue' should be boolean, got ".gettype($queue));
            }
        }

        $this->table(['Feature', 'Status', 'Processing', 'Queue'], $rows);
        $this->newLine();

        // Show warnings if features are disabled
        $enabledFeatures = collect($features)->filter(function ($name, $key) use ($config) {
            return $config[$key]['enabled'] ?? false;
        });

        if ($enabledFeatures->isEmpty()) {
            $this->warn('⚠️  All features are currently disabled.');
            $this->info('💡 Enable features in your .env file:');
            $this->line('   RANETRACE_ERRORS_ENABLED=true');
            $this->line('   RANETRACE_EVENTS_ENABLED=true');
            $this->line('   RANETRACE_WEBSITE_ANALYTICS_ENABLED=true');
            $this->line('   RANETRACE_JAVASCRIPT_ERRORS_ENABLED=true');
            $this->line('   RANETRACE_LOGGING_ENABLED=true');
            $this->newLine();
        }

        // Additional config details. Note: per-item size caps (message/trace/file/
        // context) and the 1000-item batch size are fixed internal constants, not
        // config, so only genuinely configurable settings are shown here.
        if (! empty($config['errors'])) {
            $this->line('⚙️  <fg=cyan>Error reporting settings:</>');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Timeout', ($config['errors']['timeout'] ?? 10).' seconds'],
                    ['Queue name', BatchConfig::describeFeatureQueue('ranetrace.errors')],
                    ['Capture user email', ($config['errors']['capture_user_email'] ?? false) ? 'Yes' : 'No'],
                ]
            );
            $this->newLine();
        }

        if (! config('ranetrace.enabled', true)) {
            $this->warn('RANETRACE_ENABLED is false, so nothing is captured or sent.');
            $this->error(TestResult::closingLine(false));

            return self::FAILURE;
        }

        $accepted = $this->sendTestItems();

        $this->info('📚 Test specific features:');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=errors</> - Test error reporting');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=events</> - Test event tracking');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=logging</> - Test logging');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=javascript_errors</> - JavaScript setup');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=analytics</> - Analytics setup');
        $this->newLine();

        $this->info('Test multiple features:');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=errors,events</> - Test multiple');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --all</> - Test all features');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=all</> - Test all features');
        $this->newLine();

        $this->info('Or use the specific commands:');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-errors</>');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-events</>');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-logging</>');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-javascript-errors</>');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-analytics</>');

        return $accepted ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Send the test error and the test event, print the answer per type and
     * the pauses it lifted, and say whether the API accepted either.
     *
     * Nothing here may throw into the command: a failure the run did not turn
     * into a type's line becomes a line of its own, and counts as nothing
     * accepted.
     */
    protected function sendTestItems(): bool
    {
        $this->line('📡 <fg=cyan>Test error and test event, sent to the API:</>');

        $accepted = false;

        try {
            $run = app(RanetraceTestRun::class);
            ['types' => $types, 'lifted' => $lifted] = $run->run();

            foreach ($types as $type => $result) {
                $this->line(self::row($type, $result->line($type, $run->baseUrl(), fromEnvironment: true)));

                $accepted = $accepted || $result->accepted();
            }

            foreach ($lifted as $index => $pause) {
                $this->line(self::row($index === 0 ? 'Lifted' : '', TestResult::liftedPauseLine($pause['scope'], $pause['reason'])));
            }
        } catch (Throwable $e) {
            InternalLogger::error('The test round trip failed', ['exception' => $e->getMessage()]);

            $this->error(OutputFormatter::escape("The test could not run: {$e->getMessage()}"));

            $accepted = false;
        }

        $this->newLine();

        if ($accepted) {
            $this->info(TestResult::closingLine(true));
        } else {
            $this->error(TestResult::closingLine(false));
        }

        $this->line('Run php artisan ranetrace:status to see the buffers, the pauses and the schedule.');
        $this->newLine();

        return $accepted;
    }

    /**
     * A line in the layout `vendor/bin/ranetrace-test` prints: the label
     * padded to nine characters, then the text. The text is escaped, because
     * part of it is what the API answered.
     */
    private static function row(string $label, string $text): string
    {
        return '  '.mb_str_pad($label, self::LABEL_WIDTH).OutputFormatter::escape($text);
    }
}
