<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionAccessException;
use App\Models\BillingAgreement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GooglePlayBillingProvider
{
    public function __construct(private readonly GooglePlayAuthorization $authorization) {}

    public function verify(string $reference, ?string $environment): VerifiedPurchase
    {
        try {
            $response = Http::withToken($this->authorization->token())->acceptJson()->connectTimeout(5)->timeout(15)
                ->get($this->baseUrl().'/purchases/subscriptionsv2/tokens/'.rawurlencode($reference));
        } catch (ConnectionException) {
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }
        if (! $response->successful()) {
            throw new SubscriptionAccessException($response->status() === 404 ? 'INVALID_PURCHASE' : 'VERIFICATION_UNAVAILABLE', $response->status() === 404 ? 422 : 503);
        }
        $purchase = $response->json();
        $verifiedEnvironment = array_key_exists('testPurchase', $purchase) ? 'sandbox' : 'production';
        if ($environment !== null && $verifiedEnvironment !== $environment) {
            throw new SubscriptionAccessException('PURCHASE_ENVIRONMENT_MISMATCH', 422);
        }
        $lineItems = collect($purchase['lineItems'] ?? [])->filter(fn (array $line): bool => isset($line['autoRenewingPlan']));
        $owned = $lineItems->filter(fn (array $line): bool => isset($line['expiryTime'], $line['latestSuccessfulOrderId']));
        $current = $owned->filter(fn (array $line): bool => CarbonImmutable::parse($line['expiryTime'])->isFuture());
        if ($current->count() > 1 || ($owned->isEmpty() && $lineItems->count() !== 1)) {
            throw new SubscriptionAccessException('UNSUPPORTED_STORE_PRODUCT', 422);
        }
        // A deferred replacement includes an unowned future item. Retain the paid-through item until renewal.
        $line = $current->first() ?? $owned->sortByDesc('expiryTime')->first() ?? $lineItems->first();
        $state = match ($purchase['subscriptionState'] ?? '') {
            'SUBSCRIPTION_STATE_ACTIVE', 'SUBSCRIPTION_STATE_CANCELED' => 'active',
            'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => 'grace',
            'SUBSCRIPTION_STATE_ON_HOLD' => 'hold',
            'SUBSCRIPTION_STATE_PAUSED' => 'paused',
            'SUBSCRIPTION_STATE_EXPIRED', 'SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED' => 'expired',
            'SUBSCRIPTION_STATE_PENDING' => 'pending',
            default => throw new SubscriptionAccessException('INVALID_STORE_EVIDENCE', 422),
        };

        $order = [];
        $orderLine = [];
        $orderId = $state === 'pending' ? null : ($line['latestSuccessfulOrderId'] ?? null);
        if ($orderId) {
            try {
                $orderResponse = Http::withToken($this->authorization->token())->acceptJson()->connectTimeout(5)->timeout(15)
                    ->get($this->baseUrl().'/orders/'.rawurlencode($orderId));
            } catch (ConnectionException) {
                throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
            }
            if (! $orderResponse->successful()) {
                throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
            }
            $order = $orderResponse->json();
            $orderLine = collect($order['lineItems'] ?? [])->first(fn (array $entry): bool => ($entry['productId'] ?? '') === $line['productId']
                && ($entry['subscriptionDetails']['basePlanId'] ?? '') === ($line['offerDetails']['basePlanId'] ?? '')) ?? [];
            // The authenticated subscription response owns this successful order ID. Deferred chains may retain an ancestor's order token.
            if (($order['orderId'] ?? '') !== $orderId || ! $orderLine
                || ! in_array($order['state'] ?? '', ['PROCESSED', 'PARTIALLY_REFUNDED', 'PENDING_REFUND', 'REFUNDED'], true)) {
                throw new SubscriptionAccessException('INVALID_STORE_EVIDENCE', 422);
            }
            if ($order['state'] === 'REFUNDED') {
                $state = 'revoked';
            }
        }

        return new VerifiedPurchase([
            'provider' => 'google', 'environment' => $verifiedEnvironment, 'identity' => $reference, 'reference' => $reference,
            'product_id' => $line['productId'] ?? '', 'base_plan_id' => $line['offerDetails']['basePlanId'] ?? '',
            'account_binding' => $purchase['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? '',
            'state' => $state, 'starts_at' => $purchase['startTime'] ?? null, 'ends_at' => $line['expiryTime'] ?? null,
            'auto_renews' => $line['autoRenewingPlan']['autoRenewEnabled'] ?? false,
            'acknowledgement_pending' => ($purchase['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_PENDING' && $state !== 'pending',
            'linked_reference' => $purchase['linkedPurchaseToken'] ?? null,
            'transaction_id' => $orderId,
            'transaction_starts_at' => $orderLine['subscriptionDetails']['servicePeriodStartTime'] ?? null,
            'transaction_ends_at' => $orderLine['subscriptionDetails']['servicePeriodEndTime'] ?? null,
            'amount' => isset($order['total']) ? (float) ($order['total']['units'] ?? 0) + ((float) ($order['total']['nanos'] ?? 0) / 1000000000) : null,
            'currency' => $order['total']['currencyCode'] ?? null,
            'revoked_at' => $state === 'revoked' ? ($order['lastEventTime'] ?? null) : null,
        ]);
    }

    public function acknowledge(BillingAgreement $agreement): void
    {
        try {
            $response = Http::withToken($this->authorization->token())->acceptJson()->connectTimeout(5)->timeout(15)->withBody('{}', 'application/json')
                ->post($this->baseUrl().'/purchases/subscriptions/'.rawurlencode($agreement->product->product_id).'/tokens/'.rawurlencode($agreement->purchase_reference).':acknowledge');
        } catch (ConnectionException) {
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }
        if (! $response->successful()) {
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }
    }

    private function baseUrl(): string
    {
        return 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'.rawurlencode(config('subscriptions.google.package_name'));
    }

    public function catalog(string $productId, string $basePlanId): array
    {
        try {
            $response = Http::withToken($this->authorization->token())->acceptJson()->connectTimeout(5)->timeout(15)
                ->get($this->baseUrl().'/subscriptions/'.rawurlencode($productId));
        } catch (ConnectionException) {
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }
        if (! $response->successful()) {
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }
        $basePlan = collect($response->json('basePlans', []))->firstWhere('basePlanId', $basePlanId);

        return ['product_id' => $response->json('productId'), 'duration_months' => match ($basePlan['autoRenewingBasePlanType']['billingPeriodDuration'] ?? '') {
            'P1M' => 1, 'P3M' => 3, 'P6M' => 6, 'P1Y', 'P12M' => 12, default => null,
        }, 'available' => ($basePlan['state'] ?? '') === 'ACTIVE'];
    }
}
