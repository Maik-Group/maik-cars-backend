<?php

namespace App\Services\Subscriptions;

use App\Models\BillingOrder;
use App\Models\BillingSubscription;
use App\Models\CreditGrant;
use App\Models\SubscriptionEntitlement;
use App\Models\User;
use Illuminate\Support\Carbon;

class ActiveBillingPlan
{
    public static function endsAt(User $user, string $environment): ?Carbon
    {
        $now = now('UTC');
        $recurringEnd = BillingSubscription::query()
            ->where('user_id', $user->getKey())->where('environment', $environment)
            ->whereIn('status', ['active', 'trialing'])
            ->where('period_start', '<=', $now)->where('period_end', '>', $now)
            ->max('period_end');

        $prepaid = BillingOrder::query()
            ->where('user_id', $user->getKey())->where('environment', $environment)
            ->where('kind', 'subscription')->where('status', 'fulfilled')
            ->where('offer_snapshot->billingMode', 'prepaid_term')
            ->where('period_start', '<=', $now)->where('period_end', '>', $now)
            ->orderByDesc('period_end')->cursor()
            ->first(fn (BillingOrder $order) => CreditGrant::query()
                ->where('user_id', $user->getKey())->where('environment', $environment)
                ->where('source_type', 'paid_cycle')->where('source_key', 'stripe-term:'.$environment.':'.$order->getKey())
                ->whereNull('held_at')->whereNull('revoked_at')->exists());

        $grantedEnd = SubscriptionEntitlement::query()
            ->where('user_id', $user->getKey())->where('environment', $environment)
            ->where('source', 'grant')->whereNull('revoked_at')
            ->where('features->valuation', true)
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->max('ends_at');

        $end = max($recurringEnd ?? '', $prepaid?->period_end?->format('Y-m-d H:i:s') ?? '', $grantedEnd ?? '');

        return $end === '' ? null : Carbon::parse($end, 'UTC');
    }
}
