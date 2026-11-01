<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SubscriptionAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ActivateSubscriptionEnforcement extends Command
{
    protected $signature = 'subscriptions:activate-enforcement {--reason= : Approved cutover change reference}';

    protected $description = 'Persist the approved catalog enforcement cutover; disabling purchases cannot reopen protected data';

    public function handle(): int
    {
        $reason = trim((string) $this->option('reason'));
        $cutover = config('subscriptions.cutover_at');
        if (! config('subscriptions.enforcement_enabled') || ! $cutover || $reason === '' || mb_strlen($reason) > 255
            || CarbonImmutable::parse($cutover, 'UTC')->isFuture()) {
            $this->error('Requires enforcement enabled, an effective configured cutover, and an approved reason (1–255 characters).');

            return self::FAILURE;
        }

        DB::transaction(function () use ($reason): void {
            $setting = Setting::query()->firstOrCreate(['key' => 'subscriptions.enforcement_activated_at'], ['value' => CarbonImmutable::now('UTC')->toIso8601String()]);
            if ($setting->wasRecentlyCreated) {
                SubscriptionAuditLog::query()->create([
                    'action' => 'activate_enforcement', 'subject_type' => Setting::class, 'subject_id' => 0,
                    'reason' => $reason, 'after' => ['activated_at' => $setting->value],
                ]);
            }
        });
        $this->info('Enforcement is latched. Purchase shutdown retains catalog protection.');

        return self::SUCCESS;
    }
}
