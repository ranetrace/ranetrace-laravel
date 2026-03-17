<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

class RanetraceWorkCommand extends Command
{
    protected $signature = 'ranetrace:work
                            {--type= : Specific type to process (errors, events, logs, page_visits, javascript_errors)}';

    protected $description = 'Process pending Ranetrace batches and send to the API';

    public function handle(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager): int
    {
        // Check global pause first
        if ($pauseManager->isGloballyPaused()) {
            $pauseData = $pauseManager->getGlobalPause();
            $this->warn('Ranetrace is globally paused until '.$pauseData['paused_until'].' (reason: '.$pauseData['reason'].')');

            return self::SUCCESS;
        }

        $specificType = $this->option('type');

        $types = $specificType
            ? [$specificType]
            : ['errors', 'events', 'logs', 'page_visits', 'javascript_errors'];

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
