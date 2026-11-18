<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

use Ranetrace\Laravel\Support\WorkerSchedule;

/**
 * Buffers holding items with no recent successful batch send. For a feature
 * that is not paused the detail points at `ranetrace:status`, which can read
 * the schedule (see {@see WorkerSchedule}); for a paused one, at the Pauses
 * panel, which shows the pause's reason.
 */
class DrainStalledCheck implements Check
{
    public function run(array $status): CheckResult
    {
        $stalled = $status['drain']['stalled'] ?? [];
        $pausedStalled = $status['drain']['paused_stalled'] ?? [];

        if (empty($stalled) && empty($pausedStalled)) {
            return CheckResult::pass('drain_stalled', 'Buffers are draining normally');
        }

        if (empty($stalled)) {
            return CheckResult::fail(
                'drain_stalled',
                'Paused and not sending: '.implode(', ', $pausedStalled),
                'Buffered items have waited longer than one pause explains. The Pauses panel shows the reason for each feature.'
            );
        }

        $detail = 'Buffered items are not being sent. Run `php artisan ranetrace:status` to see why: it reads your schedule, which this page cannot.';

        if (! empty($pausedStalled)) {
            $detail .= ' Also paused and not sending: '.implode(', ', $pausedStalled).'.';
        }

        return CheckResult::fail(
            'drain_stalled',
            'Drain stalled for: '.implode(', ', $stalled),
            $detail
        );
    }
}
