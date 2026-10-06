<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

use Ranetrace\Laravel\Jobs\BaseRanetraceJob;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Support\BatchConfig;
use ReflectionClass;

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
    /**
     * Each feature's config path and the job its queue name applies to.
     *
     * @var array<string, class-string<BaseRanetraceJob|SendBatchToRanetraceJob>>
     */
    protected const array FEATURE_JOBS = [
        'ranetrace.batch' => SendBatchToRanetraceJob::class,
        'ranetrace.errors' => HandleErrorJob::class,
        'ranetrace.events' => HandleEventJob::class,
        'ranetrace.logging' => HandleLogJob::class,
        'ranetrace.javascript_errors' => HandleJavaScriptErrorJob::class,
        'ranetrace.website_analytics' => HandlePageVisitJob::class,
    ];

    public function run(array $status): CheckResult
    {
        $defaultConnectionName = (string) config('queue.default');

        /** @var array<string, array<string, true>> $queuesByConnection */
        $queuesByConnection = [];
        foreach (self::FEATURE_JOBS as $path => $jobClass) {
            $job = $this->jobAsDispatched($jobClass, $path);

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

    /**
     * The job with the queue its constructor would give it, built without the
     * constructor because a queue route matches on the class alone and the
     * constructors' payload arguments play no part in where the job lands.
     *
     * @param  class-string<BaseRanetraceJob|SendBatchToRanetraceJob>  $jobClass
     */
    private function jobAsDispatched(string $jobClass, string $featureConfigPath): BaseRanetraceJob|SendBatchToRanetraceJob
    {
        $job = (new ReflectionClass($jobClass))->newInstanceWithoutConstructor();
        $job->onQueue(BatchConfig::featureQueueName($featureConfigPath));

        return $job;
    }
}
