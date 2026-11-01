<?php

namespace App\Policies;

use App\Models\SubscriptionAuditLog;
use App\Models\User;

class SubscriptionAuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can('view_subscriptions');
    }

    public function view(User $user, SubscriptionAuditLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SubscriptionAuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, SubscriptionAuditLog $log): bool
    {
        return false;
    }
}
