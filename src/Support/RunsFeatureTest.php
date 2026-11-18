<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Closure;
use Illuminate\Console\Command;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Services\CountingBatchBuffer;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

/**
 * What the feature test commands share: the checks capture makes before it
 * captures anything ({@see Ranetrace::isCaptureEnabled()}), each answered
 * with its own message, the count of what a test's own run buffered, and the
 * closing lines of a test that captured.
 *
 * A command that captured past these checks would say it queued items that
 * capture had dropped. `ranetrace:test` reads the same rule to report a
 * switched-off feature as skipped.
 *
 * @mixin Command
 */
trait RunsFeatureTest
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

        if (! CoreConfig::hasIngestKey()) {
            $this->error('❌ Ranetrace API key is not set!');
            $this->info('💡 Set your API key in the environment that reports to Ranetrace (on a server, in its .env):');
            $this->line('   RANETRACE_KEY=your-api-key-here');
            $this->newLine();

            return Command::FAILURE;
        }

        return null;
    }

    /**
     * Run the capturing steps of a test and count the items of a type they
     * put in the buffer.
     *
     * Null when that cannot be known: the items leave the command for a queue
     * worker or a later moment, or the host has bound a buffer of its own.
     *
     * @param  string  $feature  The feature's key under `ranetrace.`.
     * @param  string  $type  The buffer type the feature's items are stored under.
     * @param  Closure(): void  $capture
     */
    protected function countBuffered(string $feature, string $type, Closure $capture): ?int
    {
        if (! BatchConfig::featureBuffersBeforeReturning("ranetrace.{$feature}") || app()->bound(RanetraceBatchBuffer::class)) {
            $capture();

            return null;
        }

        $buffer = new CountingBatchBuffer;
        app()->instance(RanetraceBatchBuffer::class, $buffer);

        try {
            $capture();
        } finally {
            app()->forgetInstance(RanetraceBatchBuffer::class);
        }

        return $buffer->added($type);
    }

    /**
     * Say where the captured test items are: on the queue, or in the buffer
     * when no queue worker stands between, and that neither is the API. A run
     * that counted its items says how many arrived.
     *
     * @param  string  $feature  The feature's key under `ranetrace.`.
     * @param  string  $items  What was captured, in the plural, such as `test errors`.
     * @param  int|null  $expected  How many items the test captures, or null when its settings decide that.
     * @param  int|null  $buffered  What {@see countBuffered()} counted.
     * @return bool False when the run counted its items and none arrived.
     */
    protected function reportCaptured(string $feature, string $items, ?int $expected, ?int $buffered): bool
    {
        if ($buffered === 0) {
            $this->error("❌ No {$items} were buffered: the capture failed. ".$this->whyNotCaptured());

            return false;
        }

        $uncounted = $expected === null ? ucfirst($items) : "{$expected} {$items}";

        if ($buffered !== null && $expected !== null && $buffered < $expected) {
            $this->warn("⚠ {$buffered} of {$expected} {$items} buffered for Ranetrace. The others were not captured. ".$this->whyNotCaptured());
            $this->info('To send them now, run: php artisan ranetrace:work');
        } elseif ($buffered !== null) {
            $this->info("✅ {$buffered} {$items} buffered for Ranetrace.");
            $this->info('To send them now, run: php artisan ranetrace:work');
        } elseif (BatchConfig::featureWaitsForWorker("ranetrace.{$feature}")) {
            $this->info("✅ {$uncounted} queued for Ranetrace.");
            $this->info('Once a queue worker runs them, they will be sent the next time the ranetrace:work command runs.');
            $this->info('To send them immediately after that, run: php artisan ranetrace:work');
        } else {
            $this->info("✅ {$uncounted} buffered for Ranetrace.");
            $this->info('To send them now, run: php artisan ranetrace:work');
        }

        $this->info('To see whether the API accepts your key, run: php artisan ranetrace:test');

        return true;
    }

    /**
     * {@see reportCaptured()} for a test that captures a single item.
     *
     * @param  string  $feature  The feature's key under `ranetrace.`.
     * @param  string  $item  What was captured, such as `test page visit`.
     * @param  int|null  $buffered  What {@see countBuffered()} counted.
     * @return bool False when the run counted its item and it did not arrive.
     */
    protected function reportCapturedOne(string $feature, string $item, ?int $buffered): bool
    {
        if ($buffered === 0) {
            $this->error("❌ The {$item} was not buffered: the capture failed. ".$this->whyNotCaptured());

            return false;
        }

        if ($buffered === null && BatchConfig::featureWaitsForWorker("ranetrace.{$feature}")) {
            $this->info('✅ '.ucfirst($item).' queued for Ranetrace.');
            $this->info('Once a queue worker runs it, it will be sent the next time the ranetrace:work command runs.');
            $this->info('To send it immediately after that, run: php artisan ranetrace:work');
        } else {
            $this->info('✅ '.ucfirst($item).' buffered for Ranetrace.');
            $this->info('To send it now, run: php artisan ranetrace:work');
        }

        $this->info('To see whether the API accepts your key, run: php artisan ranetrace:test');

        return true;
    }

    /**
     * Where the reason of a failed capture is, as the closing sentence of the
     * line that reports it.
     */
    protected function whyNotCaptured(): string
    {
        $logFile = InternalLogger::todaysFile();

        return $logFile === '' ? 'Turn internal logging on to see why' : "The reason is in {$logFile}";
    }
}
