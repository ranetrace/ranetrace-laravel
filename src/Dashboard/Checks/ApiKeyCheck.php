<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * Without an API key, capture is silently disabled — the single most common
 * "nothing is arriving" cause. Critical.
 */
class ApiKeyCheck implements Check
{
    public function run(array $status): CheckResult
    {
        $configured = (bool) ($status['config']['api_key_configured'] ?? false);

        if ($configured) {
            return CheckResult::pass('api_key', 'API key is configured');
        }

        return CheckResult::fail(
            'api_key',
            'API key is missing',
            'Set RANETRACE_KEY in .env. Without it, captured data is buffered but never sent.'
        );
    }
}
