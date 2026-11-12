<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * Buffers holding items with no recent successful batch send almost always mean
 * the worker isn't running. Why is for `ranetrace:status` to say: the schedule
 * exists only in a console process, so this page cannot read it.
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
            'Buffered items are not being sent. Run `php artisan ranetrace:status` to see why: it reads your schedule, which this page cannot.'
        );
    }
}
