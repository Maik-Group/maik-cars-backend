<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionAccessException;
use Carbon\CarbonImmutable;

readonly class VerifiedPurchase
{
    public string $provider;

    public string $environment;

    public string $identity;

    public string $reference;

    public string $productId;

    public string $basePlanId;

    public string $accountBinding;

    public string $state;

    public bool $autoRenews;

    public bool $acknowledgementPending;

    public ?string $linkedReference;

    public ?string $transactionId;

    public ?CarbonImmutable $startsAt;

    public ?CarbonImmutable $endsAt;

    public ?CarbonImmutable $revokedAt;

    public ?CarbonImmutable $transactionStartsAt;

    public ?CarbonImmutable $transactionEndsAt;

    public ?float $amount;

    public ?string $currency;

    /** Only provider adapters may construct this object, from authenticated store responses. */
    public function __construct(array $claims)
    {
        foreach (['provider', 'environment', 'identity', 'reference', 'product_id', 'account_binding', 'state'] as $required) {
            if (! is_string($claims[$required] ?? null) || $claims[$required] === '') {
                throw new SubscriptionAccessException('INVALID_STORE_EVIDENCE', 422);
            }
        }
        $this->provider = $claims['provider'];
        $this->environment = $claims['environment'];
        $this->identity = $claims['identity'];
        $this->reference = $claims['reference'];
        $this->productId = $claims['product_id'];
        $this->basePlanId = $claims['base_plan_id'] ?? '';
        $this->accountBinding = $claims['account_binding'];
        $this->state = $claims['state'];
        $this->autoRenews = (bool) ($claims['auto_renews'] ?? false);
        $this->acknowledgementPending = (bool) ($claims['acknowledgement_pending'] ?? false);
        $this->linkedReference = $claims['linked_reference'] ?? null;
        $this->transactionId = $claims['transaction_id'] ?? null;
        $this->startsAt = isset($claims['starts_at']) ? CarbonImmutable::parse($claims['starts_at'])->utc() : null;
        $this->endsAt = isset($claims['ends_at']) ? CarbonImmutable::parse($claims['ends_at'])->utc() : null;
        $this->revokedAt = isset($claims['revoked_at']) ? CarbonImmutable::parse($claims['revoked_at'])->utc() : null;
        $this->transactionStartsAt = isset($claims['transaction_starts_at']) ? CarbonImmutable::parse($claims['transaction_starts_at'])->utc() : $this->startsAt;
        $this->transactionEndsAt = isset($claims['transaction_ends_at']) ? CarbonImmutable::parse($claims['transaction_ends_at'])->utc() : $this->endsAt;
        $this->amount = isset($claims['amount']) ? (float) $claims['amount'] : null;
        $this->currency = $claims['currency'] ?? null;
        if (! in_array($this->provider, ['apple', 'google'], true) || ! in_array($this->environment, ['production', 'sandbox'], true)
            || ! in_array($this->state, ['active', 'grace', 'pending', 'expired', 'revoked', 'hold', 'paused'], true)
            || ($this->state !== 'pending' && (! $this->startsAt || ! $this->endsAt || $this->endsAt->lessThanOrEqualTo($this->startsAt)))) {
            throw new SubscriptionAccessException('INVALID_STORE_EVIDENCE', 422);
        }
    }
}
