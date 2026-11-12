<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * Buffers holding items with no recent successful batch send. For a feature
 * that is not paused this almost always means the worker isn't running, and
 * why is for `ranetrace:status` to say: the schedule exists only in a console
 * process, so this page cannot read it. For a paused feature whose pause no
 * longer explains the wait, the cause is the pause, whose reason the Pauses
 * panel shows. When both kinds wait, the stalled ones lead and the paused ones
 * follow in the detail.
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
