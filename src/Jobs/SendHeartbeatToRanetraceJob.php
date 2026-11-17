<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Support\BatchConfig;
use Ranetrace\Laravel\Support\Endpoints;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Php\Http\Heartbeat;
use Ranetrace\Php\Http\ResponsePolicy;
use Throwable;

/**
 * Tells Ranetrace that this application's worker ran, whether or not it had
 * anything to send.
 *
 * `ranetrace:work` dispatches no batch while every buffer is empty, so without
 * this the API cannot tell a quiet application from one whose scheduler or
 * queue worker stopped. The heartbeat is a job on the batch queue rather than
 * a request from the command, so that it stops for the same reasons the data
 * stops: a scheduler that no longer runs, a queue worker that no longer takes
 * jobs, or a key the API rejects (which pauses everything, and the command
 * then dispatches nothing).
 *
 * The answer changes nothing here: no pause is set or lifted, no buffer is
 * touched and nothing is retried. The next heartbeat is an interval away.
 */
class SendHeartbeatToRanetraceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The Composer package whose installed version the heartbeat names.
     */
    public const string PACKAGE = 'ranetrace/ranetrace-laravel';

    /**
     * Held by `ranetrace:work` for one interval after it dispatches a
     * heartbeat, so overlapping runs, and runs per `--type`, dispatch one.
     */
    public const string DISPATCH_CLAIM_KEY = 'ranetrace:heartbeat:dispatched';

    /**
     * Held for SEND_CLAIM_SECONDS by the job that sends. A queue worker that
     * was stopped for hours hands over every heartbeat it held at once when it
     * starts again: one request says what all of them would, and the rest
     * would only draw 429s, or with an unreachable API hold the batch queue
     * for one timeout each. Heartbeats are an interval apart otherwise, so the
     * claim never holds back a regular one.
     */
    public const string SEND_CLAIM_KEY = 'ranetrace:heartbeat:sending';

    public const int SEND_CLAIM_SECONDS = 60;

    /**
     * When the API last accepted a heartbeat, as a Unix timestamp, read by
     * `ranetrace:status`.
     */
    public const string LAST_SENT_KEY = 'ranetrace:last_heartbeat';

    public int $tries = 1;

    /**
     * Lands where the batch job lands: its connection and its queue, as a
     * queue route for the batch job names them. A host that routes its batches
     * to another connection would otherwise get a heartbeat that proves a
     * worker its data does not depend on.
     */
    public function __construct()
    {
        $batchJob = BatchConfig::jobAsDispatched('ranetrace.batch');

        $this->onConnection(BatchConfig::jobConnectionName($batchJob));
        $this->onQueue(BatchConfig::jobQueueName($batchJob));
    }

    /**
     * Never throws: an exception that escapes a queued job lands in the host
     * application's exception handler and its failed_jobs table, for a request
     * whose answer changes nothing.
     */
    public function handle(RanetraceApiClient $client): void
    {
        try {
            if (! BatchConfig::cacheStore()->add(self::SEND_CLAIM_KEY, now()->timestamp, self::SEND_CLAIM_SECONDS)) {
                return;
            }

            $result = $client->sendHeartbeat(Heartbeat::forPackage(Endpoints::SDK, self::PACKAGE));

            $status = is_int($result['status'] ?? null) ? $result['status'] : 0;

            if ($status >= 200 && $status < 300) {
                BatchConfig::cacheStore()->put(self::LAST_SENT_KEY, now()->timestamp, now()->addWeek());

                InternalLogger::debug('Heartbeat sent', ['status' => $status]);

                return;
            }

            $data = is_array($result['data'] ?? null) ? $result['data'] : [];

            InternalLogger::warning('Heartbeat was not accepted', [
                'status' => $status,
                'message' => $result['error'] ?? ResponsePolicy::errorMessage($data, 'No message'),
            ]);
        } catch (Throwable $e) {
            InternalLogger::error('Heartbeat failed', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Reached only when the queue worker ends the job outside handle(), such
     * as a timeout.
     */
    public function failed(Throwable $exception): void
    {
        InternalLogger::warning('Heartbeat job failed', ['exception' => $exception->getMessage()]);
    }
}
