<?php

namespace App\Services\Credits;

use App\Models\CreditLedgerEntry;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscriptions\ActiveBillingPlan;
use App\Services\Subscriptions\AdminSubscriptionService;
use App\Services\Subscriptions\EntitlementResolver;
use App\Services\Subscriptions\GrantTerms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminUserGrantService
{
    /**
     * @param  array<int, int|string>  $ids
     * @return array{granted:int,skipped:int}
     */
    public function credits(User $actor, string $scope, array $ids, int $credits): array
    {
        $canGrant = $scope === 'all' || count($ids) > 1
            ? $actor->can('grant_bulk_credits')
            : ($actor->can('grant_free_credits') || $actor->can('grant_bulk_credits'));
        abort_unless($actor->is_active && $canGrant, 403);
        if ($credits < 1 || $credits > 1000000) {
            throw ValidationException::withMessages(['credits' => 'Choose between 1 and 1,000,000 credits.']);
        }

        $batchKey = (string) Str::uuid();
        $granted = 0;
        $this->recipients($scope, $ids, function (User $user) use ($actor, &$granted, $credits, $batchKey): void {
            DB::transaction(function () use ($actor, $user, $credits, $batchKey, &$granted): void {
                app(CreditWalletService::class)->grant($user, 'free_grant', $credits, 'admin-ui:'.$batchKey.':'.$user->id, null);
                CreditLedgerEntry::query()->create([
                    'user_id' => $user->id,
                    'entry_type' => 'admin_audit',
                    'amount' => 0,
                    'reference' => 'admin-ui:'.$batchKey.':actor:'.$actor->id,
                    'created_at' => now('UTC'),
                ]);
                $granted++;
            });
        });

        return ['granted' => $granted, 'skipped' => 0];
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{granted:int,skipped:int}
     */
    public function subscriptions(User $actor, string $scope, array $ids, SubscriptionPlan $plan): array
    {
        abort_unless($actor->is_active && $actor->can('grant_subscriptions')
            && ($scope !== 'selected' || count($ids) <= 1 || $actor->can('grant_bulk_credits'))
            && ($scope !== 'all' || $actor->can('grant_bulk_credits')), 403);

        $plan->refresh();
        if (! $plan->is_active || (int) $plan->credits < 1) {
            throw ValidationException::withMessages(['plan_id' => 'Select an active subscription plan with credits.']);
        }

        $granted = 0;
        $skipped = 0;
        $now = CarbonImmutable::now('UTC');
        $environment = (string) config('stripe_billing.environment');

        $this->recipients($scope, $ids, function (User $user) use ($actor, $plan, $now, $environment, &$granted, &$skipped): void {
            DB::transaction(function () use ($actor, $plan, $now, $environment, $user, &$granted, &$skipped): void {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (ActiveBillingPlan::endsAt($user, $environment) !== null
                    || app(EntitlementResolver::class)->activeSources($user, $now)->isNotEmpty()) {
                    $skipped++;

                    return;
                }

                $terms = GrantTerms::forPlan($plan, $now, 'Granted by administrator');
                $entitlement = app(AdminSubscriptionService::class)->grant($actor, $user, $plan, $terms);
                app(CreditWalletService::class)->grant(
                    $user, 'complimentary', (int) $plan->credits,
                    'admin-subscription:'.$entitlement->id, $terms->endsAt, $environment
                );
                $granted++;
            });
        });

        return ['granted' => $granted, 'skipped' => $skipped];
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    private function recipients(string $scope, array $ids, callable $callback): void
    {
        if (! in_array($scope, ['all', 'selected'], true)) {
            throw ValidationException::withMessages(['scope' => 'Select recipients.']);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($scope === 'selected' && (! $ids || in_array(0, $ids, true))) {
            throw ValidationException::withMessages(['user_ids' => 'Select at least one user.']);
        }

        $query = User::query()->appUsers()->where('users.is_active', true);
        if ($scope === 'selected') {
            $query->whereKey($ids);
        }
        if (! $query->exists()) {
            throw ValidationException::withMessages(['user_ids' => 'No eligible active app users found.']);
        }

        $query->select('users.*')->chunkById(100, function ($users) use ($callback): void {
            foreach ($users as $user) {
                $callback($user);
            }
        }, 'users.id', 'id');
    }
}
