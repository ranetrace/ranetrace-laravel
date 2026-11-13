<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Services;

use Exception;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Php\Worker\TestOutcome;
use Ranetrace\Php\Worker\TestResult;
use Throwable;

/**
 * Sends one test error and one test event straight to the API, for
 * `ranetrace:test`.
 *
 * The test goes beside the buffer and the queue, never through them. Each item
 * is built by the real capture path ({@see Ranetrace::buildErrorPayload()} and
 * {@see HandleErrorJob::bufferedItem()}, and their event twins), so it is shaped
 * exactly like a real one, and sent with {@see RanetraceApiClient}, but the
 * answer is only read: nothing is buffered, queued or paused. A test that ran
 * through the batch job would turn a wrong key into a 15 minute pause on
 * everything, and the developer who fixes the key and runs the test again would
 * be told nothing was sent.
 *
 * The one write goes the other way. A 200 disproves some pauses real traffic
 * left behind, so it lifts them: the global pause, and the type's own pause
 * unless its reason is one an accepted item says nothing about.
 *
 * What an answer means and how it reads is the shared core's
 * {@see TestResult}, so this test and `ranetrace/ranetrace-php`'s
 * `vendor/bin/ranetrace-test` say the same thing about the same answer. This
 * class mirrors that SDK's `TestRun`: it decides what to send and what to lift.
 */
class RanetraceTestRun
{
    /**
     * The types the test sends, in the order it sends them. Each is both the
     * buffer type and the feature whose flag decides whether it is sent.
     *
     * @var list<string>
     */
    public const array TYPES = ['errors', 'events'];

    public function __construct(
        protected Ranetrace $ranetrace,
        protected RanetraceApiClient $client,
        protected RanetracePauseManager $pauses,
    ) {}

    /**
     * Test each type in turn. The caller checks the master switch and the key
     * first.
     *
     * @return array{types: array<string, TestResult>, lifted: list<array{scope: string, reason: string}>}
     */
    public function run(): array
    {
        $types = [];
        $lifted = [];
        $keyRefused = false;

        foreach (self::TYPES as $type) {
            $types[$type] = $this->test($type, $keyRefused, $lifted);

            $keyRefused = $keyRefused || $types[$type]->outcome === TestOutcome::Unauthorized;
        }

        return ['types' => $types, 'lifted' => $lifted];
    }

    public function baseUrl(): string
    {
        return $this->client->baseUrl();
    }

    /**
     * Build, send and read one type.
     *
     * A type whose feature is off says so even after a 401, because that is
     * the reason it would not be sent either way. Otherwise a key an earlier
     * type had refused is not presented again: every endpoint refuses it.
     *
     * @param  list<array{scope: string, reason: string}>  $lifted  Receives the pauses this type's 200 lifted.
     */
    protected function test(string $type, bool $keyRefused, array &$lifted): TestResult
    {
        if (! Ranetrace::isSwitchedOn($type)) {
            return TestResult::skipped();
        }

        if ($keyRefused) {
            return TestResult::notSent();
        }

        $item = $this->build($type);

        if ($item === null) {
            return TestResult::notCaptured(InternalLogger::todaysFile());
        }

        $tested = TestResult::fromResponse($this->client->sendBatchOfType($type, [$item]));

        if ($tested->disprovesPauses()) {
            array_push($lifted, ...$this->liftPausesDisprovedBy($type));
        }

        return $tested;
    }

    /**
     * The one test item of a type, built and filtered the way its capture job
     * would buffer it, or null when that left nothing to send. A throw while
     * building costs the item, never the command, and is written to the
     * internal log the way the capture path writes it.
     *
     * @return array<string, mixed>|null
     */
    protected function build(string $type): ?array
    {
        try {
            if ($type === 'errors') {
                return (new HandleErrorJob($this->ranetrace->buildErrorPayload($this->testException())))->bufferedItem();
            }

            $event = $this->ranetrace->buildEventPayload('test_event', ['source' => 'ranetrace:test']);

            return $event === null ? null : (new HandleEventJob($event))->bufferedItem();
        } catch (Throwable $e) {
            InternalLogger::error('Failed to build the test item', [
                'type' => $type,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Made outside `src/`, because `Ranetrace::report()` drops an exception
     * thrown there as one of the package's own. The PHP SDK's test sends the
     * same message.
     */
    protected function testException(): Exception
    {
        /** @var array{simple: callable(): Exception} $exceptions */
        $exceptions = require dirname(__DIR__, 2).'/resources/test-exceptions.php';

        return $exceptions['simple']();
    }

    /**
     * Clear the pauses a 200 for this type disproves, and name each one that
     * is really gone afterwards. A pause store that cannot be read or cleared
     * lifts nothing and leaves a warning in the internal log; the answer the
     * type got stands.
     *
     * @return list<array{scope: string, reason: string}>
     */
    protected function liftPausesDisprovedBy(string $type): array
    {
        $lifted = [];

        try {
            $global = $this->pauses->getGlobalPause();

            if (is_array($global)) {
                $this->pauses->clearGlobalPause();

                if (! $this->pauses->isGloballyPaused()) {
                    $lifted[] = ['scope' => 'everything', 'reason' => $global['reason']];
                }
            }

            $feature = $this->pauses->getFeaturePause($type);

            if (is_array($feature) && TestResult::liftsFeaturePause($feature['reason'])) {
                $this->pauses->clearFeaturePause($type);

                if (! $this->pauses->isFeaturePaused($type)) {
                    $lifted[] = ['scope' => $type, 'reason' => $feature['reason']];
                }
            }
        } catch (Throwable $e) {
            InternalLogger::warning('Could not lift the pauses an accepted test item disproves', [
                'type' => $type,
                'exception' => $e->getMessage(),
            ]);
        }

        return $lifted;
    }
}
