<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

use Ranetrace\Laravel\Support\BatchConfig;

/**
 * Non-default queue names need a worker explicitly configured to process them,
 * or jobs pile up unprocessed on a queue nobody is draining.
 */
class QueueWorkerCheck implements Check
{
    /**
     * @var array<int, string>
     */
    protected const array FEATURE_CONFIG_PATHS = [
        'ranetrace.batch',
        'ranetrace.errors',
        'ranetrace.events',
        'ranetrace.logging',
        'ranetrace.javascript_errors',
        'ranetrace.website_analytics',
    ];

    public function run(array $status): CheckResult
    {
        $queues = [];
        foreach (self::FEATURE_CONFIG_PATHS as $path) {
            $name = BatchConfig::featureQueueName($path);
            if ($name !== null && $name !== 'default') {
                $queues[$name] = true;
            }
        }

        if ($queues === []) {
            return CheckResult::pass('queue_worker', 'Using the default queue');
        }

        $names = array_keys($queues);

        return CheckResult::warn(
            'queue_worker',
            'Non-default queue(s): '.implode(', ', $names),
            'Make sure a worker processes these queues, e.g. `queue:work --queue='.implode(',', $names).'`.'
        );
    }
}
