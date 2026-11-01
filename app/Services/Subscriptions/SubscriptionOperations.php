<?php

namespace App\Services\Subscriptions;

use App\Jobs\ProcessSubscriptionEvent;
use App\Jobs\ReconcileSubscription;
use App\Models\BillingAgreement;
use App\Models\SubscriptionAuditLog;
use App\Models\SubscriptionEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class SubscriptionOperations
{
    public function reconcile(User $actor, BillingAgreement $agreement, string $reason): void
    {
        DB::transaction(function () use ($actor, $agreement, $reason): void {
            $this->audit($actor, $agreement, $reason);
            ReconcileSubscription::dispatch($agreement->getKey())->afterCommit();
        });
    }

    public function replay(User $actor, SubscriptionEvent $event, string $reason): void
    {
        DB::transaction(function () use ($actor, $event, $reason): void {
            $this->audit($actor, $event, $reason);
            $locked = SubscriptionEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $locked->update(['state' => 'pending', 'error_code' => null, 'processed_at' => null]);
            ProcessSubscriptionEvent::dispatch($event->getKey(), forceVerification: true)->afterCommit();
        });
    }

    private function audit(User $actor, BillingAgreement|SubscriptionEvent $subject, string $reason): void
    {
        if (! $actor->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor)->authorize('reconcile_subscriptions');
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
        SubscriptionAuditLog::query()->create(['actor_id' => $actor->getKey(), 'user_id' => $subject->user_id, 'action' => 'request_reconciliation', 'subject_type' => $subject::class, 'subject_id' => $subject->getKey(), 'reason' => $reason]);
    }
}
