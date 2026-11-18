<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Ranetrace\Laravel\Analytics\VisitDataCollector;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Tests\Contract\ContractFields;
use Ranetrace\Php\Contract\WireContract;

/**
 * The page visit is the one item only this package sends, and its fixture
 * (`contract/items/page_visits.json` in `ranetrace/ranetrace-php`) is
 * transcribed from the endpoint's validator. The endpoint refuses a visit past
 * one of its limits, and a refused visit is lost, so the collector's cuts are
 * held to the fixture's numbers here rather than to a copy of them.
 */
beforeEach(function (): void {
    config([
        // Capture synchronously, so the visit is in the buffer by the time
        // the assertion reads it.
        'ranetrace.website_analytics.queue' => false,
    ]);
});

/**
 * A value of exactly $length characters that the collector reads back
 * unchanged from the place $field comes from.
 */
function contractVisitValueOfLength(string $field, int $length): string
{
    return match ($field) {
        'path' => '/'.str_repeat('a', $length - 1),
        'referrer' => 'https://a.test/'.str_repeat('a', $length - 15),
        default => str_repeat('a', $length),
    };
}

/**
 * The visit the collector builds for a request that carries $value where
 * $field is read from: the URL, the Referer header or the query string.
 *
 * @return array<string, mixed>
 */
function contractVisitCollectedWith(string $field, string $value): array
{
    return VisitDataCollector::collect(match ($field) {
        'path' => Request::create($value),
        'referrer' => Request::create('/', server: ['HTTP_REFERER' => $value]),
        default => Request::create('/', parameters: [$field => $value]),
    });
}

test('a visit string at its endpoint limit is sent whole and one past it is cut to the limit', function (string $field): void {
    $max = WireContract::item('page_visits')['fields'][$field]['max'];

    $atLimit = contractVisitValueOfLength($field, $max);
    $pastLimit = contractVisitCollectedWith($field, contractVisitValueOfLength($field, $max + 1))[$field];

    expect(contractVisitCollectedWith($field, $atLimit)[$field])->toBe($atLimit)
        ->and($pastLimit)->toBe($atLimit);
})->with(['path', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']);

test('a tracked visit carries only fields the endpoint declares, and every one it requires', function (): void {
    // A browser old enough that the middleware does not ask it for client
    // hints, with the headers it takes for a person's.
    $this->withHeaders(humanBrowserHeaders() + [
        'Referer' => 'https://news.example.com/monitoring-roundup',
    ])->get('/test-page?utm_source=newsletter')->assertOk();

    $visit = (new RanetraceBatchBuffer)->getItems('page_visits', 1)[0]['data'] ?? [];
    $fields = WireContract::item('page_visits')['fields'];
    $missing = [];

    foreach (ContractFields::requiredTopLevelKeys($fields) as $field) {
        if (! array_key_exists($field, $visit) || $visit[$field] === null) {
            $missing[] = $field;
        }
    }

    expect($visit)->not->toBe([])
        ->and(array_diff(array_keys($visit), ContractFields::topLevelKeys($fields)))->toBe([])
        ->and($missing)->toBe([]);
});

test('the page visit job allow-lists the fields its endpoint declares, without the url it stopped sending', function (): void {
    $allowed = (new ReflectionMethod(HandlePageVisitJob::class, 'getAllowedKeys'))->invoke(new HandlePageVisitJob([]));
    $declared = ContractFields::topLevelKeys(WireContract::item('page_visits')['fields']);

    expect($allowed)->toEqualCanonicalizing(array_values(array_diff($declared, ['url'])));
});
