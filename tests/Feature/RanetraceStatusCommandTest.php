<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

/**
 * The text output of ranetrace:status, as plain lines without styling tags.
 *
 * @param  array<string, mixed>  $options
 */
function statusOutput(array $options = []): string
{
    Artisan::call('ranetrace:status', $options);

    return Artisan::output();
}

test('status reports healthy with empty buffers', function (): void {
    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('Overall Status: HEALTHY')
        ->assertSuccessful();
});

test('status reports unhealthy when a buffer is near its own capacity', function (): void {
    Config::set('ranetrace.batch.max_buffer_size', 10);

    $buffer = app(RanetraceBatchBuffer::class);
    for ($i = 0; $i < 8; $i++) {
        $buffer->addItem('events', ['event_name' => "e{$i}"]);
    }

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('ISSUES DETECTED')
        ->assertSuccessful();
});

test('status warns about a stalled drain when buffered items wait past the drain window', function (): void {
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);

    // Age the item past the 600s DRAIN_STALE_SECONDS window so it is overdue.
    $this->travel(601)->seconds();

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('No recent batch drain')
        ->assertSuccessful();
});

test('status still warns about a stalled drain after hours with no drain and no writes', function (): void {
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    $this->travel(2)->hours();

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('No recent batch drain for: events')
        ->assertSuccessful();
});

test('status reports the ingest key as configured without naming its value', function (): void {
    Config::set('ranetrace.key', 'ingest-key');

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('Ingest API Key: Configured')
        ->doesntExpectOutputToContain('ingest-key')
        ->assertSuccessful();
});

test('status outputs successfully with the --json flag', function (): void {
    $this->artisan('ranetrace:status', ['--json' => true])
        ->assertSuccessful();
});

test('status renders an active pause without crashing and shows the remaining time', function (): void {
    // Regression guard: time_remaining_seconds is a Carbon-3 float; before the
    // (int) cast it TypeError'd when passed to formatDuration(int) for an
    // active pause, the exact case the status command exists to report.
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('PAUSED')
        ->assertSuccessful();
});

test('a feature paused alone shows the guidance for its reason under its row', function (string $reason, string $guidance): void {
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, $reason);

    $lines = explode("\n", statusOutput());
    $rowIndex = array_key_first(array_filter(
        $lines,
        fn (string $line): bool => str_contains($line, 'errors') && str_contains($line, "(reason: {$reason},"),
    ));

    expect($rowIndex)->not->toBeNull()
        ->and($lines[$rowIndex + 1])->toBe(str_repeat(' ', 25).'→ '.$guidance)
        ->and(implode("\n", $lines))->not->toContain('Recommendations');
})->with([
    '429' => ['429', 'Rate limited, auto-resumes'],
    '413' => ['413', 'Payload too large: client bug, investigate'],
    '422' => ['422', 'Request body rejected as malformed: client bug, investigate'],
    '500' => ['500', 'Ranetrace backend error: check backend health'],
    '403' => ['403', 'Subscription or permission issue: check your subscription and permissions'],
]);

test('the pause guidance prints once when Recommendations also prints', function (): void {
    Config::set('ranetrace.batch.max_buffer_size', 10);

    $buffer = app(RanetraceBatchBuffer::class);
    for ($i = 0; $i < 8; $i++) {
        $buffer->addItem('events', ['event_name' => "e{$i}"]);
    }

    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');

    $output = statusOutput();

    expect($output)->toContain('Recommendations')
        ->and(mb_substr_count($output, '→ Rate limited, auto-resumes'))->toBe(1)
        ->and($output)->not->toContain("Feature 'errors' paused");
});

test('a pause with an unknown reason prints no guidance line', function (): void {
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '418');

    $output = statusOutput();

    expect($output)->toContain('(reason: 418,')
        ->and($output)->not->toContain('→');
});

test('an expired feature pause shows no guidance', function (): void {
    Cache::store('array')->forever('ranetrace.feature.errors.pause', [
        'paused_until' => now()->subMinute()->toIso8601String(),
        'reason' => '429',
    ]);

    $output = statusOutput();

    expect($output)->toContain('Pause expired')
        ->and($output)->not->toContain('→');
});

test('a global pause still lists its recommendations', function (): void {
    app(RanetracePauseManager::class)->setGlobalPause(900, '401');

    expect(statusOutput())
        ->toContain('• Check API credentials (401 indicates invalid/revoked key)')
        ->toContain('• Run: php artisan ranetrace:pause-clear --global');
});

test('the --json output carries the feature pause without the guidance and stays healthy', function (): void {
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');

    $output = statusOutput(['--json' => true]);
    $status = json_decode(mb_trim($output), true);

    expect($status['healthy'])->toBeTrue()
        ->and($status['pauses']['features']['errors'])->toMatchArray(['paused' => true, 'reason' => '429'])
        ->and($output)->not->toContain('Rate limited');
});
