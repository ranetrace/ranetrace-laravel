<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * A single, self-contained misconfiguration / fault check.
 *
 * Implementations receive the canonical status array (DashboardData::collectStatus())
 * and may read config/cache directly. They must be read-only and must not throw —
 * the runner wraps each call defensively, but a check should still degrade to a
 * sensible result rather than rely on that.
 */
interface Check
{
    /**
     * @param  array<string, mixed>  $status
     */
    public function run(array $status): CheckResult;
}
