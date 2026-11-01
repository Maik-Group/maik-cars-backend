<?php

namespace App\Services\Subscriptions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingAccountService
{
    public function identifiers(User $user): array
    {
        $uuid = DB::transaction(function () use ($user): string {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if (! $lockedUser->billing_account_uuid) {
                $uuid = (string) Str::uuid();
                $lockedUser->forceFill(['billing_account_uuid' => $uuid, 'billing_account_hash' => hash('sha256', $uuid)])->save();
            } elseif (! $lockedUser->billing_account_hash) {
                $lockedUser->forceFill(['billing_account_hash' => hash('sha256', $lockedUser->billing_account_uuid)])->save();
            }

            return $lockedUser->billing_account_uuid;
        }, 3);

        return ['apple' => $uuid, 'google' => hash('sha256', $uuid)];
    }
}
