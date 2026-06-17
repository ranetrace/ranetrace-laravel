<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

/**
 * When the buffer lock is contended, a capture job must re-queue the item via
 * release() instead of dropping it — bounded by $tries so a permanently stuck
 * lock cannot loop forever. 0s lock_wait makes the contended add fail without a
 * real sleep.
 */
beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Config::set('ranetrace.batch.lock_wait', 0);
    Cache::store('array')->flush();
});

test('a contended buffer add re-queues the capture job instead of dropping it', function (): void {
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(1); // below $tries (3)
    $queueJob->shouldReceive('release')->once();         // re-queued, not dropped

    $job = new HandleLogJob(['level' => 'error', 'message' => 'boom']);
    $job->setJob($queueJob);

    // Hold the logs buffer lock so the job's add cannot acquire it.
    $lock = Cache::store('array')->lock('ranetrace:buffer:logs:lock', 10);
    $lock->acquire();

    $job->handle(new RanetraceBatchBuffer);

    $lock->release();

    expect($job->tries)->toBe(3);
});

test('a contended buffer add stops re-queuing once the attempt cap is reached', function (): void {
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(3); // at $tries — give up, drop
    $queueJob->shouldReceive('release')->never();

    $job = new HandleLogJob(['level' => 'error', 'message' => 'boom']);
    $job->setJob($queueJob);

    $lock = Cache::store('array')->lock('ranetrace:buffer:logs:lock', 10);
    $lock->acquire();

    $job->handle(new RanetraceBatchBuffer);

    $lock->release();
});

test('a successful buffer add does not re-queue the job', function (): void {
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->never(); // buffered fine, nothing to retry

    $job = new HandleLogJob(['level' => 'error', 'message' => 'stored']);
    $job->setJob($queueJob);

    $buffer = new RanetraceBatchBuffer;
    $job->handle($buffer);

    expect($buffer->count('logs'))->toBe(1);
});
