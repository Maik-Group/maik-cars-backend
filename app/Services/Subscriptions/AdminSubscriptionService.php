<?php

namespace App\Services\Subscriptions;

use App\Models\BillingAgreement;
use App\Models\SubscriptionAuditLog;
use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\SubscriptionAccessChanged;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminSubscriptionService
{
    public function grant(User $actor, User $recipient, SubscriptionPlan $plan, GrantTerms $terms): SubscriptionEntitlement
    {
        $this->authorize($actor, 'grant_subscriptions');

        return DB::transaction(function () use ($actor, $recipient, $plan, $terms): SubscriptionEntitlement {
            User::query()->lockForUpdate()->findOrFail($recipient->getKey());
            $plan = SubscriptionPlan::query()->findOrFail($plan->getKey());
            $entitlement = SubscriptionEntitlement::query()->create([
                'user_id' => $recipient->getKey(), 'subscription_plan_id' => $plan->getKey(),
                'source' => 'grant', 'environment' => app(EntitlementResolver::class)->environment($recipient),
                'daily_allowance' => $plan->daily_allowance, 'features' => $plan->features,
                'starts_at' => $terms->startsAt, 'ends_at' => $terms->endsAt, 'actor_id' => $actor->getKey(), 'reason' => $terms->reason,
            ]);
            $this->audit($actor, $entitlement, 'grant', $terms->reason);
            DB::afterCommit(fn () => $recipient->notify(new SubscriptionAccessChanged));

            return $entitlement;
        }, 3);
    }

    public function revoke(User $actor, SubscriptionEntitlement $entitlement, string $reason): void
    {
        $this->authorize($actor, 'revoke_subscriptions');
        $this->requireReason($reason);
        DB::transaction(function () use ($actor, $entitlement, $reason): void {
            User::query()->lockForUpdate()->findOrFail($entitlement->user_id);
            $locked = SubscriptionEntitlement::query()->lockForUpdate()->findOrFail($entitlement->getKey());
            if ($locked->billing_agreement_id) {
                $locked->agreement()->firstOrFail()->update(['suppressed_at' => now(), 'suppression_reason' => $reason]);
            } else {
                $locked->update(['revoked_at' => now()]);
            }
            $this->audit($actor, $locked, 'revoke_local_access', $reason);
            DB::afterCommit(fn () => $locked->user->notify(new SubscriptionAccessChanged));
        }, 3);
    }

    public function restoreAgreement(User $actor, BillingAgreement $agreement, string $reason): void
    {
        $this->authorize($actor, 'revoke_subscriptions');
        $this->requireReason($reason);
        DB::transaction(function () use ($actor, $agreement, $reason): void {
            User::query()->lockForUpdate()->findOrFail($agreement->user_id);
            $agreement = BillingAgreement::query()->lockForUpdate()->findOrFail($agreement->getKey());
            $agreement->update(['suppressed_at' => null, 'suppression_reason' => null]);
            DB::afterCommit(fn () => $agreement->user->notify(new SubscriptionAccessChanged));
            SubscriptionAuditLog::query()->create([
                'actor_id' => $actor->getKey(), 'user_id' => $agreement->user_id, 'action' => 'restore_local_access',
                'subject_type' => BillingAgreement::class, 'subject_id' => $agreement->getKey(), 'reason' => $reason,
            ]);
        }, 3);
    }

    public function grantTransition(User $user, CarbonImmutable $cutover, string $reason): SubscriptionEntitlement
    {
        throw new \LogicException('Transition grants are disabled.');
    }

    private function audit(?User $actor, SubscriptionEntitlement $entitlement, string $action, string $reason): void
    {
        SubscriptionAuditLog::query()->create([
            'actor_id' => $actor?->getKey(), 'user_id' => $entitlement->user_id, 'action' => $action,
            'subject_type' => SubscriptionEntitlement::class, 'subject_id' => $entitlement->getKey(),
            'reason' => $reason, 'after' => $entitlement->only(['source', 'daily_allowance', 'features', 'starts_at', 'ends_at', 'revoked_at']),
        ]);
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'A reason of at most 255 characters is required.']);
        }
    }

    private function authorize(User $actor, string $ability): void
    {
        if (! $actor->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor)->authorize($ability);
    }
}
