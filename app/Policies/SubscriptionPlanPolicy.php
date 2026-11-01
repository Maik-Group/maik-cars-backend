<?php

namespace App\Policies;

use App\Models\SubscriptionPlan;
use App\Models\User;

class SubscriptionPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can('view_subscriptions');
    }

    public function view(User $user, SubscriptionPlan $plan): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->can('manage_subscription_plans');
    }

    public function update(User $user, SubscriptionPlan $plan): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, SubscriptionPlan $plan): bool
    {
        return false;
    }
}
