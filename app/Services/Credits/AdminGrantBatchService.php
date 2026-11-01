<?php

namespace App\Services\Credits;

use App\Jobs\ProcessAdminGrantBatch;
use App\Models\AdminGrantBatch;
use App\Models\AdminGrantRecipient;
use App\Models\ComplimentaryAllocation;
use App\Models\CreditLedgerEntry;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminGrantBatchService
{
    private function authorize(User $actor, string $kind = 'free'): void
    {
        abort_unless($actor->fresh()?->is_active && $actor->fresh()->can('grant_bulk_credits')
            && ($kind !== 'complimentary' || $actor->fresh()->can('grant_complimentary_credits')), 403);
    }

    public function preview(User $actor, string $audience, array $ids, int $credits, ?CarbonImmutable $expiresAt): AdminGrantBatch
    {
        return $this->previewOperation($actor, 'free', $audience, $ids, $credits, $expiresAt);
    }

    public function previewComplimentary(User $actor, string $audience, array $ids, int $credits, int $months, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $mode, int $planId): AdminGrantBatch
    {
        return $this->previewOperation($actor, 'complimentary', $audience, $ids, $credits, null, $months, $startsAt, $endsAt, $mode, $planId);
    }

    private function previewOperation(User $actor, string $kind, string $audience, array $ids, int $credits, ?CarbonImmutable $expiresAt, ?int $months = null, ?CarbonImmutable $startsAt = null, ?CarbonImmutable $endsAt = null, ?string $mode = null, ?int $planId = null): AdminGrantBatch
    {
        $this->authorize($actor, $kind);
        if (! in_array($audience, ['all', 'selected'], true) || $credits < 1) {
            throw new \InvalidArgumentException('Invalid batch');
        }
        if ($kind === 'complimentary' && (! in_array($months, [1, 3, 6, 12], true)
            || ! in_array($mode, ['once', 'per_period'], true) || ! $startsAt || ! $endsAt || ! $endsAt->greaterThan($startsAt))) {
            throw new \InvalidArgumentException('Invalid complimentary batch terms');
        }
        $plan = $kind === 'complimentary' ? SubscriptionPlan::query()->findOrFail($planId) : null;
        $planSnapshot = $plan ? ['key' => $plan->key, 'name' => $plan->name, 'revision' => $plan->revision, 'duration_months' => $plan->duration_months] : null;
        $environment = config('stripe_billing.environment');
        $query = User::query()->appUsers()->where('is_active', true);
        if ($audience === 'selected') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
            if (! $ids) {
                throw new \InvalidArgumentException('No selected users');
            }
            $query->whereKey($ids);
        }

        return DB::transaction(function () use ($actor, $kind, $audience, $credits, $expiresAt, $months, $startsAt, $endsAt, $mode, $query, $plan, $planSnapshot, $environment): AdminGrantBatch {
            // Bound the eligible snapshot before scanning so new registrations cannot enter it.
            $upperId = (int) (clone $query)->max('users.id');
            if ($upperId === 0) {
                throw new \InvalidArgumentException('Empty batch');
            }
            $batch = AdminGrantBatch::query()->create([
                'actor_id' => $actor->id, 'operation_key' => (string) Str::uuid(), 'audience' => $audience,
                'credits' => $credits, 'expires_at' => $expiresAt, 'snapshot_hash' => str_repeat('0', 64),
                'operation_kind' => $kind, 'months' => $months, 'starts_at' => $startsAt,
                'ends_at' => $endsAt, 'issuance_mode' => $mode,
                'subscription_plan_id' => $plan?->id, 'plan_snapshot' => $planSnapshot,
                'environment' => $environment,
                'recipient_count' => 0, 'status' => 'preview',
            ]);
            $digest = hash_init('sha256');
            hash_update($digest, json_encode($kind === 'free'
                ? [$audience, $credits, $expiresAt?->getTimestamp(), $environment]
                : [$kind, $audience, $credits, $months, $startsAt?->getTimestamp(), $endsAt?->getTimestamp(), $mode, $plan?->id, $planSnapshot, $environment], JSON_THROW_ON_ERROR).':');
            $count = 0;
            (clone $query)->where('users.id', '<=', $upperId)->select('users.id')
                ->chunkById(100, function ($users) use ($batch, $digest, &$count): void {
                    $timestamp = now();
                    $rows = [];
                    foreach ($users as $user) {
                        hash_update($digest, (string) $user->id.',');
                        $rows[] = [
                            'batch_id' => $batch->id, 'user_id' => $user->id, 'status' => 'pending',
                            'created_at' => $timestamp, 'updated_at' => $timestamp,
                        ];
                        $count++;
                    }
                    AdminGrantRecipient::query()->insert($rows);
                }, 'users.id', 'id');
            if ($count === 0) {
                throw new \InvalidArgumentException('Empty batch');
            }
            // Snapshot identity is finalized once, bypassing the model's immutable update guard.
            DB::table('admin_grant_batches')->where('id', $batch->id)->update([
                'snapshot_hash' => hash_final($digest), 'recipient_count' => $count,
            ]);

            return $batch->fresh();
        });
    }

    public function confirm(User $actor, AdminGrantBatch $batch, string $snapshotHash): void
    {
        $this->authorize($actor, $batch->operation_kind);
        abort_unless($batch->actor_id === $actor->id && hash_equals($batch->snapshot_hash, $snapshotHash), 409);
        DB::transaction(function () use ($batch): void {
            $locked = AdminGrantBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status === 'preview', 409);
            $locked->update(['status' => 'running', 'confirmed_at' => now()]);
            ProcessAdminGrantBatch::dispatch($locked->id)->afterCommit();
        });
    }

    public function resume(User $actor, AdminGrantBatch $batch): void
    {
        $this->authorize($actor, $batch->operation_kind);
        abort_unless($batch->actor_id === $actor->id && in_array($batch->status, ['paused', 'running'], true), 409);
        DB::transaction(function () use ($batch): void {
            $locked = AdminGrantBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($locked->status, ['paused', 'running'], true), 409);
            $locked->recipients()->where('status', 'failed')->update(['status' => 'pending']);
            $locked->update(['status' => 'running']);
        });
        ProcessAdminGrantBatch::dispatch($batch->id);
    }

    public function cancel(User $actor, AdminGrantBatch $batch): void
    {
        $this->authorize($actor, $batch->operation_kind);
        abort_unless($batch->actor_id === $actor->id, 403);
        DB::transaction(function () use ($batch): void {
            $locked = AdminGrantBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($locked->status, ['preview', 'running', 'paused'], true), 409);
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $locked->recipients()->whereIn('status', ['pending', 'failed'])->update(['status' => 'cancelled']);
        });
    }

    public function processChunk(int $batchId): void
    {
        $batch = AdminGrantBatch::findOrFail($batchId);
        if ($batch->status !== 'running') {
            return;
        }
        $actor = User::find($batch->actor_id);
        if (! $actor?->is_active || ! $actor->can('grant_bulk_credits') || ($batch->operation_kind === 'complimentary' && ! $actor->can('grant_complimentary_credits'))) {
            $batch->update(['status' => 'paused']);

            return;
        }
        $recipients = $batch->recipients()->where('status', 'pending')->orderBy('id')->limit(50)->pluck('id');
        foreach ($recipients as $recipientId) {
            try {
                DB::transaction(function () use ($batchId, $recipientId): void {
                    $lockedBatch = AdminGrantBatch::query()->lockForUpdate()->findOrFail($batchId);
                    $actor = User::find($lockedBatch->actor_id);
                    if ($lockedBatch->status !== 'running' || ! $actor?->is_active || ! $actor->can('grant_bulk_credits')
                        || ($lockedBatch->operation_kind === 'complimentary' && ! $actor->can('grant_complimentary_credits'))) {
                        if ($lockedBatch->status === 'running') {
                            $lockedBatch->update(['status' => 'paused']);
                        }

                        return;
                    }
                    $recipient = AdminGrantRecipient::query()->lockForUpdate()->findOrFail($recipientId);
                    if (! in_array($recipient->status, ['pending', 'failed'], true)) {
                        return;
                    }
                    $user = User::query()->whereKey($recipient->user_id)->lockForUpdate()->firstOrFail();
                    if (! User::query()->appUsers()->where('is_active', true)->whereKey($user->id)->exists()) {
                        $recipient->update(['status' => 'cancelled', 'error' => 'Recipient no longer eligible']);

                        return;
                    }
                    if ($lockedBatch->operation_kind === 'complimentary') {
                        $key = 'admin-batch:'.$lockedBatch->id.':user:'.$user->id;
                        ComplimentaryAllocation::query()->firstOrCreate(['batch_recipient_key' => $key], [
                            'user_id' => $user->id, 'credits' => $lockedBatch->credits, 'months' => $lockedBatch->months,
                            'starts_at' => $lockedBatch->starts_at, 'ends_at' => $lockedBatch->ends_at,
                            'issuance_mode' => $lockedBatch->issuance_mode,
                            'subscription_plan_id' => $lockedBatch->subscription_plan_id, 'plan_snapshot' => $lockedBatch->plan_snapshot,
                            'environment' => $lockedBatch->environment,
                        ]);
                    } else {
                        app(CreditWalletService::class)->grant($user, 'free_grant', $lockedBatch->credits, 'admin-batch:'.$lockedBatch->id.':user:'.$user->id, $lockedBatch->expires_at, $lockedBatch->environment);
                    }
                    CreditLedgerEntry::query()->create([
                        'user_id' => $user->id, 'entry_type' => 'admin_audit',
                        'amount' => 0, 'reference' => 'batch:'.$lockedBatch->id.':actor:'.$actor->id,
                        'created_at' => now('UTC'),
                    ]);
                    $recipient->update(['status' => 'completed', 'processed_at' => now(), 'error' => null, 'attempts' => $recipient->attempts + 1]);
                }, 3);
            } catch (\Throwable $exception) {
                AdminGrantRecipient::query()->whereKey($recipientId)->whereIn('status', ['pending', 'failed'])
                    ->update(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 180), 'attempts' => DB::raw('attempts + 1')]);
            }
        }
        $batch->refresh();
        if ($batch->status !== 'running') {
            return;
        }
        if ($batch->recipients()->where('status', 'pending')->exists()) {
            ProcessAdminGrantBatch::dispatch($batchId);
        } else {
            $batch->update(['status' => $batch->recipients()->where('status', 'failed')->exists() ? 'paused' : 'completed']);
        }
    }
}
