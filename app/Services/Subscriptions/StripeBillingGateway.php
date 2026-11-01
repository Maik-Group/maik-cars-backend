<?php

namespace App\Services\Subscriptions;

use App\Models\BillingOrder;
use App\Models\BillingPlanPrice;
use App\Models\User;
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
        $base = config('stripe_billing.return_url');
        if (! is_string($base) || ! str_starts_with($base, 'https://')) {
            throw new RuntimeException('A verified HTTPS Stripe return URL is required.');
        }

        $params = [
            'customer' => $customer,
            'client_reference_id' => $order->getKey(),
            'mode' => $order->kind === 'subscription' ? 'subscription' : 'payment',
            'line_items' => [['price' => $offer->stripe_price_id, 'quantity' => 1]],
            'success_url' => $base.'?orderId='.$order->getKey().'&result=success',
            'cancel_url' => $base.'?orderId='.$order->getKey().'&result=cancel',
            'metadata' => ['order_id' => $order->getKey(), 'billing_environment' => $order->environment],
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];
        if ($order->kind === 'subscription') {
            $params['subscription_data'] = [
                'metadata' => ['order_id' => $order->getKey(), 'billing_environment' => $order->environment],
            ];
        }

        return $this->client()->checkout->sessions->create(
            $params,
            ['idempotency_key' => 'maik-checkout-'.$order->getKey()],
        )->toArray();
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
