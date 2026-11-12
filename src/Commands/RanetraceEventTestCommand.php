<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Ranetrace\Laravel\Events\EventTracker;
use Ranetrace\Laravel\Facades\Ranetrace;
use Ranetrace\Laravel\Facades\RanetraceEvents;
use Ranetrace\Laravel\Support\BatchConfig;
use Ranetrace\Laravel\Support\GuardsFeatureTest;

class RanetraceEventTestCommand extends Command
{
    use GuardsFeatureTest;

    /**
     * The events the steps below track, the invalid name aside, which is
     * refused before capture.
     */
    private const int TEST_COUNT = 8;

    protected $signature = 'ranetrace:test-events';

    protected $description = 'Test Ranetrace event tracking functionality';

    public function handle(): int
    {
        $this->info('Testing Ranetrace event tracking...');

        $stop = $this->stopBeforeCapture('events', function (): void {
            $this->warn('⚠ Ranetrace event tracking is disabled. Set RANETRACE_EVENTS_ENABLED=true in your .env file');
            $this->info('Configuration check completed.');
        });

        if ($stop !== null) {
            return $stop;
        }

        // Test event name validation
        $this->info('1. Testing event name validation...');

        $this->info('   ✓ Valid event name: user_registered');
        RanetraceEvents::custom('user_registered', ['source' => 'test']);

        $this->info('   ✓ Using predefined constant: EventTracker::PRODUCT_ADDED_TO_CART');
        Ranetrace::trackEvent(EventTracker::PRODUCT_ADDED_TO_CART, ['test' => true]);

        $this->info('   ⚠ Testing invalid event name (this will show validation error)...');
        try {
            // Direct call (not via the RanetraceEvents facade) so static
            // analysis can see the InvalidArgumentException throw path.
            EventTracker::custom('Invalid Event Name!', []);
        } catch (InvalidArgumentException $e) {
            $this->warn('   Expected validation error: '.$e->getMessage());
        }

        // Test basic event tracking
        $this->info('2. Tracking basic custom event...');
        Ranetrace::trackEvent('test_event', [
            'test_property' => 'test_value',
            'timestamp' => now()->toISOString(),
        ]);

        // Test e-commerce events using the helper
        $this->info('3. Tracking product added to cart event...');
        RanetraceEvents::productAddedToCart(
            productId: 'PROD-123',
            productName: 'Awesome Widget',
            price: 29.99,
            quantity: 2,
            category: 'Widgets',
            additionalProperties: ['color' => 'blue', 'size' => 'large']
        );

        $this->info('4. Tracking sale event...');
        RanetraceEvents::sale(
            orderId: 'ORDER-456',
            totalAmount: 89.97,
            products: [
                [
                    'id' => 'PROD-123',
                    'name' => 'Awesome Widget',
                    'price' => 29.99,
                    'quantity' => 2,
                ],
                [
                    'id' => 'PROD-789',
                    'name' => 'Super Gadget',
                    'price' => 29.99,
                    'quantity' => 1,
                ],
            ],
            currency: 'USD',
            additionalProperties: ['payment_method' => 'credit_card']
        );

        $this->info('5. Tracking user registration event...');
        RanetraceEvents::userRegistered(
            userId: 123,
            additionalProperties: ['registration_source' => 'website']
        );

        $this->info('6. Tracking page view event...');
        RanetraceEvents::pageView(
            pageName: 'Product Details',
            additionalProperties: ['product_id' => 'PROD-123']
        );

        $this->info('7. Tracking custom event using validated method...');
        RanetraceEvents::custom(
            eventName: 'newsletter_signup',
            properties: ['source' => 'footer', 'email_provided' => true],
            userId: 123
        );

        $this->newLine();
        $this->reportCaptured('events', self::TEST_COUNT.' test events');
        $this->newLine();
        $this->info('Check your Ranetrace dashboard once the events have been sent.');

        $this->newLine();
        $this->info('Available event constants:');
        $this->table(
            ['Constant', 'Value'],
            [
                ['EventTracker::PRODUCT_ADDED_TO_CART', EventTracker::PRODUCT_ADDED_TO_CART],
                ['EventTracker::SALE', EventTracker::SALE],
                ['EventTracker::USER_REGISTERED', EventTracker::USER_REGISTERED],
                ['EventTracker::USER_LOGGED_IN', EventTracker::USER_LOGGED_IN],
                ['EventTracker::PAGE_VIEW', EventTracker::PAGE_VIEW],
                ['EventTracker::SEARCH', EventTracker::SEARCH],
                ['EventTracker::NEWSLETTER_SIGNUP', EventTracker::NEWSLETTER_SIGNUP],
            ]
        );

        $this->newLine();
        $this->info('Event name validation rules:');
        $this->table(
            ['Rule', 'Description'],
            [
                ['Format', 'snake_case (lowercase with underscores)'],
                ['Length', '3-50 characters'],
                ['Start', 'Must start with a letter'],
                ['Characters', 'Only letters, numbers, and underscores'],
                ['Examples', 'user_registered, product_added_to_cart, newsletter_signup'],
            ]
        );

        $this->newLine();
        $this->info('Privacy-focused fingerprinting:');
        $this->table(
            ['Data point', 'How it\'s handled'],
            [
                ['User agent', 'Hashed with HMAC-SHA256 (salted)'],
                ['Session ID', 'HMAC-SHA256 of IP + user agent + date (daily rotation, salted)'],
                ['IP address', 'Not sent to Ranetrace (privacy-first)'],
                ['User ID', 'Only if explicitly provided or user is authenticated'],
            ]
        );

        $this->newLine();
        $this->info('Event tracking configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Events enabled', config('ranetrace.events.enabled') ? 'Yes' : 'No'],
                ['Queue enabled', config('ranetrace.events.queue') ? 'Yes' : 'No'],
                ['Queue name', BatchConfig::describeFeatureQueue('ranetrace.events')],
                ['API key set', blank(config('ranetrace.key')) ? 'No' : 'Yes'],
            ]
        );

        return self::SUCCESS;
    }
}
