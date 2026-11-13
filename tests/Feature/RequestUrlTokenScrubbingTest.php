<?php

declare(strict_types=1);

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ranetrace\Laravel\Facades\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

/**
 * A notification channel that fails wraps the exception of its HTTP client,
 * whose message holds the request URL. For a Telegram bot that URL holds the
 * bot token in its path, and for a Slack or Discord webhook the URL is the
 * credential. The failed job is reported as an error and logged with its
 * exception, so both capture paths are followed here, inline into the array
 * buffer.
 */
beforeEach(function (): void {
    config([
        'ranetrace.batch.cache_driver' => 'array',
        'ranetrace.errors.queue' => false,
        'ranetrace.logging.enabled' => true,
        'ranetrace.logging.queue' => false,
        'logging.channels.ranetrace' => [
            'driver' => 'ranetrace',
            'level' => 'debug',
        ],
    ]);

    Cache::store('array')->flush();
});

/**
 * The one item of $type the capture path buffered.
 *
 * @return array<string, mixed>
 */
function bufferedItemOfFailedRequest(string $type): array
{
    $items = (new RanetraceBatchBuffer)->getItems($type, 10);

    expect($items)->toHaveCount(1);

    return $items[0]['data'];
}

/**
 * The secret half of a Telegram bot token, with made-up characters. It is
 * joined here so that this file holds no string a secret scanner would flag.
 */
function telegramTokenSecret(): string
{
    return implode('', ['AAF4k3T0ken', 'F4k3T0kenF4k3T0ken_F4k-3']);
}

/**
 * What a failed Telegram notification throws: the channel's exception around
 * the HTTP client's, both naming the request URL.
 */
function failedTelegramNotification(): RuntimeException
{
    $url = 'https://api.telegram.org/bot123456789:'.telegramTokenSecret().'/sendMessage';

    return new RuntimeException(
        'The communication with Telegram failed. `Client error: `POST '.$url.'` resulted in a `401 Unauthorized` response`',
        0,
        new LogicException('cURL error 28: Operation timed out for '.$url),
    );
}

test('a failed telegram notification is reported without the bot token, in its message and its previous exception', function (): void {
    $handler = new Handler(app());
    Ranetrace::handles(new Exceptions($handler));

    $handler->report(failedTelegramNotification());

    $item = bufferedItemOfFailedRequest('errors');

    expect($item['message'])->toBe('The communication with Telegram failed. `Client error: `POST https://api.telegram.org/bot[REDACTED]/sendMessage` resulted in a `401 Unauthorized` response`')
        ->and($item['previous'][0]['message'])->toBe('cURL error 28: Operation timed out for https://api.telegram.org/bot[REDACTED]/sendMessage')
        ->and((string) json_encode($item))->not->toContain(telegramTokenSecret());
});

test('a failed telegram notification logged with its exception reaches the log buffer without the bot token', function (): void {
    $exception = failedTelegramNotification();

    Log::channel('ranetrace')->error($exception->getMessage(), ['exception' => $exception]);

    $item = bufferedItemOfFailedRequest('logs');

    expect($item['message'])->toContain('https://api.telegram.org/bot[REDACTED]/sendMessage')
        ->and((string) json_encode($item))->toContain('cURL error 28: Operation timed out for https:\/\/api.telegram.org\/bot[REDACTED]\/sendMessage')
        ->not->toContain(telegramTokenSecret());
});

test('a failed webhook request is reported without the secret part of its url', function (string $url, string $expected, string $secret): void {
    Ranetrace::report(new RuntimeException('Client error: `POST '.$url.'` resulted in a `404 Not Found` response'));

    $item = bufferedItemOfFailedRequest('errors');

    expect($item['message'])->toBe('Client error: `POST '.$expected.'` resulted in a `404 Not Found` response')
        ->and((string) json_encode($item))->not->toContain($secret);
})->with([
    // Joined from two parts, so that this file holds no string a secret
    // scanner takes for a live webhook.
    'slack' => [
        'https://hooks.slack.com/services/'.'T00000000/B00000000/F4k3F4k3F4k3F4k3F4k3F4k3',
        'https://hooks.slack.com/services/[REDACTED]',
        'F4k3F4k3F4k3F4k3F4k3F4k3',
    ],
    'discord' => [
        'https://discord.com/api/webhooks/123456789012345678/F4k3-F4k3_F4k3F4k3F4k3F4k3F4k3F4k3',
        'https://discord.com/api/webhooks/123456789012345678/[REDACTED]',
        'F4k3-F4k3_F4k3F4k3F4k3F4k3F4k3F4k3',
    ],
]);
