<?php

namespace Database\Seeders;

use App\Models\AdminGrantBatch;
use App\Models\BillingPlanPrice;
use App\Models\ComplimentaryAllocation;
use App\Models\CreditGrant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Credits\AdminGrantBatchService;
use App\Services\Credits\ComplimentaryCreditService;
use App\Services\Credits\CreditWalletService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Explicitly invoked on the user's existing LOCAL MySQL database.
 *
 * No existing user is changed, no Stripe payment identity is fabricated,
 * and no sample offer is published. Safe to re-run within the same QA period.
 */
class MaikCatQaBillingSeeder extends Seeder
{
    public const ACCOUNTS = [
        'empty' => 'qa-maik-empty@example.test',
        'free' => 'qa-maik-free@example.test',
        'paid' => 'qa-maik-paid@example.test',
        'topup' => 'qa-maik-topup@example.test',
        'mixed' => 'qa-maik-mixed@example.test',
        'complimentary' => 'qa-maik-complimentary@example.test',
        'once' => 'qa-maik-once@example.test',
        'expired' => 'qa-maik-expired@example.test',
        'held' => 'qa-maik-held@example.test',
        'bulk' => 'qa-maik-bulk@example.test',
        'admin' => 'qa-maik-admin@example.test',
        'renewed' => 'qa-maik-renewed@example.test',
        'renewal_due' => 'qa-maik-renewal-due@example.test',
        'expired_only' => 'qa-maik-expired-only@example.test',
        'topup_only' => 'qa-maik-topup-only@example.test',
    ];

