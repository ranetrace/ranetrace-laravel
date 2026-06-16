<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * Buffers holding items with no recent successful batch send almost always mean
 * the worker isn't running — i.e. `ranetrace:work` isn't scheduled.
 */
class DrainStalledCheck implements Check
{
    public function run(array $status): CheckResult
    {
        $stalled = $status['drain']['stalled'] ?? [];

        if (empty($stalled)) {
            return CheckResult::pass('drain_stalled', 'Buffers are draining normally');
        }

        return CheckResult::fail(
            'drain_stalled',
            'Drain stalled for: '.implode(', ', $stalled),
            'Buffered items are not being sent. Ensure `ranetrace:work` is scheduled to run every minute.'
        );
    }
}
