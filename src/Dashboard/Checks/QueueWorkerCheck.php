<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

use Ranetrace\Laravel\Support\BatchConfig;

/**
 * A job that lands on a queue other than the default queue of its connection
 * needs a worker explicitly configured to process that queue, or it piles up
 * unprocessed on a queue nobody is draining. Where each feature's job lands
 * follows the host's queue routes and forwards, so a job routed to another
 * connection is judged against that connection's default queue, and the hint
 * names the connection when it is not `queue.default`.
 */
class QueueWorkerCheck implements Check
{
    public function run(array $status): CheckResult
    {
        $defaultConnectionName = (string) config('queue.default');

        /** @var array<string, array<string, true>> $queuesByConnection */
        $queuesByConnection = [];
        foreach (array_keys(BatchConfig::FEATURE_JOBS) as $path) {
            $job = BatchConfig::jobAsDispatched($path);

            if (! BatchConfig::jobLandsOnConnectionDefaultQueue($job)) {
                $queuesByConnection[BatchConfig::jobConnectionName($job)][(string) BatchConfig::jobQueueName($job)] = true;
            }
        }

        if ($queuesByConnection === []) {
            return CheckResult::pass('queue_worker', 'Using the connection\'s default queue');
        }

        $names = [];
        $commands = [];
        foreach ($queuesByConnection as $connectionName => $queues) {
            $queueNames = array_keys($queues);
            $isDefaultConnection = $connectionName === $defaultConnectionName;

            foreach ($queueNames as $queueName) {
                $names[] = $isDefaultConnection ? $queueName : $queueName.' on '.$connectionName;
            }

            $commands[] = '`queue:work'.($isDefaultConnection ? '' : ' '.$connectionName).' --queue='.implode(',', $queueNames).'`';
        }

        return CheckResult::warn(
            'queue_worker',
            'Non-default queue(s): '.implode(', ', $names),
            'Make sure a worker processes these queues, e.g. '.implode(' and ', $commands).'.'
        );
    }
}
