<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Support\InternalLogger;
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
        // throwing an uncaught exception on every scheduled run — there is nothing
        // to drain anyway, and ranetrace:status remains the diagnostic.
        try {
            return $this->dispatchBatches($buffer, $pauseManager, $specificType);
        } catch (Throwable $e) {
            InternalLogger::error('ranetrace:work failed to read buffer/pause state or dispatch', [
                'exception' => $e->getMessage(),
            ]);
            $this->error('Ranetrace: ranetrace:work could not run — is the cache/queue backend available? See the ranetrace_internal log.');

            return self::FAILURE;
        }
    }

    /**
     * Dispatch one batch job per drainable, non-paused feature type.
     */
    protected function dispatchBatches(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager, ?string $specificType): int
    {
        // Check global pause first
        if ($pauseManager->isGloballyPaused()) {
            $pauseData = $pauseManager->getGlobalPause();
            $this->warn('Ranetrace is globally paused until '.$pauseData['paused_until'].' (reason: '.$pauseData['reason'].')');

            return self::SUCCESS;
        }

        $types = $specificType
            ? [$specificType]
            : $buffer->getAvailableTypes();

        $sentCount = 0;

        foreach ($types as $type) {
            // Check feature-specific pause
            if ($pauseManager->isFeaturePaused($type)) {
                $pauseData = $pauseManager->getFeaturePause($type);
                $this->warn("Feature '{$type}' is paused until ".$pauseData['paused_until'].' (reason: '.$pauseData['reason'].')');

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

        return self::SUCCESS;
    }
}
