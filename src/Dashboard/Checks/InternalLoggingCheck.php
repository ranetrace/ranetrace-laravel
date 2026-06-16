<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * The internal log is how capture/drain failures become visible. With it off,
 * the installation is flying blind.
 */
class InternalLoggingCheck implements Check
{
    public function run(array $status): CheckResult
    {
        if (config('ranetrace.internal_logging.enabled', true)) {
            return CheckResult::pass('internal_logging', 'Internal logging is enabled');
        }

        return CheckResult::warn(
            'internal_logging',
            'Internal logging is disabled',
            'You will not see capture, drain, or overflow failures. Set RANETRACE_INTERNAL_LOGGING_ENABLED=true.'
        );
    }
}
