<?php

namespace App\Services\Subscriptions;

use App\Models\BillingOrder;
use App\Models\BillingPlanPrice;
use App\Models\BillingSubscription;
use App\Models\StripeCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class StripeCheckoutService
{
    public function __construct(private readonly StripeBillingGateway $stripe) {}

    public function environment(): string
    {
        $key = config('stripe_billing.secret_key');
        $environment = match (true) {
            is_string($key) && str_starts_with($key, 'sk_test_') => 'sandbox',
            is_string($key) && str_starts_with($key, 'sk_live_') => 'production',
            default => null,
        };
        if (! in_array($environment, ['production', 'sandbox'], true)) {
            throw new HttpException(503, 'BILLING_ENVIRONMENT_MISMATCH');
        }

        return $environment;
    }

    public function catalog(): array
    {
        $allowed = $this->isCheckoutConfigured();
        $configurationReason = $this->checkoutConfigurationReason();
        $offers = BillingPlanPrice::query()->where('environment', $this->environment())
            ->whereNull('retired_at')
            ->whereNotNull('published_at')
            ->orderBy('kind')->orderBy('amount_minor')->get();
        // Once commercial offers are published, QA fixtures must not appear
        // in the customer-facing catalog. Preserve the existing catalog until
        // at least one commercial offer is published, avoiding an empty store.
        $commercial = $offers->reject(static fn (BillingPlanPrice $offer) => str_starts_with($offer->offer_key, 'qa-'));
        if ($commercial->isNotEmpty()) {
            $offers = $commercial->unique('offer_key');
        }
        $map = static fn (BillingPlanPrice $price): array => [
            'priceVersionId' => $price->getKey(),
            'planId' => $price->subscription_plan_id,
            'name' => $price->name,
            'credits' => $price->credits,
            'durationMonths' => $price->duration_months,
            'currency' => $price->currency,
            'amountMinor' => $price->amount_minor,
            'termsVersion' => $price->terms_version,
            'checkoutEligible' => $allowed && $price->checkoutEligible(),
            'checkoutUnavailableReason' => ! $allowed ? $configurationReason
                : (! $price->published_at ? 'OFFER_NOT_PUBLISHED'
                    : (! $price->verified_at ? 'OFFER_NOT_VERIFIED'
                        : ($price->retired_at ? 'OFFER_RETIRED'
                            : (! filled($price->stripe_price_id) ? 'STRIPE_PRICE_MISSING' : null)))),
        ];

        return [
            'environment' => $this->environment(),
            'subscriptions' => $offers->where('kind', 'subscription')->map($map)->values()->all(),
            'topUps' => $offers->where('kind', 'topup')->map($map)->values()->all(),
            'externalCheckoutAllowed' => false,
            'checkoutUnavailableReason' => $configurationReason,
        ];
    }

    public function isCheckoutConfigured(): bool
    {
        return $this->checkoutConfigurationReason() === null;
    }

    private function checkoutConfigurationReason(): ?string
    {
        $key = config('stripe_billing.secret_key');
        if (! is_string($key) || (! str_starts_with($key, 'sk_test_') && ! str_starts_with($key, 'sk_live_'))) {
            return 'CHECKOUT_NOT_CONFIGURED';
        }
        if (! is_string(config('stripe_billing.return_url')) || ! str_starts_with(config('stripe_billing.return_url'), 'https://')) {
            return 'CHECKOUT_NOT_CONFIGURED';
        }
        // A successful Stripe payment without signed webhook processing cannot
        // safely grant credits. Fail closed before creating such a checkout.
        if (! is_string(config('stripe_billing.webhook_secret')) || ! str_starts_with(config('stripe_billing.webhook_secret'), 'whsec_')) {
            return 'STRIPE_WEBHOOK_NOT_CONFIGURED';
        }

        return null;
    }

    public function begin(User $user, string $kind, int $priceId, string $context, string $idempotency): BillingOrder
    {
        // Direct hosted checkout from internal QA is permitted only with a
        // Stripe test key. Store-distributed native contexts remain blocked.
        $approvedContext = $context === 'web'
            || ($context === 'qa-mobile' && $this->environment() === 'sandbox');
        if (! $this->isCheckoutConfigured() || ! $approvedContext) {
            throw new HttpException(403, 'CHECKOUT_UNAVAILABLE');
        }
        if (! $user->is_active) {
            throw new HttpException(403, 'ACCOUNT_INACTIVE');
        }
        if (strlen($idempotency) < 12 || strlen($idempotency) > 200) {
            throw new HttpException(422, 'IDEMPOTENCY_KEY_REQUIRED');
        }
        $environment = $this->environment();
        $requestHash = hash('sha256', json_encode([$kind, $priceId, $context], JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $idempotency);

        $order = DB::transaction(function () use ($user, $kind, $priceId, $context, $environment, $requestHash, $keyHash): BillingOrder {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = BillingOrder::query()->where('user_id', $user->getKey())
                ->where('environment', $environment)->where('idempotency_hash', $keyHash)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new HttpException(409, 'IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }
            $offer = BillingPlanPrice::query()->whereKey($priceId)->where('environment', $environment)->first();
            if (! $offer || $offer->kind !== $kind || ! $offer->checkoutEligible()) {
                throw new HttpException(409, 'OFFER_CHANGED');
            }
            if (str_starts_with($offer->offer_key, 'qa-')
                && BillingPlanPrice::query()->where('environment', $environment)
                    ->whereNotNull('published_at')->whereNull('retired_at')
                    ->where('offer_key', 'not like', 'qa-%')->exists()) {
                throw new HttpException(409, 'OFFER_CHANGED');
            }
            if ($kind === 'subscription') {
                if (BillingOrder::query()->where('user_id', $user->getKey())->where('environment', $environment)
                    ->where('kind', 'subscription')->where('status', 'fulfilled')
                    ->where('period_end', '>', now('UTC'))->exists()) {
                    throw new HttpException(409, 'SUBSCRIPTION_EXISTS');
                }
                if (BillingSubscription::query()->where('user_id', $user->getKey())
                    ->where('environment', $environment)
                    ->whereIn('status', ['incomplete', 'trialing', 'active', 'past_due', 'unpaid', 'paused'])
                    ->where(fn ($q) => $q->whereNull('period_end')->orWhere('period_end', '>', now('UTC')))->exists()) {
                    throw new HttpException(409, 'SUBSCRIPTION_EXISTS');
                }
                if (BillingOrder::query()->where('user_id', $user->getKey())
                    ->where('environment', $environment)->where('kind', 'subscription')
                    ->whereIn('status', ['reserved', 'checkout_created', 'needs_reconciliation', 'paid'])->exists()) {
                    throw new HttpException(409, 'SUBSCRIPTION_CHECKOUT_IN_PROGRESS');
                }
            }
            StripeCustomer::query()->firstOrCreate(['user_id' => $user->getKey(), 'environment' => $environment]);

            return BillingOrder::query()->create([
                'id' => (string) Str::uuid(), 'user_id' => $user->getKey(),
                'billing_plan_price_id' => $offer->getKey(), 'environment' => $environment,
                'kind' => $kind, 'purchase_context_id' => $context,
                'idempotency_hash' => $keyHash, 'request_hash' => $requestHash,
                'offer_snapshot' => [
                    'offerKey' => $offer->offer_key, 'revision' => $offer->revision,
                    'credits' => $offer->credits, 'durationMonths' => $offer->duration_months,
                    'amountMinor' => $offer->amount_minor, 'currency' => $offer->currency,
                    'priceId' => $offer->stripe_price_id, 'termsVersion' => $offer->terms_version,
                    'billingMode' => $kind === 'subscription' ? 'prepaid_term' : 'topup',
                ],
                'status' => 'reserved',
            ]);
        }, 3);

        if ($order->status === 'checkout_created' || $order->status === 'fulfilled') {
            return $order;
        }
        if ($order->status !== 'reserved') {
            throw new HttpException(409, 'CHECKOUT_RECONCILIATION_REQUIRED');
        }

        // Network requests must never run inside the wallet or order transaction.
        // Repeated calls use exactly the same provider idempotency identities.
        try {
            $mapping = StripeCustomer::query()->where('user_id', $user->getKey())
                ->where('environment', $environment)->firstOrFail();
            if (! $mapping->stripe_customer_id) {
                $customer = $this->stripe->customer($user, $environment);
                $id = $customer['id'] ?? null;
                if (! is_string($id) || ! str_starts_with($id, 'cus_')) {
                    throw new RuntimeException('Invalid Stripe customer identity.');
                }
                StripeCustomer::query()->whereKey($mapping->getKey())->whereNull('stripe_customer_id')
                    ->update(['stripe_customer_id' => $id]);
                $mapping->refresh();
            }
            $offer = BillingPlanPrice::query()->findOrFail($order->billing_plan_price_id);
            $session = $this->stripe->checkout($order, $offer, (string) $mapping->stripe_customer_id);
            $url = (string) ($session['url'] ?? '');
            $hostname = parse_url($url, PHP_URL_HOST);
            if (! in_array($hostname, ['checkout.stripe.com'], true) || ! str_starts_with($url, 'https://')) {
                throw new RuntimeException('Stripe returned an unapproved checkout URL.');
            }
            $order->forceFill([
                'stripe_checkout_session_id' => $session['id'], 'checkout_url' => $url,
                'expires_at' => isset($session['expires_at']) ? now()->setTimestamp($session['expires_at']) : null,
                'status' => 'checkout_created',
            ])->save();

            return $order;
        } catch (Throwable $error) {
            report($error);
            $order->refresh();
            if ($order->status === 'reserved') {
                $order->forceFill(['status' => 'needs_reconciliation'])->save();
            }
            throw new HttpException(503, 'CHECKOUT_RECONCILIATION_REQUIRED');
        }
    }

    public function orderFor(User $user, string $orderId): BillingOrder
    {
        return BillingOrder::query()->whereKey($orderId)
            ->where('environment', $this->environment())->where('user_id', $user->getKey())->firstOrFail();
    }

    public function orderResponse(BillingOrder $order, bool $includeCheckoutUrl = false): array
    {
        $data = [
            'orderId' => $order->getKey(), 'kind' => $order->kind, 'status' => $order->status,
            'paymentStatus' => $order->payment_status, 'fulfillmentStatus' => $order->fulfillment_status,
        ];
        if ($includeCheckoutUrl && $order->status === 'checkout_created') {
            $data['checkoutUrl'] = $order->checkout_url;
        }
        if ($order->fulfillment_status === 'fulfilled' && $order->payment_status === 'paid') {
            $data['creditsGranted'] = (int) ($order->offer_snapshot['credits'] ?? 0);
        }

        return $data;
    }
}
