<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionAccessException;
use App\Models\DailyUsage;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DailyUsageService
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    public function claim(User $user, Item $item, ?string $revalidateDate = null): array
    {
        return DB::transaction(function () use ($user, $item, $revalidateDate): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $now = CarbonImmutable::now('UTC');
            $access = $this->entitlements->resolve($lockedUser, $now);
            if (! $access['active']) {
                throw new SubscriptionAccessException('SUBSCRIPTION_REQUIRED', 403);
            }

            $bucket = DailyUsage::query()->firstOrCreate([
                'user_id' => $user->getKey(), 'environment' => $this->entitlements->environment($lockedUser), 'usage_date' => $now->toDateString(),
            ], ['used' => 0]);
            $subject = $this->subjectFingerprint($item);
            $counted = ! $bucket->reveals()->where('subject_fingerprint', $subject)->exists();

            if ($revalidateDate !== null && ($revalidateDate !== $now->toDateString() || $counted)) {
                throw new SubscriptionAccessException('REVEAL_REQUIRED', 403);
            }

            if ($counted && $access['limit'] !== null && $bucket->used >= $access['limit']) {
                throw new SubscriptionAccessException('DAILY_QUOTA_EXHAUSTED', 429, ['usage' => $this->summary($access['limit'], $bucket->used, $now)]);
            }
            if ($counted) {
                $bucket->reveals()->create(['subject_fingerprint' => $subject, 'item_id' => $item->getKey()]);
                $bucket->increment('used');
            }

            $lastSourceExpiry = $access['sources']->min('ends_at');
            $leaseEnd = $now->addSeconds(config('subscriptions.display_lease_seconds'))
                ->min($now->addDay()->startOfDay())->min($lastSourceExpiry);

            return ['usage' => [...$this->summary($access['limit'], $bucket->used, $now), 'counted' => $counted], 'valid_until' => $leaseEnd->toIso8601String()];
        }, 3);
    }

    public function status(User $user, array $access): array
    {
        $now = CarbonImmutable::now('UTC');
        $used = (int) DailyUsage::query()->where('user_id', $user->getKey())
            ->where('environment', $this->entitlements->environment($user))->where('usage_date', $now->toDateString())->value('used');

        return $this->summary($access['limit'], $used, $now);
    }

    public function subjectFingerprint(Item $item): string
    {
        $serial = Item::normalizeSerialValue($item->normalized_serial ?: $item->serial_code);

        return hash('sha256', $serial !== '' ? 'serial:'.$serial : 'item:'.$item->getKey());
    }

    private function summary(?int $limit, int $used, CarbonImmutable $now): array
    {
        return ['limit' => $limit, 'used' => $used, 'remaining' => $limit === null ? null : max(0, $limit - $used), 'usage_date' => $now->toDateString(), 'resets_at' => $now->addDay()->startOfDay()->toIso8601String()];
    }
}
