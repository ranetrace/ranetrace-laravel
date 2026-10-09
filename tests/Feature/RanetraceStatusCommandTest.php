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
        ->expectsOutputToContain('✓ Overall status: healthy')
        ->assertSuccessful();
});

test('status reports unhealthy when a buffer is near its own capacity', function (): void {
    Config::set('ranetrace.batch.max_buffer_size', 10);

    $buffer = app(RanetraceBatchBuffer::class);
    for ($i = 0; $i < 8; $i++) {
        $buffer->addItem('events', ['event_name' => "e{$i}"]);
    }

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('✗ Overall status: issues detected')
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
        ->expectsOutputToContain('Ingest API key: Configured')
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
        ->expectsOutputToContain('errors               Paused (reason: 429, remaining: ')
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

test('the status output has no all-caps words besides acronyms', function (): void {
    $acronyms = ['API', 'JSON', 'MCP'];

    $healthy = statusOutput();

    Config::set('ranetrace.key', null);
    Config::set('ranetrace.batch.max_buffer_size', 10);
    $buffer = app(RanetraceBatchBuffer::class);
    for ($i = 0; $i < 8; $i++) {
        $buffer->addItem('events', ['event_name' => "e{$i}"]);
    }
    $pauseManager = app(RanetracePauseManager::class);
    $pauseManager->setGlobalPause(900, '401');
    $pauseManager->setFeaturePause('errors', 900, '429');
    $troubled = statusOutput();

    expect($troubled)->toContain('Recommendations');

    foreach ([$healthy, $troubled] as $output) {
        preg_match_all('/(?<!\w)[A-Z]{3,}(?!\w)/u', $output, $matches);

        expect(array_diff($matches[0], $acronyms))->toBe([]);
    }
});

test('an enabled app with no ingest key is reported as unhealthy, with the key recommendation once', function (): void {
    Config::set('ranetrace.key', null);

    $output = statusOutput();

    expect($output)->toContain('✗ Overall status: issues detected')
        ->and($output)->toContain('Ingest API key: Not configured')
        ->and($output)->toContain('Recommendations')
        ->and(mb_substr_count($output, '• Configure RANETRACE_KEY in .env (the ingest key; the MCP tools use an OAuth connection, held by your MCP client)'))->toBe(1)
        ->and($output)->not->toContain('RANETRACE_ENABLED');
});

test('the --json output reports an enabled app with no ingest key as unhealthy', function (): void {
    Config::set('ranetrace.key', null);

    $status = json_decode(mb_trim(statusOutput(['--json' => true])), true);

    expect($status['healthy'])->toBeFalse()
        ->and($status['config']['enabled'])->toBeTrue()
        ->and($status['config']['api_key_configured'])->toBeFalse();
});

test('an enabled app with an ingest key stays healthy and prints no enable hint', function (): void {
    $output = statusOutput();

    expect($output)->toContain('✓ Overall status: healthy')
        ->and($output)->toContain('Enabled: Yes')
        ->and($output)->not->toContain('→')
        ->and($output)->not->toContain('Recommendations')
        ->and(json_decode(mb_trim(statusOutput(['--json' => true])), true)['healthy'])->toBeTrue();
});

test('a disabled app is healthy and shows how to turn it on under the Enabled row', function (?string $key): void {
    Config::set('ranetrace.enabled', false);
    Config::set('ranetrace.key', $key);

    $output = statusOutput();
    $lines = explode("\n", $output);
    $enabledIndex = array_search('Enabled: No', $lines, true);

    expect($output)->toContain('✓ Overall status: healthy')
        ->and($enabledIndex)->not->toBeFalse()
        ->and($lines[$enabledIndex + 1])->toBe('  → Nothing is captured or sent. Set RANETRACE_ENABLED=true to turn it on')
        ->and(mb_substr_count($output, 'RANETRACE_ENABLED'))->toBe(1)
        ->and($output)->not->toContain('Enable Ranetrace')
        ->and($output)->not->toContain('Recommendations')
        ->and(json_decode(mb_trim(statusOutput(['--json' => true])), true)['healthy'])->toBeTrue();
})->with([
    'without a key' => [null],
    'with a key' => ['ingest-key'],
]);

test('a disabled app lists neither the enable hint nor the key in Recommendations when something else is wrong', function (): void {
    Config::set('ranetrace.enabled', false);
    Config::set('ranetrace.key', null);
    app(RanetracePauseManager::class)->setGlobalPause(900, '401');

    $output = statusOutput();

    expect($output)->toContain('✗ Overall status: issues detected')
        ->and($output)->toContain('Recommendations')
        ->and(mb_substr_count($output, 'RANETRACE_ENABLED'))->toBe(1)
        ->and($output)->not->toContain('Enable Ranetrace')
        ->and($output)->not->toContain('Configure RANETRACE_KEY');
});

test('a blank ingest key counts as missing', function (string $key): void {
    Config::set('ranetrace.key', $key);

    $output = statusOutput();

    expect($output)->toContain('✗ Overall status: issues detected')
        ->and($output)->toContain('Ingest API key: Not configured')
        ->and($output)->toContain('• Configure RANETRACE_KEY in .env');
})->with([
    'empty' => [''],
    'a space' => [' '],
    'whitespace' => ["\t\n "],
]);

test('an enabled flag left as a string by env() decides health by its truthiness', function (string $enabled, bool $isEnabled): void {
    Config::set('ranetrace.enabled', $enabled);
    Config::set('ranetrace.key', null);

    $status = json_decode(mb_trim(statusOutput(['--json' => true])), true);

    expect($status['config']['enabled'])->toBe($isEnabled)
        ->and($status['healthy'])->toBe(! $isEnabled);
})->with([
    'zero' => ['0', false],
    'one' => ['1', true],
]);
