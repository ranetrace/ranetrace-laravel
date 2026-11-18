<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;

/**
 * The visit data of the single job dispatched by the request under test.
 *
 * @var Closure(): array<string, mixed>
 */
$visitData = function (): array {
    $job = Bus::dispatched(HandlePageVisitJob::class)->first();

    expect($job)->not->toBeNull();

    return $job->getVisitData();
};

beforeEach(function (): void {
    Bus::fake();
    Cache::flush();

    Route::middleware(['web', TrackPageVisit::class])->group(function (): void {
        Route::get('/invitations/{token}', fn () => response('Invitation'));
        Route::get('/verify/{id}/{hash}', fn () => response('Verified'));
        Route::get('/articles/{slug}', fn () => response('Article'));
        Route::get('/reset-password/{token}', fn () => response('Reset'));
        Route::get('/teams/{invitation:token}', fn () => response('Team invite'));
    });
});

test('a token in the path never reaches the dispatched visit job', function () use ($visitData): void {
    $this->withHeaders(humanBrowserHeaders())
        ->get('/invitations/live-invite-token-abc123')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and(json_encode($data))->not->toContain('live-invite-token-abc123');
});

test('a verification hash in the path is redacted while other segments survive', function () use ($visitData): void {
    $this->withHeaders(humanBrowserHeaders())
        ->get('/verify/42/8f14e45fceea167a5a36dedd4bea2543')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/verify/42/[REDACTED]');
});

test('the stock password-reset page is tracked with its token redacted', function () use ($visitData): void {
    // `reset-password` is deliberately NOT in the default excluded_paths: the
    // page-view signal is kept, and the route-parameter scrub keeps the live
    // reset token out of the visit data.
    $this->withHeaders(humanBrowserHeaders())
        ->get('/reset-password/live-reset-token-xyz789')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/reset-password/[REDACTED]')
        ->and(json_encode($data))->not->toContain('live-reset-token-xyz789');
});

test('non-sensitive route parameters are left untouched', function () use ($visitData): void {
    $this->withHeaders(humanBrowserHeaders())
        ->get('/articles/my-first-post?utm_source=newsletter')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/articles/my-first-post')
        ->and($data['utm_source'])->toBe('newsletter');
});

test('a custom-key binding is redacted via its binding field', function () use ($visitData): void {
    // `{invitation:token}` names the parameter `invitation`; only the binding
    // field says the segment is a token.
    $this->withHeaders(humanBrowserHeaders())
        ->get('/teams/live-team-invite-abc')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/teams/[REDACTED]')
        ->and(json_encode($data))->not->toContain('live-team-invite-abc');
});

test('a token in the REFERRER path never reaches the dispatched visit job', function () use ($visitData): void {
    // The scenario the current-request scrub cannot see: the visitor was on the
    // reset page (redacted there) and clicked a link. Same-origin navigations
    // send the full previous URL by default, so the live token arrives on the
    // NEXT page view instead.
    $this->withHeaders(humanBrowserHeaders() + [
        'Referer' => 'http://localhost/reset-password/live-reset-token-xyz789',
    ])->get('/articles/my-first-post')->assertSuccessful();

    $data = $visitData();

    expect($data['referrer'])->toBe('http://localhost/reset-password/[REDACTED]')
        ->and($data['referrer'])->not->toContain('live-reset-token-xyz789')
        ->and($data['path'])->toBe('/articles/my-first-post');
});

test('a referrer with a url password still has its route token redacted', function () use ($visitData): void {
    // The route lookup has to see the referrer as sent: once its password is
    // the placeholder, `parse_url()` no longer finds the host or the path.
    $this->withHeaders(humanBrowserHeaders() + [
        'Referer' => 'http://app:hunter2-pass@localhost/reset-password/live-reset-token-xyz789',
    ])->get('/articles/my-first-post')->assertSuccessful();

    expect($visitData()['referrer'])->toBe('http://app:[REDACTED]@localhost/reset-password/[REDACTED]');
});

test('a non-sensitive referrer path is preserved in full', function () use ($visitData): void {
    $this->withHeaders(humanBrowserHeaders() + [
        'Referer' => 'http://localhost/articles/previous-post?utm_source=mail',
    ])->get('/articles/my-first-post')->assertSuccessful();

    expect($visitData()['referrer'])->toBe('http://localhost/articles/previous-post?utm_source=mail');
});

test('an external referrer keeps its path and still has its query scrubbed', function () use ($visitData): void {
    // A third-party path is not described by our routes, so it is left alone,
    // but the query-string scrub still applies, as it always did.
    $this->withHeaders(humanBrowserHeaders() + [
        'Referer' => 'https://search.example.com/results/laravel?token=theirs&q=ranetrace',
    ])->get('/articles/my-first-post')->assertSuccessful();

    expect($visitData()['referrer'])
        ->toBe('https://search.example.com/results/laravel?token=[REDACTED]&q=ranetrace');
});

test('a secret in both the path and the query never reaches the dispatched visit job', function () use ($visitData): void {
    $this->withHeaders(humanBrowserHeaders())
        ->get('/invitations/dual-secret?token=dual-secret&page=2')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and(json_encode($data))->not->toContain('dual-secret');
});

test('a prefixed token written percent-encoded is redacted in the path and the referrer of a visit', function () use ($visitData): void {
    $encodedKey = implode('', ['sk%5Flive', '%5F51HabcDEF0123456789']);

    $this->withHeaders(humanBrowserHeaders() + ['Referer' => 'http://localhost/articles/first?ref='.$encodedKey])
        ->get('/articles/'.$encodedKey.'?next=https%3A%2F%2Fapp.test%2Fkeys%2Fsk_live_51HabcDEF0123456789')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/articles/[REDACTED]')
        ->and(json_encode($data))->not->toContain('51HabcDEF0123456789')
        ->and($data['referrer'])->toBe('http://localhost/articles/first?ref=[REDACTED]');
});
