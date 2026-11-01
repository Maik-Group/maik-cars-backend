<?php

namespace App\Policies;

use App\Models\SubscriptionEntitlement;
use App\Models\User;

class SubscriptionEntitlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can('view_subscriptions');
    }

    public function view(User $user, SubscriptionEntitlement $entitlement): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SubscriptionEntitlement $entitlement): bool
    {
        return false;
    }

    public function delete(User $user, SubscriptionEntitlement $entitlement): bool
    {
        return false;
    }
}
