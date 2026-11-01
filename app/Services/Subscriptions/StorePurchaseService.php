<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionAccessException;
use App\Jobs\AcknowledgeGooglePurchase;
use App\Models\BillingAgreement;
use App\Models\PlanStoreProduct;
use App\Models\PurchaseTransaction;
use App\Models\SubscriptionEntitlement;
use App\Models\User;
use App\Notifications\SubscriptionAccessChanged;
use Illuminate\Support\Facades\DB;

class StorePurchaseService
{
    public function __construct(private readonly AppleBillingProvider $apple, private readonly GooglePlayBillingProvider $google, private readonly BillingAccountService $accounts, private readonly EntitlementResolver $entitlements) {}

    public function verify(User $user, string $provider, string $reference): BillingAgreement
    {
        $bindings = $this->accounts->identifiers($user);

        return DB::transaction(function () use ($user, $provider, $reference, $bindings): BillingAgreement {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $environment = $this->entitlements->environment($user);
            if ($provider === 'google') {
                $fingerprint = hash('sha256', $reference);
                $known = BillingAgreement::query()->where('provider', $provider)->where('environment', $environment)
                    ->where(fn ($query) => $query->where('identity_fingerprint', $fingerprint)->orWhere('reference_fingerprint', $fingerprint)
                        ->orWhereHas('transactions', fn ($transactions) => $transactions->where('purchase_fingerprint', $fingerprint)))->first();
                if ($known && $known->user_id !== $user->getKey()) {
                    throw new SubscriptionAccessException('PURCHASE_ACCOUNT_CONFLICT', 409);
                }
                // Ancestor tokens can age out of Google's API; verify the latest token already owned by this agreement.
                $reference = $known?->purchase_reference ?? $reference;
            }
            $purchase = $this->evidence($provider, $reference, $environment);
            $this->checkBinding($purchase, $bindings[$provider]);
            $identity = hash('sha256', $purchase->identity);
            $chain = [hash('sha256', $purchase->reference)];
            $linked = $purchase->linkedReference;
            for ($depth = 0; $linked !== null; $depth++) {
                if ($depth >= 10 || in_array(hash('sha256', $linked), $chain, true)) {
                    throw new SubscriptionAccessException('INVALID_PURCHASE_CHAIN', 422);
                }
                $known = BillingAgreement::query()->where('provider', $provider)->where('environment', $environment)
                    ->where(fn ($query) => $query->where('reference_fingerprint', hash('sha256', $linked))
                        ->orWhereHas('transactions', fn ($transactions) => $transactions->where('purchase_fingerprint', hash('sha256', $linked))))->first();
                if ($known) {
                    if ($known->user_id !== $user->getKey()) {
                        throw new SubscriptionAccessException('PURCHASE_ACCOUNT_CONFLICT', 409);
                    }
                    $chain[] = hash('sha256', $linked);
                    $identity = $known->identity_fingerprint;
                    break;
                }
                $ancestor = $this->evidence($provider, $linked, $environment);
                $this->checkBinding($ancestor, $bindings[$provider]);
                $identity = hash('sha256', $ancestor->identity);
                $chain[] = hash('sha256', $ancestor->reference);
                $linked = $ancestor->linkedReference;
            }
            $agreement = BillingAgreement::query()->where('provider', $provider)->where('environment', $environment)
                ->where('identity_fingerprint', $identity)->lockForUpdate()->first();
            if ($agreement && $agreement->user_id !== $user->getKey()) {
                throw new SubscriptionAccessException('PURCHASE_ACCOUNT_CONFLICT', 409);
            }
            // A late RTDN for an ancestor token must fetch the currently owned token instead.
            if ($agreement && $provider === 'google' && ! in_array($agreement->reference_fingerprint, $chain, true)) {
                $purchase = $this->evidence($provider, $agreement->purchase_reference, $environment);
                $this->checkBinding($purchase, $bindings[$provider]);
            }
            $agreement = $this->persist($user, $purchase, $identity, $agreement);
            if ($agreement->acknowledgement_pending) {
                AcknowledgeGooglePurchase::dispatch($agreement->getKey())->afterCommit();
            }

            return $agreement;
        }, 3);
    }

    public function evidence(string $provider, string $reference, string $environment): VerifiedPurchase
    {
        $purchase = match ($provider) {
            'apple' => $this->apple->verify($reference, $environment),
            'google' => $this->google->verify($reference, $environment),
            default => throw new SubscriptionAccessException('INVALID_PROVIDER', 422),
        };
        if ($purchase->environment !== $environment || $purchase->provider !== $provider) {
            throw new SubscriptionAccessException('PURCHASE_ENVIRONMENT_MISMATCH', 422);
        }

        return $purchase;
    }

