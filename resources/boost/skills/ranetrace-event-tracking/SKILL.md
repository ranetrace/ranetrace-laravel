---
name: ranetrace-event-tracking
description: Track custom application events like sales, signups, and user actions with Ranetrace's privacy-first event system.
---

# Ranetrace Event Tracking

## When to use this skill

Use this skill when implementing custom event tracking for analytics, e-commerce metrics, user behavior tracking, or any application-specific events.

## Facades

- `Ranetrace::trackEvent(string $eventName, array $properties = [], ?int $userId = null)` — low-level tracking
- `RanetraceEvents` — convenience methods with built-in validation

## Standard Event Constants

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

## Convenience Methods

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

### User Actions

```php
// Track user registration (auto-detects authenticated user if $userId is null)
RanetraceEvents::userRegistered(userId: $user->id);

// Track user login
RanetraceEvents::userLoggedIn();

// Track a specific page view (distinct from website analytics)
RanetraceEvents::pageView('pricing-page');
```

### Custom Events

```php
// Custom event with validation (enforces naming rules)
RanetraceEvents::custom('feature_toggled', [
    'feature' => 'dark_mode',
    'enabled' => true,
]);

// Custom event without validation (advanced use only)
RanetraceEvents::customUnsafe('My.Custom.Event', $properties);
```

## Event Naming Rules

Event names are validated by default and must:
- Use `snake_case` format (lowercase with underscores)
- Be 3-50 characters long
- Start with a letter
- Only contain letters, numbers, and underscores

Valid: `user_registered`, `checkout_completed`, `feature_toggled`
Invalid: `UserRegistered`, `a`, `123_event`, `my-event`

## Configuration

```php
// config/ranetrace.php
'events' => [
    'enabled' => env('RANETRACE_EVENTS_ENABLED', true),
    'queue' => env('RANETRACE_EVENTS_QUEUE', true),
    'queue_name' => env('RANETRACE_EVENTS_QUEUE_NAME', 'default'),
    'timeout' => env('RANETRACE_EVENTS_TIMEOUT', 10),
],
```

## What Gets Captured

Each event includes:
- Event name and custom properties
- Authenticated user (auto-detected or explicitly provided)
- Timestamp
- Current URL
- User agent hash and session ID hash (privacy-first, no raw values stored)

## Testing

```bash
php artisan ranetrace:test-events
```
