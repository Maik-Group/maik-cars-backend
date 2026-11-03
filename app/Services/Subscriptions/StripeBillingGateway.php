<?php

namespace App\Services\Subscriptions;

use App\Models\BillingOrder;
use App\Models\BillingPlanPrice;
use App\Models\User;
use Database\Seeders\MaikCatCommercialOffersSeeder;
use RuntimeException;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeBillingGateway
{
    protected function client(): StripeClient
    {
        $key = config('stripe_billing.secret_key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Stripe is not configured.');
        }

        return new StripeClient([
            'api_key' => $key,
            'stripe_version' => config('stripe_billing.api_version'),
            'max_network_retries' => 1,
        ]);
    }

    public function customer(User $user, string $environment): array
    {
        $created = $this->client()->customers->create([
            'email' => $user->email,
            'metadata' => ['user_id' => (string) $user->getKey(), 'environment' => $environment],
        ], ['idempotency_key' => 'maik-customer-'.$environment.'-'.$user->getKey()]);

        return $created->toArray();
    }

    public function checkout(BillingOrder $order, BillingPlanPrice $offer, string $customer): array
    {
        $params = $this->checkoutSessionParameters($order, $offer, $customer);

        return $this->client()->checkout->sessions->create(
            $params,
            ['idempotency_key' => 'maik-checkout-'.$order->getKey()],
        )->toArray();
    }

    /**
     * Build Stripe Checkout from the immutable backend offer selected for this order.
     *
     * Stripe Price IDs display the title of their existing Stripe Product, which
     * may be stale (e.g. an old QA name). For non-renewing purchases, inline
     * product_data ensures the customer sees the title stored in Laravel for
     * the actual purchased offer without mutating any Stripe catalog products.
     */
    public function checkoutSessionParameters(BillingOrder $order, BillingPlanPrice $offer, string $customer): array
    {
        $base = config('stripe_billing.return_url');
        if (! is_string($base) || ! str_starts_with($base, 'https://')) {
            throw new RuntimeException('A verified HTTPS Stripe return URL is required.');
        }

        $prepaid = $order->kind === 'subscription'
            && ($order->offer_snapshot['billingMode'] ?? null) === 'prepaid_term';
        $oneTime = $prepaid || $order->kind === 'topup';
        $title = trim((string) ($order->offer_snapshot['name'] ?? $offer->name));
        if ($oneTime && ($title === '' || mb_strlen($title) > 255)) {
            throw new RuntimeException('Checkout offer must have a valid database title.');
        }
        $credits = (int) ($order->offer_snapshot['credits'] ?? 0);
        $months = (int) ($order->offer_snapshot['durationMonths'] ?? 0);
        $description = $order->kind === 'topup'
            ? $credits.' extra Maik Cat credits. One-time purchase, no subscription.'
            : $credits.' Maik Cat credits for '.$months.' months. One-time prepaid access, no automatic renewal.';
        $params = [
            'customer' => $customer,
            'client_reference_id' => $order->getKey(),
            'mode' => $oneTime ? 'payment' : 'subscription',
            'submit_type' => $oneTime ? 'pay' : 'subscribe',
            'line_items' => [$oneTime
                ? ['price_data' => [
                    'currency' => strtolower((string) $order->offer_snapshot['currency']),
                    'unit_amount' => (int) $order->offer_snapshot['amountMinor'],
                    'product_data' => [
                        'name' => $title,
                        'description' => $description,
                        'metadata' => [
                            'maik_offer_key' => (string) $offer->offer_key,
                            'maik_offer_revision' => (string) $offer->revision,
                            'maik_purchase_kind' => $order->kind,
                        ],
                    ],
                ], 'quantity' => 1]
                : ['price' => $offer->stripe_price_id, 'quantity' => 1]],
            'success_url' => $base.'?orderId='.$order->getKey().'&result=success',
            'cancel_url' => $base.'?orderId='.$order->getKey().'&result=cancel',
            'metadata' => ['order_id' => $order->getKey(), 'billing_environment' => $order->environment, 'purchase_kind' => $order->kind],
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];
        // Link's hosted express-payment surface has a fixed green treatment
        // and can visually replace the merchant-branded Checkout in our QA
        // WebView. Use the standard Stripe Checkout card form for the isolated
        // sandbox visual review; do not change live payment-method behavior.
        if ($order->environment === 'sandbox') {
            $params['wallet_options'] = ['link' => ['display' => 'never']];
        }
        if ($order->kind === 'subscription' && ! $prepaid) {
            $params['subscription_data'] = [
                'metadata' => ['order_id' => $order->getKey(), 'billing_environment' => $order->environment],
            ];
        }

        return $params;
    }

    public function verifyEvent(string $rawBody, string $signature): array
    {
        $secret = config('stripe_billing.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Stripe webhook signature secret missing.');
        }

        return Webhook::constructEvent($rawBody, $signature, $secret)->toArray();
    }

    public function invoice(string $id): array
    {
        // Invoice lines are already a paginated collection in the invoice
        // object. Expanding the collection itself is not a valid Stripe expand.
        // If Stripe reports has_more, fulfillment refuses to infer a full term.
        return $this->client()->invoices->retrieve($id)->toArray();
    }

    public function subscription(string $id): array
    {
        return $this->client()->subscriptions->retrieve($id)->toArray();
    }

    public function checkoutSession(string $id): array
    {
        return $this->client()->checkout->sessions->retrieve($id)->toArray();
    }

    public function expireCheckoutSession(string $id): array
    {
        return $this->client()->checkout->sessions->expire($id)->toArray();
    }

    public function checkoutSessionsForCustomer(string $customerId, ?string $startingAfter = null): array
    {
        $options = ['customer' => $customerId, 'limit' => 100];
        if ($startingAfter !== null) {
            $options['starting_after'] = $startingAfter;
        }

        return $this->client()->checkout->sessions->all($options)->toArray();
    }

    public function refund(string $id): array
    {
        return $this->client()->refunds->retrieve($id)->toArray();
    }

    public function charge(string $id): array
    {
        return $this->client()->charges->retrieve($id)->toArray();
    }

    public function dispute(string $id): array
    {
        return $this->client()->disputes->retrieve($id)->toArray();
    }

    public function price(string $id): array
    {
        return $this->client()->prices->retrieve($id)->toArray();
    }

    /**
     * Locate a previously created QA test price after a partial seeder run.
     * Stripe lookup keys survive its short-lived API idempotency cache.
     */
    public function findQaPrice(BillingPlanPrice $offer): ?array
    {
        $this->assertQaOfferCreation($offer);
        $result = $this->client()->prices->all([
            'lookup_keys' => [$this->qaLookupKey($offer)],
            'limit' => 2,
        ])->toArray();
        $prices = $result['data'] ?? [];
        if (count($prices) > 1) {
            throw new RuntimeException('Duplicate Stripe QA Price lookup key: '.$offer->offer_key);
        }

        return $prices[0] ?? null;
    }

    public function findCommercialTestPrice(BillingPlanPrice $offer): ?array
    {
        $this->assertCommercialTestOffer($offer);
        $result = $this->client()->prices->all(['lookup_keys' => [$this->qaLookupKey($offer)], 'limit' => 2])->toArray();
        if (count($result['data'] ?? []) > 1) {
            throw new RuntimeException('Duplicate commercial Stripe test price.');
        }

        return $result['data'][0] ?? null;
    }

    public function createCommercialTestProduct(BillingPlanPrice $offer): array
    {
        $this->assertCommercialTestOffer($offer);

        return $this->client()->products->create([
            'name' => $offer->name,
            'metadata' => ['maik_offer_key' => $offer->offer_key, 'maik_revision' => (string) $offer->revision, 'environment' => 'sandbox'],
        ], ['idempotency_key' => 'maik-commercial-test-product-'.$offer->offer_key.'-r'.$offer->revision])->toArray();
    }

    public function createCommercialTestPrice(BillingPlanPrice $offer, string $productId): array
    {
        $this->assertCommercialTestOffer($offer);
        if (! str_starts_with($productId, 'prod_')) {
            throw new RuntimeException('Invalid Stripe product ID.');
        }
        $params = [
            'product' => $productId, 'currency' => strtolower($offer->currency),
            'unit_amount' => $offer->amount_minor, 'lookup_key' => $this->qaLookupKey($offer),
            'metadata' => ['maik_offer_key' => $offer->offer_key, 'maik_revision' => (string) $offer->revision, 'credits' => (string) $offer->credits],
        ];
        if ($offer->kind === 'subscription') {
            $params['recurring'] = ['interval' => 'month', 'interval_count' => $offer->duration_months];
        }

        return $this->client()->prices->create($params, [
            'idempotency_key' => 'maik-commercial-test-price-'.$offer->offer_key.'-r'.$offer->revision,
        ])->toArray();
    }

    private function assertCommercialTestOffer(BillingPlanPrice $offer): void
    {
        if ($offer->environment !== 'sandbox'
            || ! in_array($offer->offer_key, array_column(MaikCatCommercialOffersSeeder::OFFERS, 'key'), true)
            || ! str_starts_with((string) config('stripe_billing.secret_key'), 'sk_test_')
            || config('stripe_billing.environment') !== 'sandbox') {
            throw new RuntimeException('Commercial offer publication requires an approved sandbox offer and Stripe test mode.');
        }
    }

    /** Create an actual Stripe *test-mode* product for one immutable QA offer. */
    public function createQaProduct(BillingPlanPrice $offer): array
    {
        $this->assertQaOfferCreation($offer);

        return $this->client()->products->create([
            'name' => $offer->name,
            'description' => $offer->kind === 'topup'
                ? $offer->credits.' extra credits. One-time purchase.'
                : $offer->credits.' credits for '.$offer->duration_months.' months. One-time prepaid plan, no automatic renewal.',
            'metadata' => [
                'maik_offer_key' => $offer->offer_key,
                'maik_revision' => (string) $offer->revision,
                'environment' => 'sandbox',
            ],
        ], ['idempotency_key' => 'maik-qa-product-'.$offer->offer_key.'-r'.$offer->revision])->toArray();
    }

    /** Create an actual Stripe *test-mode* price matching the seeded offer. */
    public function createQaPrice(BillingPlanPrice $offer, string $productId): array
    {
        $this->assertQaOfferCreation($offer);
        if (! str_starts_with($productId, 'prod_')) {
            throw new RuntimeException('Invalid QA Stripe product ID.');
        }
        $params = [
            'product' => $productId,
            'currency' => strtolower($offer->currency),
            'unit_amount' => $offer->amount_minor,
            'lookup_key' => $this->qaLookupKey($offer),
            'metadata' => [
                'maik_offer_key' => $offer->offer_key,
                'maik_revision' => (string) $offer->revision,
                'environment' => 'sandbox',
                'credits' => (string) $offer->credits,
            ],
        ];
        if ($offer->kind === 'subscription') {
            $params['recurring'] = ['interval' => 'month', 'interval_count' => $offer->duration_months];
        }

        return $this->client()->prices->create(
            $params,
            ['idempotency_key' => 'maik-qa-price-'.$offer->offer_key.'-r'.$offer->revision],
        )->toArray();
    }

    private function qaLookupKey(BillingPlanPrice $offer): string
    {
        return 'maik-cat-'.$offer->offer_key.'-r'.$offer->revision;
    }

    private function assertQaOfferCreation(BillingPlanPrice $offer): void
    {
        if ($offer->environment !== 'sandbox' || ! str_starts_with($offer->offer_key, 'qa-')
            || ! str_starts_with((string) config('stripe_billing.secret_key'), 'sk_test_')
            || config('stripe_billing.environment') !== 'sandbox') {
            throw new RuntimeException('Stripe QA product/price creation requires a sandbox offer and sk_test_ key.');
        }
    }

    public function portal(string $customer, string $configuration): array
    {
        $returnUrl = config('stripe_billing.portal_return_url');
        if (! is_string($returnUrl) || ! str_starts_with($returnUrl, 'https://')) {
            throw new RuntimeException('Verified HTTPS customer portal return URL missing.');
        }

        return $this->client()->billingPortal->sessions->create([
            'customer' => $customer, 'return_url' => $returnUrl, 'configuration' => $configuration,
        ])->toArray();
    }

    public function scheduleFromSubscription(string $providerId): array
    {
        return $this->client()->subscriptionSchedules->create(['from_subscription' => $providerId])->toArray();
    }

    public function updateSchedule(string $id, array $phases): array
    {
        return $this->client()->subscriptionSchedules->update($id, [
            'end_behavior' => 'release', 'phases' => $phases,
        ])->toArray();
    }

    public function releaseSchedule(string $id): void
    {
        $this->client()->subscriptionSchedules->release($id);
    }

    public function cancelAtPeriodEnd(string $id): array
    {
        return $this->client()->subscriptions->update($id, ['cancel_at_period_end' => true])->toArray();
    }
}
