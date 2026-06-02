<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;

test('ranetrace:test-analytics dispatches a synthetic page visit', function (): void {
    Bus::fake();
    Config::set('ranetrace.website_analytics.enabled', true);
    Config::set('ranetrace.key', 'test-key');

    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    Bus::assertDispatched(HandlePageVisitJob::class);
});

test('ranetrace:test-analytics is a no-op when analytics is disabled', function (): void {
    Bus::fake();
    Config::set('ranetrace.website_analytics.enabled', false);

    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});
