<?php

namespace App\Policies;

use App\Models\BillingAgreement;
use App\Models\User;

class BillingAgreementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can('view_subscriptions');
    }

    public function view(User $user, BillingAgreement $agreement): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BillingAgreement $agreement): bool
    {
        return false;
    }

    public function delete(User $user, BillingAgreement $agreement): bool
    {
        return false;
    }
}