    private function persist(User $user, VerifiedPurchase $purchase, string $identity, ?BillingAgreement $agreement): BillingAgreement
    {
        $platform = $purchase->provider === 'apple' ? 'ios' : 'android';
        $product = PlanStoreProduct::query()->with('plan')->where('platform', $platform)->where('environment', $purchase->environment)
            ->where('product_id', $purchase->productId)->where('base_plan_id', $purchase->basePlanId)->first();
        $replacement = ! $agreement || $agreement->plan_store_product_id !== $product?->getKey();
        if (! $product || ($replacement && ($product->validation_state !== 'verified' || $product->plan_revision !== $product->plan->revision))) {
            throw new SubscriptionAccessException('STORE_PRODUCT_UNAVAILABLE', 503);
        }
        $attributes = [
            'user_id' => $user->getKey(), 'plan_store_product_id' => $product->getKey(), 'provider' => $purchase->provider, 'environment' => $purchase->environment,
            'identity_fingerprint' => $identity, 'reference_fingerprint' => hash('sha256', $purchase->reference),
            'purchase_reference' => $purchase->reference, 'provider_state' => $purchase->state, 'auto_renews' => $purchase->autoRenews,
            'acknowledgement_pending' => $purchase->acknowledgementPending, 'verified_at' => now(),
        ];
        if ($replacement) {
            $attributes += ['daily_allowance' => $product->plan->daily_allowance, 'features' => $product->plan->features];
        }
        if ($agreement) {
            $agreement->update($attributes);
        } else {
            $agreement = BillingAgreement::query()->create($attributes);
        }
        $this->recordTransaction($agreement, $purchase);
        if ($purchase->startsAt && $purchase->endsAt) {
            $entitlement = SubscriptionEntitlement::query()->updateOrCreate(['billing_agreement_id' => $agreement->getKey()], [
                'user_id' => $user->getKey(), 'subscription_plan_id' => $product->subscription_plan_id,
                'source' => 'paid', 'environment' => $purchase->environment, 'daily_allowance' => $agreement->daily_allowance,
                'features' => $agreement->features, 'starts_at' => $purchase->startsAt, 'ends_at' => $purchase->endsAt,
                'revoked_at' => $purchase->revokedAt, 'reason' => 'Verified store subscription',
            ]);
            if ($entitlement->wasRecentlyCreated || $entitlement->wasChanged(['ends_at', 'revoked_at', 'daily_allowance']) || $agreement->wasChanged('provider_state')) {
                DB::afterCommit(fn () => $user->notify(new SubscriptionAccessChanged));
            }
        }

        return $agreement;
    }

    private function recordTransaction(BillingAgreement $agreement, VerifiedPurchase $purchase): void
    {
        if (! $purchase->transactionId || ! $purchase->startsAt || ! $purchase->endsAt || $purchase->state === 'pending') {
            return;
        }
        $transaction = PurchaseTransaction::query()->firstOrCreate([
            'provider' => $purchase->provider, 'environment' => $purchase->environment, 'transaction_fingerprint' => hash('sha256', $purchase->transactionId),
        ], [
            'billing_agreement_id' => $agreement->getKey(), 'purchase_fingerprint' => hash('sha256', $purchase->reference), 'transaction_reference' => $purchase->transactionId,
            'product_id' => $purchase->productId, 'starts_at' => $purchase->transactionStartsAt, 'ends_at' => $purchase->transactionEndsAt,
            'amount' => $purchase->amount, 'currency' => $purchase->currency, 'revoked_at' => $purchase->revokedAt,
        ]);
        if ($transaction->billing_agreement_id !== $agreement->getKey()) {
            throw new SubscriptionAccessException('PURCHASE_ACCOUNT_CONFLICT', 409);
        }
        if ($purchase->revokedAt && ! $transaction->revoked_at) {
            $transaction->update(['revoked_at' => $purchase->revokedAt]);
        }
    }

    private function checkBinding(VerifiedPurchase $purchase, string $expected): void
    {
        if (! hash_equals(strtolower($expected), strtolower($purchase->accountBinding))) {
            throw new SubscriptionAccessException('PURCHASE_ACCOUNT_CONFLICT', 409);
        }
    }
}
