<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Jobs\SendHeartbeatToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Support\BatchConfig;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Php\Http\Heartbeat;
use Throwable;

class RanetraceWorkCommand extends Command
{
    protected $signature = 'ranetrace:work
                            {--type= : Specific type to process (errors, events, logs, page_visits, javascript_errors)}';

    protected $description = 'Process pending Ranetrace batches and send to the API';

    public function handle(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager): int
    {
        $specificType = $this->option('type');

        if ($specificType !== null && ! in_array($specificType, RanetraceBatchBuffer::TYPES, true)) {
            $this->error("Unknown type: {$specificType}");
            $this->line('Valid types: '.implode(', ', RanetraceBatchBuffer::TYPES));

            return self::FAILURE;
        }

        // Pause/buffer state lives in the cache and dispatch hits the queue. If
        // either backend is down, fail cleanly (log + non-zero exit) instead of
        // throwing an uncaught exception on every scheduled run: there is nothing
        // to drain anyway, and ranetrace:status remains the diagnostic.
        try {
            return $this->dispatchBatches($buffer, $pauseManager, $specificType);
        } catch (Throwable $e) {
            InternalLogger::error('ranetrace:work failed to read buffer/pause state or dispatch', [
                'exception' => $e->getMessage(),
            ]);
            $this->error('Ranetrace: ranetrace:work could not run. Is the cache/queue backend available? See the ranetrace_internal log.');

            return self::FAILURE;
        }
    }

    /**
     * Dispatch one batch job per drainable, non-paused feature type.
     */
    protected function dispatchBatches(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager, ?string $specificType): int
    {
        // A pause says nothing to a process that cannot send at all.
        if (! CoreConfig::hasIngestKey()) {
            return $this->reportMissingKey($buffer, $specificType);
        }

        $globalPause = $pauseManager->getGlobalPause();

        if ($pauseManager->isActive($globalPause)) {
            $this->warn('Ranetrace is globally paused until '.$globalPause['paused_until'].' (reason: '.$globalPause['reason'].')');

            return self::SUCCESS;
        }

        $types = $specificType
            ? [$specificType]
            : $buffer->getAvailableTypes();

        $sentCount = 0;

        foreach ($types as $type) {
            $featurePause = $pauseManager->getFeaturePause($type);

            if ($pauseManager->isActive($featurePause)) {
                $this->warn("Feature '{$type}' is paused until ".$featurePause['paused_until'].' (reason: '.$featurePause['reason'].')');

                continue;
            }

            $count = $buffer->count($type);

            if ($count === 0) {
                continue;
            }

            // Dispatch batch job to send items
            SendBatchToRanetraceJob::dispatch($type);

            $this->info("Dispatched batch job for {$type}: {$count} items");
            $sentCount++;
        }

        if ($sentCount === 0) {
            $this->info('No batches to send.');
        } else {
            $this->info("Dispatched {$sentCount} batch job(s).");
        }

        $this->dispatchHeartbeat();

        return self::SUCCESS;
    }

    /**
     * Dispatch at most one heartbeat per interval, whatever the buffers hold,
     * whichever `--type` this run has and however many runs overlap.
     *
     * It never changes the exit code or costs the batches anything. A claim is
     * kept when the dispatch throws, so a broken queue is tried once an
     * interval rather than on every run.
     */
    protected function dispatchHeartbeat(): void
    {
        try {
            if (! config('ranetrace.enabled', true)) {
                return;
            }

            if (! BatchConfig::cacheStore()->add(SendHeartbeatToRanetraceJob::DISPATCH_CLAIM_KEY, now()->timestamp, Heartbeat::INTERVAL_SECONDS)) {
                return;
            }

            SendHeartbeatToRanetraceJob::dispatch();

            $this->info('Dispatched heartbeat job.');
        } catch (Throwable $e) {
            InternalLogger::warning('Heartbeat job could not be dispatched', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Without an ingest key nothing is dispatched (the batch job would take
     * nothing either, see `SendBatchToRanetraceJob::handle()`). The run fails
     * only while items wait. Nothing is captured without a key, so an app that
     * runs the schedule without one, such as a local or staging install, has
     * nothing waiting and stays quiet.
     */
    protected function reportMissingKey(RanetraceBatchBuffer $buffer, ?string $specificType): int
    {
        $types = $specificType !== null ? [$specificType] : RanetraceBatchBuffer::TYPES;
        $waiting = [];

        foreach ($types as $type) {
            $count = $buffer->count($type);

            if ($count > 0) {
                $waiting[] = "{$type} ({$count})";
            }
        }

        if ($waiting === []) {
            $this->info('No batches to send.');

            return self::SUCCESS;
        }

        $this->error('RANETRACE_KEY is not set, so nothing is sent. Waiting in the buffer: '.implode(', ', $waiting).'.');

        return self::FAILURE;
    }
}
