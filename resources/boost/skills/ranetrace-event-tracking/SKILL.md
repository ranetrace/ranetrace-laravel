---
name: ranetrace-event-tracking
description: Track custom application events like sales, signups, and user actions with Ranetrace's privacy-first event system.
---

# Ranetrace event tracking

## When to use this skill

Use this skill when implementing custom event tracking for analytics, e-commerce metrics, user behavior tracking, or any application-specific events.

## Facades

- `Ranetrace::trackEvent(string $eventName, array $properties = [], int|string|null $userId = null, bool $validate = true)`: low-level tracking (`$userId` accepts int or string for UUID models; `$validate: false` skips snake_case name validation)
- `RanetraceEvents`: convenience methods with built-in validation

## Standard event constants

The `EventTracker` class provides constants for common events:

```php
use Ranetrace\Laravel\Events\EventTracker;

EventTracker::PRODUCT_ADDED_TO_CART  // 'product_added_to_cart'
EventTracker::PRODUCT_REMOVED_FROM_CART
EventTracker::CART_VIEWED
EventTracker::CHECKOUT_STARTED
EventTracker::CHECKOUT_COMPLETED
EventTracker::SALE                    // 'sale'
EventTracker::USER_REGISTERED         // 'user_registered'
EventTracker::USER_LOGGED_IN
EventTracker::USER_LOGGED_OUT
EventTracker::PAGE_VIEW
EventTracker::SEARCH
EventTracker::NEWSLETTER_SIGNUP
EventTracker::CONTACT_FORM_SUBMITTED
```

## Convenience methods

### E-commerce

```php
use Ranetrace\Laravel\Facades\RanetraceEvents;

// Track a sale
RanetraceEvents::sale(
    orderId: 'ORD-123',
    totalAmount: 99.99,
    products: [['id' => 'PROD-1', 'name' => 'Widget', 'price' => 49.99]],
    currency: 'EUR',
);

// Track product added to cart
RanetraceEvents::productAddedToCart(
    productId: 'PROD-1',
    productName: 'Widget',
    price: 49.99,
    quantity: 2,
    category: 'Electronics',
);
```

### User actions

```php
// Track user registration (auto-detects authenticated user if $userId is null)
RanetraceEvents::userRegistered(userId: $user->id);

// Track user login
RanetraceEvents::userLoggedIn();

// Track a specific page view (distinct from website analytics)
RanetraceEvents::pageView('pricing-page');
```

### Custom events

```php
// Custom event with validation (enforces naming rules)
RanetraceEvents::custom('feature_toggled', [
    'feature' => 'dark_mode',
    'enabled' => true,
]);

// Custom event without validation (advanced use only)
RanetraceEvents::customUnsafe('My.Custom.Event', $properties);
```

## Event naming rules

Event names are validated by default and must:
- Use `snake_case` format (lowercase with underscores)
- Be 3-50 characters long
- Start with a letter
- Only contain letters, numbers, and underscores

An invalid name throws an `InvalidArgumentException`. `RanetraceEvents::custom()` and `Ranetrace::trackEvent()` both check on every call, whether or not capture is on, so a bad name fails locally rather than first in production. `validate: false` on `trackEvent()` skips the check.

Valid: `user_registered`, `checkout_completed`, `feature_toggled`
Invalid: `UserRegistered`, `a`, `123_event`, `my-event`

## Configuration

```php
// config/ranetrace.php
'events' => [
    'enabled' => env('RANETRACE_EVENTS_ENABLED', true),
    'queue' => env('RANETRACE_EVENTS_QUEUE', true),
    'queue_name' => env('RANETRACE_EVENTS_QUEUE_NAME', null), // unset: the connection's default queue
    'timeout' => env('RANETRACE_EVENTS_TIMEOUT', 10),
],
```

Keep `RANETRACE_EVENTS_TIMEOUT` at 8 seconds or more. The server takes up to 8 seconds over a batch, and a client that gives up sooner sends a batch again that the server already stored.

## What gets captured

Each event includes:
- Event name and custom properties
- Authenticated user (auto-detected or explicitly provided)
- Timestamp
- Current URL
- User agent hash and session ID hash (privacy-first, no raw values stored)
- An id of its own, made when the event is tracked. Ranetrace stores an event once even when the batch it travels in is sent twice, and stores two events alike in every other field as two. So tracking the same event twice records it twice

An event over the events endpoint's size limits is cut before it is queued, so it arrives instead of being refused: a top-level string property over 500 bytes is cut with a `... (truncated)` suffix, and an event over 5,120 bytes JSON-encoded loses its largest top-level properties first to a `_truncated` marker, so a sale with a long products list arrives with its order id, total, currency and product count and without the list. Each cut writes a warning naming the event and its properties to the `ranetrace_internal` log channel.

## Testing

```bash
php artisan ranetrace:test-events
```
