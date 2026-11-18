<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Support\Endpoints;
use Ranetrace\Php\Http\Heartbeat;
use Workbench\App\Console\ReleaseCheckCommand;

/**
 * The workbench's `release:check`, run as a release session runs it, against
 * a faked API that counts the items of each batch as processed, or as failed
 * for the endpoint path named in $refuses, and answers the heartbeat with
 * $heartbeatStatus.
 *
 * @return array{0: int, 1: string}
 */
function releaseCheckRun(string $refuses = '', ?string $keyFile = null, int $heartbeatStatus = 200): array
{
    Http::fake(function (Request $request) use ($refuses, $heartbeatStatus) {
        if (str_ends_with($request->url(), Heartbeat::PATH)) {
            return $heartbeatStatus === 200
                ? Http::response(['success' => true])
                : Http::response(['success' => false, 'error' => ['message' => 'Heartbeat refused']], $heartbeatStatus);
        }

        $items = count(array_values($request->data())[0] ?? []);
        $counter = $refuses !== '' && str_ends_with($request->url(), $refuses) ? 'failed' : 'processed';

        return Http::response(['success' => true, 'items' => ['received' => $items, 'processed' => 0, $counter => $items]]);
    });

    if ($keyFile === null) {
        $keyFile = tempnam(sys_get_temp_dir(), 'release-check-key');
        file_put_contents($keyFile, "release-check-key-12345\n");
    }

    app(Kernel::class)->registerCommand(new ReleaseCheckCommand);

    $exitCode = Artisan::call('release:check', ['key-file' => $keyFile]);

    return [$exitCode, Artisan::output()];
}

test('it sends items of all five types and the heartbeat, and exits 0 when the api takes every one', function (): void {
    [$exitCode, $output] = releaseCheckRun();

    expect($output)->toMatch('/^  errors +\d+ sent, \d+ accepted$/m')
        ->toMatch('/^  events +\d+ sent, \d+ accepted$/m')
        ->toMatch('/^  logs +\d+ sent, \d+ accepted$/m')
        ->toMatch('/^  javascript_errors +\d+ sent, \d+ accepted$/m')
        ->toMatch('/^  page_visits +\d+ sent, \d+ accepted$/m')
        ->toMatch('/^  heartbeat +accepted$/m')
        ->toContain('The API took every item of every type, and the heartbeat.')
        ->not->toContain('refused')
        ->and($exitCode)->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer release-check-key-12345'));
});

test('it sends the heartbeat the queued job sends, with the release check key', function (): void {
    releaseCheckRun();

    $heartbeat = Heartbeat::forPackage(Endpoints::SDK, Endpoints::PACKAGE);

    Http::assertSentCount(6);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/heartbeat')
        && $request->data() === $heartbeat->body()
        && $request->hasHeader('User-Agent', $heartbeat->userAgent())
        && $request->hasHeader('Authorization', 'Bearer release-check-key-12345'));
});

test('it exits 1 and says not to tag when the api does not take the heartbeat', function (int $status): void {
    [$exitCode, $output] = releaseCheckRun(heartbeatStatus: $status);

    expect($exitCode)->toBe(1)
        ->and($output)->toMatch('/^  heartbeat +refused: the API answered '.$status.' \(Heartbeat refused\)$/m')
        ->toContain('Do not tag')
        ->not->toMatch('/\d+ refused$/m');
})->with([
    'an api without the endpoint' => [404],
    'an api that refuses the body' => [422],
]);

test('it exits 1 and says not to tag when the api refuses the items of one type', function (string $path, string $type): void {
    [$exitCode, $output] = releaseCheckRun($path);

    expect($exitCode)->toBe(1)
        ->and($output)->toMatch('/^  '.$type.' +(\d+) sent, 0 accepted, \1 refused$/m')
        ->toContain('Do not tag')
        ->and(mb_substr_count($output, ' refused'))->toBe(1);
})->with([
    'errors' => ['/errors/store', 'errors'],
    'events' => ['/events/store', 'events'],
    'logs' => ['/logs/store', 'logs'],
    'javascript errors' => ['/javascript-errors/store', 'javascript_errors'],
    'page visits' => ['/page-visits/store', 'page_visits'],
]);

test('it exits 1 without sending when the key file is missing', function (): void {
    [$exitCode, $output] = releaseCheckRun(keyFile: sys_get_temp_dir().'/no-such-release-check-key');

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('No readable key file at [');

    Http::assertNothingSent();
});

test('it never prints the key', function (): void {
    [, $output] = releaseCheckRun();

    expect($output)->not->toContain('release-check-key-12345');
});
