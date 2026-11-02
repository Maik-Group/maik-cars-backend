<?php

namespace App\Services\Credits;

use App\Models\CreditConsumption;
use App\Models\CreditGrant;
use App\Models\CreditLedgerEntry;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreditWalletService
{
    public function grant(User $user, string $sourceType, int $credits, string $sourceKey, ?CarbonImmutable $expiresAt, ?string $environment = null): CreditGrant
    {
        if ($credits < 1 || trim($sourceKey) === '') {
            throw new \InvalidArgumentException('Invalid credit grant');
        }
        $environment ??= config('stripe_billing.environment');

        return DB::transaction(function () use ($user, $sourceType, $credits, $sourceKey, $expiresAt, $environment): CreditGrant {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = CreditGrant::query()->where('source_type', $sourceType)->where('source_key', $sourceKey)->where('environment', $environment)->first();
            if ($existing) {
                if ((int) $existing->user_id !== (int) $user->getKey() || $existing->credits !== $credits
                    || $existing->expires_at?->getTimestamp() !== $expiresAt?->getTimestamp()) {
                    throw new \LogicException('Conflicting credit grant identity');
                }

                return $existing;
            }
            $grant = CreditGrant::query()->create([
                'user_id' => $user->getKey(), 'source_type' => $sourceType, 'source_key' => $sourceKey,
                'environment' => $environment, 'credits' => $credits, 'remaining' => $credits, 'expires_at' => $expiresAt,
            ]);
            $this->ledger($user, $grant, 'grant', $credits, $sourceKey);

            return $grant;
        }, 3);
    }

    public function spend(User $user, Item $item, ?string $environment = null, bool $validateExistingOnly = false, ?string $idempotencyKey = null): array
    {
        $environment ??= config('stripe_billing.environment');
        $today = CarbonImmutable::now('UTC')->toDateString();
        if ($validateExistingOnly) {
            throw new HttpException(409, 'REVEAL_REQUIRED');
        }
        if ($idempotencyKey !== null && (strlen($idempotencyKey) < 12 || strlen($idempotencyKey) > 200)) {
            throw new HttpException(422, 'INVALID_IDEMPOTENCY_KEY');
        }
        // A fresh valuation always costs one credit regardless of its serial.
        // Only a retry carrying the same request key is idempotent.
        $fingerprint = hash('sha256', $idempotencyKey !== null
            ? 'valuation-request:'.$idempotencyKey
            : 'valuation-attempt:'.Str::uuid());

        return DB::transaction(function () use ($user, $item, $environment, $today, $fingerprint): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $receipt = CreditConsumption::query()->where('user_id', $user->getKey())->where('environment', $environment)
                ->where('usage_date', $today)->where('serial_fingerprint', $fingerprint)->first();
            if ($receipt && (string) $receipt->item_id === (string) $item->getKey()
                && CreditGrant::query()->whereKey($receipt->credit_grant_id)->whereNull('revoked_at')->exists()) {
                return ['counted' => false, 'receipt_id' => $receipt->id, 'remaining' => $this->balance($user, $environment)];
            }
            if ($receipt) {
                throw new HttpException(403, 'RECEIPT_REVOKED');
            }
            $grant = CreditGrant::query()->where('user_id', $user->getKey())->where('environment', $environment)
                ->whereNull('revoked_at')->whereNull('held_at')->where('remaining', '>', 0)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now('UTC')))
                ->orderByRaw("CASE WHEN source_type IN ('paid', 'paid_cycle') THEN 0 WHEN source_type IN ('free', 'complimentary', 'free_grant') THEN 1 ELSE 2 END")
                ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderBy('id')->lockForUpdate()->first();
            if (! $grant) {
                throw new HttpException(402, 'INSUFFICIENT_CREDITS');
            }
            $grant->decrement('remaining');
            $receipt = CreditConsumption::query()->create([
                'user_id' => $user->getKey(), 'credit_grant_id' => $grant->id,
                'usage_date' => $today, 'serial_fingerprint' => $fingerprint,
                'item_id' => $item->getKey(), 'environment' => $environment,
            ]);
            $this->ledger($user, $grant, 'spend', -1, (string) $receipt->id, $receipt->id);

            return ['counted' => true, 'receipt_id' => $receipt->id, 'remaining' => $this->balance($user, $environment)];
        }, 3);
    }

    public function balance(User $user, ?string $environment = null): int
    {
        return (int) CreditGrant::query()->where('user_id', $user->getKey())
            ->where('environment', $environment ?? config('stripe_billing.environment'))
            ->whereNull('revoked_at')->whereNull('held_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now('UTC')))
            ->sum('remaining');
    }

    public function revoke(CreditGrant $grant, string $reason): void
    {
        DB::transaction(function () use ($grant, $reason): void {
            User::query()->whereKey($grant->user_id)->lockForUpdate()->firstOrFail();
            $locked = CreditGrant::query()->lockForUpdate()->findOrFail($grant->id);
            if ($locked->revoked_at) {
                return;
            }
            $remaining = $locked->remaining;
            $locked->update(['revoked_at' => now('UTC'), 'remaining' => 0]);
            if ($remaining > 0) {
                $this->ledger(User::findOrFail($grant->user_id), $locked, 'revoke', -$remaining, $reason);
            }
        }, 3);
    }

    public function hold(CreditGrant $grant, string $reason): void
    {
        $this->setHold($grant, $reason, true);
    }

    public function releaseHold(CreditGrant $grant, string $reason): void
    {
        $this->setHold($grant, $reason, false);
    }

    private function setHold(CreditGrant $grant, string $reason, bool $hold): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Hold reason required');
        }
        DB::transaction(function () use ($grant, $reason, $hold): void {
            $user = User::query()->whereKey($grant->user_id)->lockForUpdate()->firstOrFail();
            $locked = CreditGrant::query()->lockForUpdate()->findOrFail($grant->id);
            if ($locked->revoked_at || ($hold && $locked->held_at) || (! $hold && ! $locked->held_at)) {
                return;
            }
            $locked->update(['held_at' => $hold ? now('UTC') : null]);
            $this->ledger($user, $locked, $hold ? 'hold' : 'release_hold', 0, $reason);
        }, 3);
    }

    public function expireDue(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now('UTC');
        $expired = 0;
        CreditGrant::query()->whereNull('revoked_at')->where('remaining', '>', 0)
            ->whereNotNull('expires_at')->where('expires_at', '<=', $asOf)
            ->orderBy('id')->chunkById(100, function ($grants) use (&$expired, $asOf): void {
                foreach ($grants as $grant) {
                    DB::transaction(function () use ($grant, $asOf, &$expired): void {
                        $user = User::query()->whereKey($grant->user_id)->lockForUpdate()->firstOrFail();
                        $locked = CreditGrant::query()->lockForUpdate()->findOrFail($grant->id);
                        if ($locked->remaining === 0 || $locked->revoked_at || ! $locked->expires_at || $locked->expires_at->greaterThan($asOf)) {
                            return;
                        }
                        $amount = $locked->remaining;
                        $locked->update(['remaining' => 0]);
                        $this->ledger($user, $locked, 'expire', -$amount, 'expired:'.$locked->id);
                        $expired++;
                    }, 3);
                }
            });

        return $expired;
    }

    private function ledger(User $user, CreditGrant $grant, string $type, int $amount, string $reference, ?int $consumptionId = null): void
    {
        CreditLedgerEntry::query()->create([
            'user_id' => $user->getKey(), 'credit_grant_id' => $grant->id,
            'credit_consumption_id' => $consumptionId, 'entry_type' => $type,
            'amount' => $amount, 'reference' => $reference, 'created_at' => now('UTC'),
        ]);
    }
}
