<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Facades\RanetraceEvents;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Php\Events\EventLimits;

test('RanetraceEvents facade is available', function (): void {
    expect(class_exists(RanetraceEvents::class))->toBeTrue();
});

test('product added to cart helper works', function (): void {
    Queue::fake();

    RanetraceEvents::productAddedToCart(
        productId: 'PROD-123',
        productName: 'Test Product',
        price: 29.99,
        quantity: 2,
        category: 'Electronics'
    );

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'product_added_to_cart'
            && $eventData['properties']['product_id'] === 'PROD-123'
            && $eventData['properties']['price'] === 29.99
            && $eventData['properties']['quantity'] === 2;
    });
});

test('sale helper works', function (): void {
    Queue::fake();

    RanetraceEvents::sale(
        orderId: 'ORDER-456',
        totalAmount: 89.97,
        products: [
            ['id' => 'PROD-1', 'name' => 'Product 1', 'price' => 29.99],
            ['id' => 'PROD-2', 'name' => 'Product 2', 'price' => 59.98],
        ],
        currency: 'USD'
    );

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'sale'
            && $eventData['properties']['order_id'] === 'ORDER-456'
            && $eventData['properties']['total_amount'] === 89.97
            && count($eventData['properties']['products']) === 2;
    });
});

/**
 * The events endpoint refuses an event over 5,120 bytes, and refused is final.
 * A sale has no cap of its own: the event-wide cut replaces the largest
 * property, so the products list goes and the figures that say what was sold
 * arrive.
 */
test('a sale with a products list too large for the endpoint is queued with its order, total, currency and count', function (): void {
    Queue::fake();

    $products = array_map(static fn (int $index): array => [
        'id' => "PROD-{$index}",
        'name' => "Organic cotton t-shirt, size M, colour number {$index}",
        'sku' => "TS-ORG-M-{$index}",
        'category' => 'Clothing',
        'price' => 29.99,
        'quantity' => 2,
    ], range(1, 40));

    expect(mb_strlen((string) json_encode($products), '8bit'))->toBeGreaterThan(EventLimits::MAX_ITEM_BYTES);

    RanetraceEvents::sale(orderId: 'ORD-4821', totalAmount: 2399.20, products: $products, currency: 'EUR');

    $eventData = Queue::pushed(HandleEventJob::class)->first()->getEventData();

    expect($eventData['properties'])->toBe([
        'order_id' => 'ORD-4821',
        'total_amount' => 2399.20,
        'currency' => 'EUR',
        'products' => ['_truncated' => EventLimits::PROPERTY_REPLACED_REASON],
        'product_count' => 40,
    ])
        ->and(mb_strlen((string) json_encode($eventData), '8bit'))->toBeLessThanOrEqual(EventLimits::MAX_ITEM_BYTES);
});

test('user registered helper works', function (): void {
    Queue::fake();

    RanetraceEvents::userRegistered(
        userId: 123,
        additionalProperties: ['source' => 'website']
    );

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'user_registered'
            && $eventData['user']['id'] === 123
            && $eventData['properties']['source'] === 'website';
    });
});

test('user logged in helper works', function (): void {
    Queue::fake();

    RanetraceEvents::userLoggedIn(userId: 456);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'user_logged_in'
            && $eventData['user']['id'] === 456;
    });
});

test('page view helper works', function (): void {
    Queue::fake();

    RanetraceEvents::pageView('Pricing Page', ['variant' => 'A']);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'page_view'
            && $eventData['properties']['page_name'] === 'Pricing Page'
            && $eventData['properties']['variant'] === 'A';
    });
});

test('custom event helper validates event name', function (): void {
    expect(fn () => RanetraceEvents::custom('Invalid Name!', []))
        ->toThrow(InvalidArgumentException::class);
});

test('custom event helper works with valid name', function (): void {
    Queue::fake();

    RanetraceEvents::custom('newsletter_signup', ['source' => 'footer']);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'newsletter_signup'
            && $eventData['properties']['source'] === 'footer';
    });
});

test('custom unsafe helper bypasses validation', function (): void {
    Queue::fake();

    // Should not throw exception
    RanetraceEvents::customUnsafe('Invalid Event Name!', ['test' => true]);

    Queue::assertPushed(HandleEventJob::class);
});
