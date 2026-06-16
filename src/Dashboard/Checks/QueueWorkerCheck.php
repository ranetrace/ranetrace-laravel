<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * Non-default queue names need a worker explicitly configured to process them,
 * or jobs pile up unprocessed on a queue nobody is draining.
 */
class QueueWorkerCheck implements Check
{
    /**
     * @var array<int, string>
     */
    protected const array QUEUE_CONFIG_KEYS = [
        'ranetrace.batch.queue_name',
        'ranetrace.errors.queue_name',
        'ranetrace.events.queue_name',
        'ranetrace.logging.queue_name',
        'ranetrace.javascript_errors.queue_name',
        'ranetrace.website_analytics.queue_name',
    ];

    public function run(array $status): CheckResult
    {
        $queues = [];
        foreach (self::QUEUE_CONFIG_KEYS as $key) {
            $name = config($key);
            if (is_string($name) && $name !== '' && $name !== 'default') {
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
