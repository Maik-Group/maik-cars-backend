<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionAccessException;
use App\Models\Setting;
use App\Models\SubscriptionEntitlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class EntitlementResolver
{
    private ?bool $enforcementLatched = null;

    public function environment(User $user): string
    {
        return in_array($user->getKey(), config('subscriptions.qa_user_ids', []), true)
            ? 'sandbox' : config('subscriptions.environment');
    }

    public function enforcementEnabled(): bool
    {
        $cutover = config('subscriptions.cutover_at');

        return ($this->enforcementLatched ??= Setting::query()->whereKey('subscriptions.enforcement_activated_at')->exists())
            || config('subscriptions.enforcement_enabled')
            && (! $cutover || CarbonImmutable::now('UTC')->greaterThanOrEqualTo(CarbonImmutable::parse($cutover, 'UTC')));
    }

    public function activeSources(User $user, CarbonImmutable $at): Collection
    {
        return SubscriptionEntitlement::query()->with('agreement')
            ->where('user_id', $user->getKey())->where('environment', $this->environment($user))
            ->whereNull('revoked_at')->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            ->get()->filter(fn (SubscriptionEntitlement $source): bool => ! in_array($source->source, ['transition', 'free', 'trial'], true)
                && ($source->features['valuation'] ?? false) === true
                && ($source->source !== 'paid' || ($source->agreement
                    && $source->agreement->user_id === $user->getKey()
                    && $source->agreement->suppressed_at === null
                    && in_array($source->agreement->provider_state, ['active', 'grace'], true))))->values();
    }

    public function resolve(User $user, ?CarbonImmutable $at = null): array
    {
        if (! $user->is_active) {
            throw new SubscriptionAccessException('ACCOUNT_INACTIVE', 403);
        }

        $at ??= CarbonImmutable::now('UTC');
        $sources = $this->activeSources($user, $at);
        $unlimited = $sources->contains(fn (SubscriptionEntitlement $source): bool => $source->daily_allowance === null);

        return [
            'active' => $sources->isNotEmpty(),
            'limit' => $unlimited ? null : (int) $sources->max('daily_allowance'),
            'sources' => $sources,
        ];
    }
}
