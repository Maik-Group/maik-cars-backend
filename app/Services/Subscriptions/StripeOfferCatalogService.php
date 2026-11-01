<?php

namespace App\Services\Subscriptions;

use App\Models\BillingPlanPrice;
use App\Models\SubscriptionAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StripeOfferCatalogService
{
    public function createDraft(User $actor, array $attributes, string $reason): BillingPlanPrice
    {
        $this->authorize($actor, $reason);
        $data = Validator::make($attributes, [
            'offer_key' => ['required', 'alpha_dash', 'max:100'],
            'revision' => ['required', 'integer', 'min:1'],
            'environment' => ['required', Rule::in(['production', 'sandbox'])],
            'kind' => ['required', Rule::in(['subscription', 'topup'])],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'name' => ['required', 'string', 'max:255'],
            'duration_months' => ['nullable', 'integer', Rule::in([1, 3, 6, 12])],
            'credits' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'terms_version' => ['required', 'string', 'max:40'],
            'stripe_product_id' => ['nullable', 'string', 'max:255'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
        ])->validated();
        if (($data['kind'] === 'subscription') !== isset($data['duration_months'])) {
            throw ValidationException::withMessages(['duration_months' => 'Subscriptions require a duration; top-ups cannot have one.']);
        }

        return DB::transaction(function () use ($actor, $data, $reason): BillingPlanPrice {
            $offer = BillingPlanPrice::query()->create([...$data, 'offer_snapshot' => $this->snapshot($data)]);
            $this->audit($actor, $offer, 'create_stripe_offer_draft', $reason);

            return $offer;
        });
    }

    public function verifyAndPublish(User $actor, BillingPlanPrice $offer, string $reason, StripeBillingGateway $gateway): BillingPlanPrice
    {
        $this->authorize($actor, $reason);
        $offer->refresh();
        if ($offer->published_at || $offer->retired_at || ! $offer->stripe_price_id || ! $offer->stripe_product_id) {
            throw ValidationException::withMessages(['stripe_price_id' => 'A new draft with both Stripe identifiers is required.']);
        }
        $remote = $gateway->price($offer->stripe_price_id);
        $product = $remote['product'] ?? null;
        if (is_array($product)) {
            $product = $product['id'] ?? null;
        }
        $recurring = $remote['recurring'] ?? null;
        $matches = ($remote['id'] ?? null) === $offer->stripe_price_id
            && ($remote['active'] ?? false) === true
            && $product === $offer->stripe_product_id
            && ($remote['currency'] ?? null) === strtolower($offer->currency)
            && is_numeric($remote['unit_amount'] ?? null)
            && (int) $remote['unit_amount'] === $offer->amount_minor
            && (($offer->kind === 'subscription'
                && ($recurring['interval'] ?? null) === 'month'
                && (int) ($recurring['interval_count'] ?? 0) === $offer->duration_months
                && ($remote['type'] ?? null) === 'recurring')
                || ($offer->kind === 'topup' && empty($recurring) && ($remote['type'] ?? null) === 'one_time'));
        if (! $matches) {
            throw ValidationException::withMessages(['stripe_price_id' => 'Stripe Price differs from the local immutable offer.']);
        }

        return DB::transaction(function () use ($actor, $offer, $reason): BillingPlanPrice {
            $locked = BillingPlanPrice::query()->lockForUpdate()->findOrFail($offer->getKey());
            if ($locked->published_at || $locked->retired_at || $locked->updated_at?->ne($offer->updated_at)) {
                throw ValidationException::withMessages(['stripe_price_id' => 'Offer changed during verification; retry.']);
            }
            $locked->forceFill(['verified_at' => now(), 'published_at' => now(), 'offer_snapshot' => $this->snapshot($locked->getAttributes())])->save();
            $this->audit($actor, $locked, 'publish_stripe_offer', $reason);

            return $locked->refresh();
        });
    }

    public function retire(User $actor, BillingPlanPrice $offer, string $reason): BillingPlanPrice
    {
        $this->authorize($actor, $reason);

        return DB::transaction(function () use ($actor, $offer, $reason): BillingPlanPrice {
            $locked = BillingPlanPrice::query()->lockForUpdate()->findOrFail($offer->getKey());
            if (! $locked->published_at || $locked->retired_at) {
                throw ValidationException::withMessages(['offer_key' => 'Only published active offers can be retired.']);
            }
            $locked->forceFill(['retired_at' => now()])->save();
            $this->audit($actor, $locked, 'retire_stripe_offer', $reason);

            return $locked;
        });
    }

    private function authorize(User $actor, string $reason): void
    {
        if (! $actor->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor)->authorize('manage_subscription_products');
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
    }

    private function snapshot(array $values): array
    {
        return collect($values)->only(['offer_key', 'revision', 'environment', 'kind', 'subscription_plan_id', 'name', 'duration_months', 'credits', 'currency', 'amount_minor', 'terms_version', 'stripe_product_id', 'stripe_price_id'])->all();
    }

    private function audit(User $actor, BillingPlanPrice $offer, string $action, string $reason): void
    {
        SubscriptionAuditLog::query()->create(['actor_id' => $actor->getKey(), 'action' => $action, 'subject_type' => BillingPlanPrice::class, 'subject_id' => $offer->getKey(), 'reason' => $reason, 'before' => null, 'after' => $offer->toArray()]);
    }
}
