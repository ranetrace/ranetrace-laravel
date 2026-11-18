<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Events\EventTracker;
use Ranetrace\Laravel\Facades\Ranetrace;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\Core;
use Ranetrace\Php\Events\EventLimits;

test('it validates event names correctly', function (): void {
    // Valid event names
    expect(EventTracker::validateEventName('user_registered'))->toBeTrue();
    expect(EventTracker::validateEventName('product_added_to_cart'))->toBeTrue();
    expect(EventTracker::validateEventName('abc'))->toBeTrue(); // Minimum 3 chars
    expect(EventTracker::validateEventName('a_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r_s_t_u_v_w_x_y1'))->toBeTrue(); // Exactly 50 chars

    // Invalid event names
    expect(EventTracker::validateEventName('ab'))->toBeFalse(); // Too short
    expect(EventTracker::validateEventName('User Registered'))->toBeFalse(); // Contains spaces
    expect(EventTracker::validateEventName('user-registered'))->toBeFalse(); // Contains dashes
    expect(EventTracker::validateEventName('123_event'))->toBeFalse(); // Starts with number
    expect(EventTracker::validateEventName('user@registered'))->toBeFalse(); // Contains special char
    expect(EventTracker::validateEventName(str_repeat('a', 51)))->toBeFalse(); // Too long
});

test('it throws exception for invalid event names when validation is enabled', function (): void {
    expect(fn () => EventTracker::ensureValidEventName('Invalid Event!'))
        ->toThrow(InvalidArgumentException::class);
});

test('it tracks events successfully with validation', function (): void {
    Queue::fake();

    Ranetrace::trackEvent('user_registered', ['source' => 'test']);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'user_registered'
            && $eventData['properties']['source'] === 'test';
    });
});

test('it tracks events without validation when disabled', function (): void {
    Queue::fake();

    Ranetrace::trackEvent('Invalid Event Name!', ['test' => true], null, false);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'Invalid Event Name!';
    });
});

/**
 * What an HTTP request says about an event tracked in it: the URL, the user
 * agent hash, the session hash and the path secret resolver.
 *
 * The suite runs under the CLI SAPI, so `runningInConsole()` is true and the
 * ordinary path leaves all four out. The console flag is therefore stated
 * rather than detected, which is the only way to reach the request branch.
 *
 * @return array{0: string|null, 1: string|null, 2: string|null, 3: callable|null}
 */
function eventRequestContext(Request $request): array
{
    $ranetrace = new \Ranetrace\Laravel\Ranetrace;

    return new ReflectionMethod($ranetrace, 'eventRequestContext')->invoke($ranetrace, $request, false);
}

test('an event tracked in a request carries its url and the hashes of its user agent and session', function (): void {
    $this->travelTo('2026-11-13 10:00:00');

    $request = Request::create('https://app.test/checkout?step=2', server: [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 Test Browser',
        'REMOTE_ADDR' => '203.0.113.10',
    ]);

    [$url, $userAgentHash, $sessionIdHash] = eventRequestContext($request);
    $fingerprints = Core::fingerprints();

    expect($url)->toBe('https://app.test/checkout?step=2')
        ->and($userAgentHash)->toBe($fingerprints->generateUserAgentHash('Mozilla/5.0 Test Browser'))
        ->and($userAgentHash)->toHaveLength(64)
        ->and($sessionIdHash)->toBe($fingerprints->generateSessionIdHash('203.0.113.10', 'Mozilla/5.0 Test Browser', '2026-11-13'))
        ->and($sessionIdHash)->toHaveLength(64);
});

test('an event tracked in a request without a user agent carries a null user agent hash, not an empty string, and still a session hash', function (): void {
    $request = Request::create('/');
    $request->headers->remove('User-Agent');

    [, $userAgentHash, $sessionIdHash] = eventRequestContext($request);

    expect($userAgentHash)->toBeNull()
        ->and($sessionIdHash)->toHaveLength(64);
});

/**
 * Laravel hands a console process a request whose user agent is `Symfony` and
 * whose address is `127.0.0.1`. Hashing those would give every event of every
 * command, scheduled task and queued job one session, which the backend
 * counts as one visitor.
 */
test('an event tracked from a console process carries no url, no user agent hash and no session hash', function (): void {
    Queue::fake();

    expect(request()->userAgent())->toBe('Symfony');

    Ranetrace::trackEvent('invoice_sent', []);

    Queue::assertPushed(HandleEventJob::class, function (HandleEventJob $job): bool {
        $buffered = (array) $job->bufferedItem();

        return array_key_exists('url', $buffered) && $buffered['url'] === null
            && array_key_exists('user_agent_hash', $buffered) && $buffered['user_agent_hash'] === null
            && array_key_exists('session_id_hash', $buffered) && $buffered['session_id_hash'] === null;
    });
});

/**
 * The API reads two events with one id as one event sent twice. The id is made
 * when the event is tracked, before the queue, so a job the queue runs twice
 * buffers one id twice and the API keeps one event, while two calls alike in
 * every field are two events.
 */
test('two events tracked alike in every field each carry their own uuid', function (): void {
    Queue::fake();

    Ranetrace::trackEvent('order_shipped');
    Ranetrace::trackEvent('order_shipped');

    $ids = Queue::pushed(HandleEventJob::class)
        ->map(fn (HandleEventJob $job): mixed => ((array) $job->bufferedItem())['id'] ?? null)
        ->all();

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toMatch(UUID_V4_PATTERN)
        ->and($ids[1])->toMatch(UUID_V4_PATTERN)
        ->and($ids[1])->not->toBe($ids[0]);
});

