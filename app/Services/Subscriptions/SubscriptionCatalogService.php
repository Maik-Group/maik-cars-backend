<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionAccessException;
use App\Models\PlanStoreProduct;
use App\Models\SubscriptionAuditLog;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubscriptionCatalogService
{
    public function savePlan(User $actor, ?SubscriptionPlan $plan, array $attributes, string $reason): SubscriptionPlan
    {
        $this->authorize($actor, 'manage_subscription_plans');
        $this->reason($reason);
        $validated = Validator::make($attributes, [
            'key' => ['required', 'alpha_dash', 'max:100', Rule::unique('subscription_plans', 'key')->ignore($plan?->getKey())],
            'name' => ['required', 'string', 'max:255'], 'tier_key' => ['required', 'alpha_dash', 'max:100'],
            'duration_months' => ['required', 'integer', Rule::in([1, 3, 6, 12])], 'service_level' => ['required', 'integer', 'min:1', 'max:1000'],
            'daily_allowance' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'credits' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000'], 'features.valuation' => ['required', 'boolean'],
            'descriptions' => ['nullable', 'array'], 'descriptions.*' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['required', 'boolean'], 'is_visible' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'min:0'],
        ])->validated();

        return DB::transaction(function () use ($actor, $plan, $validated, $reason): SubscriptionPlan {
            $plan = $plan ? SubscriptionPlan::query()->lockForUpdate()->findOrFail($plan->getKey()) : null;
            $before = $plan?->toArray();
            $plan ??= new SubscriptionPlan;
            $plan->fill($validated)->save();
            $this->audit($actor, $plan, $reason, $before);

            return $plan->refresh();
        });
    }

    public function createMapping(User $actor, SubscriptionPlan $plan, array $attributes, string $reason): PlanStoreProduct
    {
        $this->authorize($actor, 'manage_subscription_products');
        $this->reason($reason);
        $validated = Validator::make($attributes, [
            'platform' => ['required', 'in:ios,android'], 'environment' => ['required', 'in:production,sandbox'],
            'product_id' => ['required', 'string', 'max:180'],
            'base_plan_id' => ['required_if:platform,android', 'nullable', 'string', 'max:80', 'prohibited_if:platform,ios'],
        ])->validated();

        return DB::transaction(function () use ($actor, $plan, $validated, $reason): PlanStoreProduct {
            $product = $plan->storeProducts()->create([...$validated, 'base_plan_id' => $validated['base_plan_id'] ?? '', 'plan_revision' => $plan->revision]);
            $this->audit($actor, $product, $reason, null);

            return $product;
        });
    }

    public function verifyMapping(User $actor, PlanStoreProduct $product, string $reason): void
    {
        $this->authorize($actor, 'manage_subscription_products');
        $this->reason($reason);
        $plan = $product->plan()->firstOrFail();
        $revision = $plan->revision;
        try {
            $metadata = $product->platform === 'ios'
                ? app(AppleBillingProvider::class)->catalog($product->product_id, $product->environment)
                : app(GooglePlayBillingProvider::class)->catalog($product->product_id, $product->base_plan_id);
            $matches = ($metadata['available'] ?? false) === true && ($metadata['product_id'] ?? '') === $product->product_id
                && ($metadata['duration_months'] ?? null) === $plan->duration_months;
            if ($product->platform === 'ios') {
                $matches = $matches && ($metadata['family_sharing'] ?? true) === false && ($metadata['service_level'] ?? null) === (int) $plan->service_level;
            }
            $state = $matches ? 'verified' : 'mismatch';
        } catch (SubscriptionAccessException) {
            $metadata = [];
            $state = 'unavailable';
        }
        DB::transaction(function () use ($actor, $product, $reason, $metadata, $state, $revision): void {
            $plan = SubscriptionPlan::query()->lockForUpdate()->findOrFail($product->subscription_plan_id);
            $product = PlanStoreProduct::query()->lockForUpdate()->findOrFail($product->getKey());
            if ($plan->revision !== $revision) {
                $state = 'mismatch';
            }
            $before = $product->toArray();
            $product->forceFill(['validation_state' => $state, 'expected_metadata' => $metadata, 'plan_revision' => $revision, 'checked_at' => now(), 'mismatch_reason' => $state === 'verified' ? null : strtoupper($state)])->save();
            $this->audit($actor, $product, $reason, $before);
        });
    }

    private function authorize(User $actor, string $ability): void
    {
        if (! $actor->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor)->authorize($ability);
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
    }

    private function audit(User $actor, SubscriptionPlan|PlanStoreProduct $subject, string $reason, ?array $before): void
    {
        SubscriptionAuditLog::query()->create(['actor_id' => $actor->getKey(), 'action' => 'configure_catalog', 'subject_type' => $subject::class, 'subject_id' => $subject->getKey(), 'reason' => $reason, 'before' => $before, 'after' => $subject->toArray()]);
    }
}
