<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Jobs\SendHeartbeatToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Php\Contract\WireContract;

/**
 * The heartbeat as the contract describes it, checked on the request the real
 * job sends, so the path, the body and the headers are checked as they leave.
 */
beforeEach(function (): void {
    Config::set('ranetrace.key', 'test-api-key-12345');
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

/**
 * Send one heartbeat through the real job and return the request that left the
 * client.
 */
function contractSentHeartbeatRequest(): Request
{
    Http::fake(['api.ranetrace.com/*' => Http::response(['success' => true], 200)]);

    (new SendHeartbeatToRanetraceJob)->handle(app(RanetraceApiClient::class));

    $sent = null;

    Http::assertSent(function (Request $request) use (&$sent): bool {
        $sent = $request;

        return true;
    });

    return $sent;
}

/**
 * The headers this SDK set on the heartbeat, without the `Host` and
 * `Content-Length` Guzzle adds itself.
 *
 * @return array<string, string>
 */
function contractHeartbeatHeaders(Request $request): array
{
    $headers = [];

    foreach ($request->headers() as $name => $values) {
        if (in_array($name, ['Host', 'Content-Length'], true)) {
            continue;
        }

        $headers[$name] = (string) ($values[0] ?? '');
    }

    return $headers;
}

test('a heartbeat goes to the path the contract names', function (): void {
    $contract = WireContract::heartbeat();

    expect($contract['method'])->toBe('POST')
        ->and(contractSentHeartbeatRequest()->url())->toBe(WireContract::endpoints()['base_url_default'].$contract['path']);
});

test('a heartbeat body holds exactly the contracted fields with values the contract allows', function (): void {
    $fields = WireContract::heartbeat()['fields'];
    $body = contractSentHeartbeatRequest()->data();

    expect(array_keys($body))->toEqualCanonicalizing(array_keys($fields))
        ->and($body['sdk'])->toBeIn($fields['sdk']['in'])
        ->and($body['sdk_version'])->toBeString()
        ->and(mb_strlen($body['sdk_version']))->toBeGreaterThanOrEqual($fields['sdk_version']['min'])
        ->and(mb_strlen($body['sdk_version']))->toBeLessThanOrEqual($fields['sdk_version']['max']);
});

test('a heartbeat travels with exactly the five contracted headers', function (): void {
    $contract = WireContract::headers();
    $sent = contractHeartbeatHeaders(contractSentHeartbeatRequest());

    $userAgent = str_replace(
        ['{SDK}', '{Feature}', '{version}'],
        ['Laravel', WireContract::heartbeat()['user_agent_feature'], $contract['api_version']],
        $contract['request']['User-Agent']['format']
    );

    expect(array_keys($sent))->toEqualCanonicalizing(array_keys($contract['request']))
        ->and($sent['Content-Type'])->toBe($contract['request']['Content-Type']['value'])
        ->and($sent['Accept'])->toBe($contract['request']['Accept']['value'])
        ->and($sent['Ranetrace-API-Version'])->toBe($contract['api_version'])
        ->and($sent['Authorization'])->toBe(str_replace('{api key}', 'test-api-key-12345', $contract['request']['Authorization']['format']))
        ->and($sent['User-Agent'])->toBe($userAgent);
});

test('ranetrace:work dispatches a heartbeat at most once per contracted interval', function (): void {
    $interval = WireContract::heartbeat()['interval_seconds'];

    $this->freezeTime();
    Bus::fake();

    $this->artisan('ranetrace:work')->assertSuccessful();
    $this->travel($interval - 1)->seconds();
    $this->artisan('ranetrace:work')->assertSuccessful();

    Bus::assertDispatchedTimes(SendHeartbeatToRanetraceJob::class, 1);

    $this->travel(1)->seconds();
    $this->artisan('ranetrace:work')->assertSuccessful();

    Bus::assertDispatchedTimes(SendHeartbeatToRanetraceJob::class, 2);
});
