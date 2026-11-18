<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The rate limit on the two routes the package mounts in the host application:
 * the JavaScript error relay and the analytics beacon. Both are public POSTs a
 * page fires on its own, so the limit has to count them apart from everything
 * the host limits itself, and has to hold when the host's auth guard does not.
 */
beforeEach(function (): void {
    Bus::fake();
    $this->withoutMiddleware(VerifyCsrfToken::class);

    config([
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);
});

dataset('package routes', [
    'the JavaScript error route' => ['ranetrace.javascript-errors.store', 'ranetrace.javascript_errors.throttle', 60],
    'the beacon route' => ['ranetrace.analytics.verify', 'ranetrace.website_analytics.beacon.throttle', 120],
]);

/**
 * Post a request the named package route accepts.
 */
function postToPackageRoute(string $routeName): TestResponse
{
    $payload = $routeName === 'ranetrace.analytics.verify'
        ? ['token' => (string) Str::uuid()]
        : ['message' => 'Test error', 'url' => 'https://example.com/'];

    return test()->postJson(route($routeName), $payload);
}

test('a package route answers 429 once its configured limit is spent', function (string $routeName, string $configKey): void {
    config([$configKey => '2,1']);

    postToPackageRoute($routeName)->assertOk()->assertHeader('X-RateLimit-Limit', 2);
    postToPackageRoute($routeName)->assertOk();
    postToPackageRoute($routeName)->assertTooManyRequests();
})->with('package routes');

test('the limit of a package route lasts the configured minutes', function (string $routeName, string $configKey): void {
    config([$configKey => '1,2']);

    postToPackageRoute($routeName)->assertOk();

    $this->travel(61)->seconds();
    postToPackageRoute($routeName)->assertTooManyRequests();

    $this->travel(60)->seconds();
    postToPackageRoute($routeName)->assertOk();
})->with('package routes');

test('a throttle value that is not requests,minutes gives the route its default limit', function (string $routeName, string $configKey, int $defaultLimit, mixed $value): void {
    config([$configKey => $value]);

    postToPackageRoute($routeName)->assertOk()->assertHeader('X-RateLimit-Limit', $defaultLimit);
})->with('package routes')->with([
    'a word' => ['api'],
    'zero requests' => ['0,1'],
    'zero minutes' => ['5,0'],
    'a third segment' => ['5,1,prefix'],
    'nothing' => [''],
    'null' => [null],
]);

test('requests alone, without the minutes, limit per minute', function (string $routeName, string $configKey): void {
    config([$configKey => '1']);

    postToPackageRoute($routeName)->assertOk()->assertHeader('X-RateLimit-Limit', 1);

    $this->travel(61)->seconds();
    postToPackageRoute($routeName)->assertOk();
})->with('package routes');

test('requests to a package route do not count against a route the host application limits itself', function (string $routeName): void {
    // Laravel keys an unnamed `throttle:2,1` on the user or the IP alone, so
    // every route limited that way shares one counter. Laravel's starter kits
    // limit their email verification routes like this.
    Route::post('/host-limited', fn (): string => 'ok')->middleware(['web', 'throttle:2,1']);

    postToPackageRoute($routeName)->assertOk();
    postToPackageRoute($routeName)->assertOk();
    postToPackageRoute($routeName)->assertOk();

    $this->post('/host-limited')->assertOk();
})->with('package routes');

test('the two package routes count apart from each other', function (): void {
    config([
        'ranetrace.javascript_errors.throttle' => '2,1',
        'ranetrace.website_analytics.beacon.throttle' => '2,1',
    ]);

    postToPackageRoute('ranetrace.javascript-errors.store')->assertOk();
    postToPackageRoute('ranetrace.javascript-errors.store')->assertOk();

    postToPackageRoute('ranetrace.analytics.verify')->assertOk();
});

test('signed-in users behind one IP each have their own count', function (string $routeName, string $configKey): void {
    config([$configKey => '1,1']);

    $this->actingAs(new GenericUser(['id' => 1]));
    postToPackageRoute($routeName)->assertOk();
    postToPackageRoute($routeName)->assertTooManyRequests();

    $this->actingAs(new GenericUser(['id' => 2]));
    postToPackageRoute($routeName)->assertOk();
})->with('package routes');

test('guests are counted by IP', function (string $routeName, string $configKey): void {
    config([$configKey => '1,1']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);
    postToPackageRoute($routeName)->assertOk();
    postToPackageRoute($routeName)->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2']);
    postToPackageRoute($routeName)->assertOk();
})->with('package routes');

test('a signed-in user and a guest whose IP reads like that user id do not share a count', function (): void {
    config(['ranetrace.javascript_errors.throttle' => '1,1']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);
    postToPackageRoute('ranetrace.javascript-errors.store')->assertOk();

    $user = new GenericUser(['id' => '203.0.113.1']);

    $this->actingAs($user);
    postToPackageRoute('ranetrace.javascript-errors.store')->assertOk();
});

test('an auth guard that throws leaves a package route answering, limited by IP', function (string $routeName, string $configKey): void {
    config([$configKey => '2,1']);
    useThrowingAuthGuard('User provider unreachable');

    postToPackageRoute($routeName)->assertOk();
    postToPackageRoute($routeName)->assertOk();
    postToPackageRoute($routeName)->assertTooManyRequests();
})->with('package routes');