    public function run(): void
    {
        if (! app()->environment('local')
            || DB::connection()->getDatabaseName() !== 'maik_cat_final_pro'
            || ! in_array((string) config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true)
            || config('stripe_billing.environment') !== 'sandbox') {
            throw new RuntimeException('QA seeder requires the approved local MySQL and Stripe test credentials.');
        }

        $file = storage_path('app/private/maik-qa-credentials.json');
        if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0700, true) && ! is_dir(dirname($file))) {
            throw new RuntimeException('Could not create private QA credential folder.');
        }
        $known = is_file($file) ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : null;
        if ($known === null) {
            // Never overwrite a possibly pre-existing user's password.
            if (User::query()->whereIn('email', array_values(self::ACCOUNTS))->exists()) {
                throw new RuntimeException('A QA email already exists without its original credential manifest.');
            }
            $known = [
                'purpose' => 'Maik Cat local sandbox QA accounts only',
                'createdAt' => CarbonImmutable::now('UTC')->toIso8601String(),
                'accounts' => [],
            ];
            foreach (self::ACCOUNTS as $scenario => $email) {
                $known['accounts'][$scenario] = ['email' => $email, 'password' => Str::random(28)];
            }
            $temp = $file.'.tmp';
            file_put_contents($temp, json_encode($known, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
            rename($temp, $file);
        }
        // Allow adding a new named QA scenario in later runs without changing
        // any previously issued credential or taking over an existing user.
        $manifestExtended = false;
        foreach (self::ACCOUNTS as $scenario => $email) {
            if (! isset($known['accounts'][$scenario])) {
                if (User::query()->where('email', $email)->exists()) {
                    throw new RuntimeException('New QA scenario already exists outside the manifest.');
                }
                $known['accounts'][$scenario] = ['email' => $email, 'password' => Str::random(28)];
                $manifestExtended = true;
            }
        }
        if ($manifestExtended) {
            $temp = $file.'.tmp';
            file_put_contents($temp, json_encode($known, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
            rename($temp, $file);
        }
        foreach (self::ACCOUNTS as $scenario => $email) {
            if (($known['accounts'][$scenario]['email'] ?? null) !== $email || ! filled($known['accounts'][$scenario]['password'] ?? null)) {
                throw new RuntimeException('QA credential manifest is incomplete or was modified.');
            }
        }

        $users = [];
        foreach (self::ACCOUNTS as $scenario => $email) {
            // firstOrCreate never touches an existing QA user's password,
            // device binding, subscriptions or other original profile fields.
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => 'Maik QA '.ucfirst($scenario),
                'password' => $known['accounts'][$scenario]['password'],
                'preferred_language' => 'en', 'is_active' => true,
                'email_verified_at' => now('UTC'), 'unlimited_devices' => true,
            ]);
            if (! $user->hasRole($scenario === 'admin' ? 'admin' : 'app_user')) {
                $user->assignRole($scenario === 'admin' ? 'admin' : 'app_user');
            }
            $users[$scenario] = $user;
        }

        // Four catalog terms; these are NOT actual merchant prices and will
        // remain unverified drafts with Checkout disabled.
        $terms = [
            ['months' => 1, 'credits' => 15, 'minor' => 999],
            ['months' => 3, 'credits' => 50, 'minor' => 2499],
            ['months' => 6, 'credits' => 110, 'minor' => 4499],
            ['months' => 12, 'credits' => 240, 'minor' => 7999],
        ];
        $plans = [];
        foreach ($terms as $term) {
            $months = $term['months'];
            $key = 'qa-wallet-'.$months.'m';
            $plans[$months] = SubscriptionPlan::query()->firstOrCreate(['key' => $key], [
                'name' => 'QA '.$months.'-month credit plan (not for sale)',
                'tier_key' => 'qa-'.$months.'m',
                'duration_months' => $months, 'service_level' => 1,
                'daily_allowance' => null, 'features' => ['valuation' => true],
                'descriptions' => ['en' => 'Local QA only. No real payment.'],
                'is_active' => true, 'is_visible' => false, 'sort_order' => 1000 + $months,
            ]);
            BillingPlanPrice::query()->firstOrCreate([
                'environment' => 'sandbox', 'offer_key' => $key, 'revision' => 1,
            ], [
                'subscription_plan_id' => $plans[$months]->id, 'kind' => 'subscription',
                'name' => 'QA '.$months.' month — test price',
                'duration_months' => $months, 'credits' => $term['credits'],
                'currency' => 'USD', 'amount_minor' => $term['minor'],
                'terms_version' => 'qa-local-1',
                'offer_snapshot' => ['qa' => true, 'credits' => $term['credits'], 'durationMonths' => $months,
                    'currency' => 'USD', 'amountMinor' => $term['minor']],
                'stripe_product_id' => null, 'stripe_price_id' => null,
                'verified_at' => null, 'published_at' => null,
            ]);
        }
        foreach ([['credits' => 10, 'minor' => 399], ['credits' => 30, 'minor' => 999], ['credits' => 100, 'minor' => 2499]] as $topup) {
            BillingPlanPrice::query()->firstOrCreate([
                'environment' => 'sandbox', 'offer_key' => 'qa-topup-'.$topup['credits'], 'revision' => 1,
            ], [
                'kind' => 'topup', 'name' => 'QA '.$topup['credits'].' credits — test price',
                'duration_months' => null, 'credits' => $topup['credits'],
                'currency' => 'USD', 'amount_minor' => $topup['minor'],
                'terms_version' => 'qa-local-1',
                'offer_snapshot' => ['qa' => true, 'credits' => $topup['credits'],
                    'currency' => 'USD', 'amountMinor' => $topup['minor']],
                'stripe_product_id' => null, 'stripe_price_id' => null,
                'verified_at' => null, 'published_at' => null,
            ]);
        }

        $wallet = app(CreditWalletService::class);
        $month = CarbonImmutable::now('UTC')->startOfMonth();
        $monthKey = $month->format('Ym');
        $paidExpiry = $month->addMonthNoOverflow();
        $freeExpiry = $month->addMonthsNoOverflow(2);
        $credit = function (string $scenario, string $source, int $quantity, ?CarbonImmutable $expiry = null) use ($wallet, $users, $monthKey): CreditGrant {
            return $wallet->grant($users[$scenario], $source, $quantity, 'qa-local:'.$monthKey.':'.$scenario.':'.$source, $expiry, 'sandbox');
        };
        $credit('free', 'free_grant', 7, $freeExpiry);
        $credit('paid', 'paid_cycle', 15, $paidExpiry);
        $credit('topup', 'purchased_topup', 10);
        $credit('mixed', 'paid_cycle', 5, $paidExpiry);
        $credit('mixed', 'free_grant', 4, $freeExpiry);
        $credit('mixed', 'purchased_topup', 8);
        $credit('expired', 'free_grant', 3, $month->subDay());
        $credit('expired', 'purchased_topup', 2); // explicitly revoked source, zero spendable
        $wallet->revoke(CreditGrant::query()->where('source_key', 'qa-local:'.$monthKey.':expired:purchased_topup')
            ->where('environment', 'sandbox')->firstOrFail(), 'qa-revocation-check');
        $held = $credit('held', 'paid_cycle', 5, $paidExpiry);
        $wallet->hold($held, 'qa-pending-dispute');
        $credit('held', 'free_grant', 2, $freeExpiry);

        // Additional deterministic wallet scenarios. A renewed account has an
        // expired historical cycle and a current cycle; only the current one
        // contributes to its spendable balance. These are QA wallet fixtures,
        // not fabricated Stripe payment or subscription identities.
        $credit('renewed', 'paid_cycle', 12, $paidExpiry);
        $wallet->grant($users['renewed'], 'paid_cycle', 15,
            'qa-local:'.$month->subMonthNoOverflow()->format('Ym').':renewed:previous_cycle',
            $month->subDay(), 'sandbox');
        $credit('renewal_due', 'paid_cycle', 2, CarbonImmutable::now('UTC')->addDays(2));
        $credit('expired_only', 'paid_cycle', 8, $month->subDay());
        $credit('topup_only', 'purchased_topup', 20);

        $start = $month;
        $end = $month->addMonthsNoOverflow(3);
        $comp = ComplimentaryAllocation::query()->where('user_id', $users['complimentary']->id)
            ->where('environment', 'sandbox')->where('issuance_mode', 'per_period')->first();
        if (! $comp) {
            $comp = app(ComplimentaryCreditService::class)->enroll($users['complimentary'], 6, 1, $start, $end, 'per_period', $plans[1], 'sandbox');
        }
        if (! ComplimentaryAllocation::query()->where('user_id', $users['once']->id)
            ->where('environment', 'sandbox')->where('issuance_mode', 'once')->exists()) {
            app(ComplimentaryCreditService::class)->enroll($users['once'], 4, 3, $start, $end, 'once', $plans[3], 'sandbox');
        }
        // Scheduler's allocateDue is intentionally environment-scoped and
        // does not grant historical cycles to users who were never enrolled.
        app(ComplimentaryCreditService::class)->allocateDue();

        // Exercise the REAL durable admin preview/confirm workflow only for
        // the explicitly named QA bulk recipient. Re-runs do not create new
        // batches or credit an already completed recipient twice.
        $batchService = app(AdminGrantBatchService::class);
        $admin = $users['admin'];
        $batch = AdminGrantBatch::query()->where('actor_id', $admin->id)
            ->where('environment', 'sandbox')->where('audience', 'selected')
            ->where('credits', 3)->first();
        if (! $batch) {
            $batch = $batchService->preview($admin, 'selected', [$users['bulk']->id], 3, $freeExpiry);
        }
        if ($batch->status === 'preview') {
            $batchService->confirm($admin, $batch, $batch->snapshot_hash);
        }
        if ($batch->fresh()->status === 'running') {
            $batchService->processChunk($batch->id);
        }
        if ($batch->fresh()->status !== 'completed') {
            throw new RuntimeException('QA admin bulk grant is not completed: '.$batch->fresh()->status);
        }
        if (! AdminGrantBatch::query()->where('actor_id', $admin->id)
            ->where('environment', 'sandbox')->where('audience', 'selected')
            ->where('credits', 99)->exists()) {
            $cancelled = $batchService->preview($admin, 'selected', [$users['bulk']->id], 99, $freeExpiry);
            $batchService->cancel($admin, $cancelled);
        }

        $this->command?->info('Maik Cat QA data inserted/verified on existing local MySQL. No Stripe charges.');
        foreach ($users as $scenario => $user) {
            $this->command?->line(sprintf('%-16s user_id=%d wallet=%d', $scenario, $user->id, $wallet->balance($user, 'sandbox')));
        }
        $this->command?->line('Private QA credentials: '.$file);
    }
}