test('an event job that runs twice buffers the event with one id both times', function (): void {
    Queue::fake();

    Ranetrace::trackEvent('order_shipped');

    $job = Queue::pushed(HandleEventJob::class)->first();
    $buffer = app(RanetraceBatchBuffer::class);

    $job->handle($buffer);
    $job->handle($buffer);

    $buffered = $buffer->getItems('events', 10);

    expect($buffered)->toHaveCount(2)
        ->and($buffered[0]['data']['id'])->toBeString()->not->toBe('')
        ->and($buffered[1]['data']['id'])->toBe($buffered[0]['data']['id'])
        ->and($buffered[1]['id'])->not->toBe($buffered[0]['id']);
});

test('it includes authenticated user id when available', function (): void {
    Queue::fake();

    $user = new class implements Illuminate\Contracts\Auth\Authenticatable
    {
        public int $id = 123;

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }
    };

    $this->actingAs($user);

    Ranetrace::trackEvent('test_event', []);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return isset($eventData['user']['id'])
            && $eventData['user']['id'] === 123;
    });
});

test('it allows explicit user id override', function (): void {
    Queue::fake();

    Ranetrace::trackEvent('test_event', [], 456);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['user']['id'] === 456;
    });
});

test('an auth guard that throws costs the event its user, never the event', function (): void {
    Queue::fake();
    $logger = spyOnInternalLog();
    useThrowingAuthGuard('Session store unreachable');

    Ranetrace::trackEvent('test_event', ['plan' => 'pro']);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['user'] === null
            && $eventData['properties'] === ['plan' => 'pro'];
    });

    $logger->shouldHaveReceived('warning')->once()
        ->with('Ranetrace user resolver failed', ['exception' => 'Session store unreachable']);
    $logger->shouldNotHaveReceived('error');
});

/**
 * The cut runs before the queue, so the job carries the small event and a
 * queue that serializes its payload never holds the oversize one.
 */
test('an event over the endpoint limits is queued cut, and the cut is logged to the internal channel once', function (): void {
    Queue::fake();
    $logger = spyOnInternalLog();

    Ranetrace::trackEvent('checkout_started', [
        'note' => str_repeat('p', 100_000),
        'cart' => array_map(static fn (int $index): array => ['sku' => "SKU-{$index}", 'name' => "Product {$index}"], range(1, 200)),
    ]);

    $eventData = Queue::pushed(HandleEventJob::class)->first()->getEventData();

    expect($eventData['properties'])->toBe([
        'note' => str_repeat('p', 485).'... (truncated)',
        'cart' => ['_truncated' => EventLimits::PROPERTY_REPLACED_REASON],
    ])
        ->and(mb_strlen((string) json_encode($eventData), '8bit'))->toBeLessThanOrEqual(EventLimits::MAX_ITEM_BYTES);

    $logger->shouldHaveReceived('warning')->once()
        ->with('Event exceeded the events endpoint limits and was cut', [
            'event_name' => 'checkout_started',
            'cut_properties' => ['note'],
            'max_property_value_bytes' => EventLimits::MAX_PROPERTY_VALUE_BYTES,
            'replaced_properties' => ['cart'],
            'max_item_bytes' => EventLimits::MAX_ITEM_BYTES,
        ]);
});

test('an event the job buffers is the cut one, and the per-item budget leaves it alone', function (): void {
    Queue::fake();

    Ranetrace::trackEvent('checkout_started', ['cart' => range(1, 2_000)]);

    $job = Queue::pushed(HandleEventJob::class)->first();
    $job->handle(app(RanetraceBatchBuffer::class));

    $buffered = app(RanetraceBatchBuffer::class)->getItems('events', 10);

    expect($buffered)->toHaveCount(1)
        ->and($buffered[0]['data'])->toBe($job->getEventData())
        ->and($buffered[0]['data']['properties'])->toBe(['cart' => ['_truncated' => EventLimits::PROPERTY_REPLACED_REASON]]);
});

/**
 * A console process has no URL, so a real event cannot be made too large for
 * the whole-properties fallback here; the drop itself is the shared core's
 * and tested there. What is this SDK's own is that a dropped event queues
 * nothing and throws nothing.
 */
test('an event the limits drop is not queued and does not throw', function (): void {
    Queue::fake();

    $ranetrace = new class extends \Ranetrace\Laravel\Ranetrace
    {
        public function buildEventPayload(string $eventName, array $properties = [], int|string|null $userId = null): ?array
        {
            return null;
        }
    };

    expect(fn () => $ranetrace->trackEvent('search_performed'))->not->toThrow(Throwable::class);

    Queue::assertNothingPushed();
});

test('event constants have valid names', function (): void {
    $constants = [
        EventTracker::PRODUCT_ADDED_TO_CART,
        EventTracker::PRODUCT_REMOVED_FROM_CART,
        EventTracker::CART_VIEWED,
        EventTracker::CHECKOUT_STARTED,
        EventTracker::CHECKOUT_COMPLETED,
        EventTracker::SALE,
        EventTracker::USER_REGISTERED,
        EventTracker::USER_LOGGED_IN,
        EventTracker::USER_LOGGED_OUT,
        EventTracker::PAGE_VIEW,
        EventTracker::SEARCH,
        EventTracker::NEWSLETTER_SIGNUP,
        EventTracker::CONTACT_FORM_SUBMITTED,
    ];

    foreach ($constants as $constant) {
        expect(EventTracker::validateEventName($constant))->toBeTrue();
    }
});

test('it does not track events when disabled in config', function (): void {
    Queue::fake();

    config(['ranetrace.events.enabled' => false]);

    Ranetrace::trackEvent('test_event', []);

    Queue::assertNothingPushed();
});
