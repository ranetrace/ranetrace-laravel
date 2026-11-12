<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Closure;
use Illuminate\Console\Command;
use Ranetrace\Laravel\Ranetrace;

/**
 * What the feature test commands share: the checks capture makes before it
 * captures anything ({@see Ranetrace::isCaptureEnabled()}), each answered
 * with its own message, and the closing lines of a test that captured.
 *
 * A command that captured past these checks would say it queued items that
 * capture had dropped. `ranetrace:test` reads the same rule to report a
 * switched-off feature as skipped.
 *
 * @mixin Command
 */
trait GuardsFeatureTest
{
    /**
     * Null when the test may capture, otherwise the exit code the command
     * returns. A switched-off package or feature is a setting, not a failure;
     * a missing key is a failure, because nothing could ever be sent.
     *
     * @param  string  $feature  The feature's key under `ranetrace.`.
     * @param  Closure(): void  $reportDisabled  Prints the command's own message for a feature that is off.
     */
    protected function stopBeforeCapture(string $feature, Closure $reportDisabled): ?int
    {
        if (! config('ranetrace.enabled', true)) {
            $this->warn('⚠ Ranetrace is disabled (RANETRACE_ENABLED is false), so nothing is captured.');

            return Command::SUCCESS;
        }

        if (! Ranetrace::isSwitchedOn($feature)) {
            $reportDisabled();

            return Command::SUCCESS;
        }

        if (blank(config('ranetrace.key'))) {
            $this->error('❌ Ranetrace API key is not set!');
            $this->info('💡 Set your API key in the environment that reports to Ranetrace (on a server, in its .env):');
            $this->line('   RANETRACE_KEY=your-api-key-here');
            $this->newLine();

            return Command::FAILURE;
        }

        return null;
    }

    /**
     * Say where the captured test items are: on the queue, or in the buffer
     * when the feature's queue is off, and that neither is the API.
     *
     * @param  string  $feature  The feature's key under `ranetrace.`.
     * @param  string  $items  What was captured, as the start of a sentence, such as `4 test errors`.
     */
    protected function reportCaptured(string $feature, string $items): void
    {
        if (config("ranetrace.{$feature}.queue", true)) {
            $this->info("✅ {$items} queued for Ranetrace.");
            $this->info('Once a queue worker runs them, they will be sent the next time the ranetrace:work command runs.');
            $this->info('To send them immediately after that, run: php artisan ranetrace:work');
        } else {
            $this->info("✅ {$items} buffered for Ranetrace.");
            $this->info('To send them now, run: php artisan ranetrace:work');
        }

        $this->info('To see whether the API accepts your key, run: php artisan ranetrace:test');
    }
}
