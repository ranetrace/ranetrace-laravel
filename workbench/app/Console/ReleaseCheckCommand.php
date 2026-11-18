<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Ranetrace\Laravel\Jobs\SendHeartbeatToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\Endpoints;
use Ranetrace\Php\Http\Heartbeat;
use Ranetrace\Php\Http\ResponsePolicy;
use Ranetrace\Php\Worker\TestResult;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The release gate: capture test items of all five types through the
 * package's own test commands, send them to the API, then the heartbeat, and
 * say for each whether the API took it. Run it from the checkout at the commit
 * about to be tagged, before the tag:
 *
 *     vendor/bin/testbench release:check
 *
 * The API matches each item's fields strictly and refuses an item that
 * carries a key it does not know, which is final. A tag that sends a new field
 * before the backend that accepts it is deployed loses every item of that type
 * for everyone who upgrades, and a tag cannot be taken back. A pushed backend
 * commit is not a deployed one, so this asks the deployed API itself. The page
 * visit is the item only this package sends, so no other check covers it.
 *
 * The heartbeat is the one {@see SendHeartbeatToRanetraceJob} sends, so a 2xx
 * says the deployed API has the endpoint and takes this commit's body. It is
 * a real one: it counts as a heartbeat of the release check website. The app
 * watches a website only once heartbeats have kept arriving for a while, so
 * the one heartbeat of a check does not make that website read as stopped.
 *
 * The key is an ingest key of a website kept for this check, read from a file
 * so that it is never typed into a command line: the file named as the
 * argument, else by RANETRACE_RELEASE_KEY_FILE, else
 * ~/.config/ranetrace/release-check-key. The API is the production one unless
 * RANETRACE_BASE_URL says otherwise, and the report names the one it asked.
 *
 * It lives in the workbench because it is for maintainers: an application that
 * installs the package never loads it.
 */
class ReleaseCheckCommand extends Command
{
    protected $signature = 'release:check {key-file? : The file that holds the ingest key of the release check website}';

    protected $description = 'Before a tag: send test items of every type and a heartbeat to the Ranetrace API and fail when it refuses one';

    public function handle(): int
    {
        $key = $this->key();

        if ($key === null) {
            return self::FAILURE;
        }

        config([
            'ranetrace.key' => $key,
            'ranetrace.enabled' => true,
            'ranetrace.batch.cache_driver' => 'array',
        ]);

        foreach (['errors', 'events', 'logging', 'javascript_errors', 'website_analytics'] as $feature) {
            config(["ranetrace.{$feature}.enabled" => true, "ranetrace.{$feature}.queue" => false]);
        }

        $captured = new BufferedOutput;

        if ($this->runCommand('ranetrace:test', ['--all' => true], $captured) !== self::SUCCESS) {
            $this->line($captured->fetch());
            $this->error('A test command could not capture its items, so nothing was sent. Its output is above.');

            return self::FAILURE;
        }

        $client = new RanetraceApiClient($key);
        $buffer = new RanetraceBatchBuffer;
        $types = Endpoints::table()->types();
        $width = max(array_map(mb_strlen(...), $types));
        $every = true;

        $this->line("Release check against {$client->baseUrl()}");
        $this->newLine();

        foreach ($types as $type) {
            [$taken, $line] = $this->send($client, $buffer, $type);
            $every = $every && $taken;

            $this->line(sprintf("  %-{$width}s  %s", $type, $line));
        }

        [$taken, $line] = $this->sendHeartbeat($client);
        $every = $every && $taken;

        $this->line(sprintf("  %-{$width}s  %s", 'heartbeat', $line));

        $this->newLine();
        $this->line($every
            ? 'The API took every item of every type, and the heartbeat.'
            : 'The API did not take everything this commit sends. Do not tag: find out which side is behind first.');

        return $every ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Send what the test commands buffered for one type, and read the answer.
     *
     * @return array{0: bool, 1: string} Whether the API took every item, and the type's line.
     */
    private function send(RanetraceApiClient $client, RanetraceBatchBuffer $buffer, string $type): array
    {
        $items = array_column($buffer->getItems($type, 100), 'data');

        if ($items === []) {
            return [false, 'not captured: the test command buffered no item of this type'];
        }

        try {
            $result = $client->sendBatchOfType($type, $items);
        } catch (Throwable $failure) {
            return [false, 'failed: '.$failure->getMessage()];
        }

        $counters = (new ResponsePolicy)->decide($result)->counters;

        if ($result['status'] !== 200 || $counters === null) {
            return [false, TestResult::fromResponse($result)->line($type, $client->baseUrl(), false)];
        }

        $sent = count($items);
        $accepted = $counters->processed + $counters->ignored;
        $line = "{$sent} sent, {$accepted} accepted";

        if ($counters->failed > 0) {
            $line .= ", {$counters->failed} refused";
        }

        if ($counters->unprocessed > 0) {
            $line .= ", {$counters->unprocessed} not processed: the API ran out of time, run the check again";
        }

        return [$accepted === $sent, $line];
    }

    /**
     * Send the heartbeat the queued job sends, and read the answer.
     *
     * @return array{0: bool, 1: string} Whether the API took it, and its line.
     */
    private function sendHeartbeat(RanetraceApiClient $client): array
    {
        $answer = $client->sendHeartbeat(Heartbeat::forPackage(Endpoints::SDK, Endpoints::PACKAGE));
        $status = $answer['status'];

        return match (true) {
            $status >= 200 && $status < 300 => [true, 'accepted'],
            $status === 0 => [false, "failed: could not reach {$client->baseUrl()} (".($answer['error'] ?? 'unknown network error').')'],
            default => [false, "refused: the API answered {$status} (".ResponsePolicy::errorMessage($answer['data'], 'no message').')'],
        };
    }

    /**
     * The ingest key, or null after saying why there is none.
     */
    private function key(): ?string
    {
        $argument = $this->argument('key-file');
        $configured = getenv('RANETRACE_RELEASE_KEY_FILE');
        $home = getenv('HOME');

        $file = match (true) {
            is_string($argument) && $argument !== '' => $argument,
            is_string($configured) && $configured !== '' => $configured,
            is_string($home) && $home !== '' => $home.'/.config/ranetrace/release-check-key',
            default => null,
        };

        if ($file === null || ! is_file($file) || ! is_readable($file)) {
            $this->error(sprintf(
                'No readable key file at [%s]. Save the ingest key of the release check website there, or name the file as the argument.',
                $file ?? 'HOME is not set',
            ));

            return null;
        }

        $key = mb_trim((string) file_get_contents($file));

        if ($key === '') {
            $this->error("The key file [{$file}] is empty.");

            return null;
        }

        return $key;
    }
}
