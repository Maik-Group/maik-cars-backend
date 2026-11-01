<?php

namespace App\Services\Credits;

use App\Models\ComplimentaryAllocation;
use App\Models\CreditGrant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ComplimentaryCreditService
{
    public function enroll(User $user, int $credits, int $months, ?CarbonImmutable $startsAt = null, ?CarbonImmutable $endsAt = null, string $mode = 'per_period', ?SubscriptionPlan $plan = null, ?string $environment = null): ComplimentaryAllocation
    {
        $startsAt ??= CarbonImmutable::now('UTC');
        if ($credits < 1 || ! in_array($months, [1, 3, 6, 12], true) || ! in_array($mode, ['once', 'per_period'], true)
            || ! $endsAt || ! $endsAt->greaterThan($startsAt)) {
            throw new \InvalidArgumentException('Invalid allocation');
        }

        return ComplimentaryAllocation::query()->create([
            'user_id' => $user->getKey(), 'credits' => $credits, 'months' => $months,
            'environment' => $environment ?? config('stripe_billing.environment'),
            'starts_at' => $startsAt, 'ends_at' => $endsAt, 'issuance_mode' => $mode,
            'subscription_plan_id' => $plan?->id,
            'plan_snapshot' => $plan ? ['key' => $plan->key, 'name' => $plan->name, 'revision' => $plan->revision, 'duration_months' => $plan->duration_months] : null,
        ]);
    }

    public function allocateDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $count = 0;
        $environment = config('stripe_billing.environment');
        ComplimentaryAllocation::query()->where('environment', $environment)->whereNull('revoked_at')->where('starts_at', '<=', $now)
            ->whereNotNull('ends_at')->where('ends_at', '>', $now)
            ->orderBy('id')->chunkById(100, function ($allocations) use ($now, &$count): void {
                foreach ($allocations as $allocation) {
                    $user = User::find($allocation->user_id);
                    if (! $user || ! $user->is_active) {
                        continue;
                    }
                    $start = $allocation->starts_at->utc();
                    $months = (int) $allocation->months;
                    $period = 0;
                    if ($allocation->issuance_mode === 'per_period') {
                        $monthDifference = ($now->year - $start->year) * 12 + $now->month - $start->month;
                        $period = max(0, intdiv(max(0, $monthDifference), $months));
                        while ($period > 0 && $start->addMonthsNoOverflow($period * $months)->greaterThan($now)) {
                            $period--;
                        }
                        while ($start->addMonthsNoOverflow(($period + 1) * $months)->lessThanOrEqualTo($now)) {
                            $period++;
                        }
                    }
                    $anchor = $start->addMonthsNoOverflow($period * $months);
                    $expiresAt = $start->addMonthsNoOverflow(($period + 1) * $months)->min($allocation->ends_at);
                    if ($now->lessThan($anchor) || $now->greaterThanOrEqualTo($expiresAt)) {
                        continue;
                    }
                    $key = 'allocation:'.$allocation->id.':period:'.$period;
                    $exists = CreditGrant::query()->where('environment', $allocation->environment)->where('source_type', 'complimentary')->where('source_key', $key)->exists();
                    if ($exists) {
                        continue;
                    }
                    app(CreditWalletService::class)->grant($user, 'complimentary', $allocation->credits, $key, $expiresAt, $allocation->environment);
                    $count++;
                }
            });

        return $count;
    }

    public function grantFree(User $user, int $credits, ?CarbonImmutable $expiresAt = null, ?string $key = null): CreditGrant
    {
        return app(CreditWalletService::class)->grant($user, 'free_grant', $credits, $key ?? 'admin:'.Str::uuid(), $expiresAt);
    }
}
