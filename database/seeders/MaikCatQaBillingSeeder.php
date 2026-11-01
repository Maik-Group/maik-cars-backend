<?php

namespace Database\Seeders;

use App\Models\AdminGrantBatch;
use App\Models\BillingPlanPrice;
use App\Models\CarGroup;
use App\Models\ComplimentaryAllocation;
use App\Models\CreditConsumption;
use App\Models\CreditGrant;
use App\Models\Item;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Credits\AdminGrantBatchService;
use App\Services\Credits\ComplimentaryCreditService;
use App\Services\Credits\CreditWalletService;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Explicitly invoked on an existing database for QA fixtures.
 *
 * Only QA accounts and sandbox credit buckets are created. Stripe checkout
 * offers are published only when real test Price IDs are supplied and verified.
 * Never forges Stripe payments, subscriptions, or webhook fulfillments.
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
        'low_balance' => 'qa-maik-low-balance@example.test',
        'partially_used' => 'qa-maik-partially-used@example.test',
        'exhausted' => 'qa-maik-exhausted@example.test',
        'repeat_reveal' => 'qa-maik-repeat-reveal@example.test',
        'paid_priority' => 'qa-maik-paid-priority@example.test',
        'held_only' => 'qa-maik-held-only@example.test',
        'revoked_only' => 'qa-maik-revoked-only@example.test',
        'scheduled_free' => 'qa-maik-scheduled-free@example.test',
        'revoked_free' => 'qa-maik-revoked-free@example.test',
        'expired_free' => 'qa-maik-expired-free@example.test',
    ];

    public function run(): void
    {
        // These fixtures always belong to sandbox billing, even when the
        // application's Stripe credentials use another environment.
        $originalEnvironment = config('stripe_billing.environment');
        config()->set('stripe_billing.environment', 'sandbox');

        try {
            $this->seedSandboxFixtures();
        } finally {
            config()->set('stripe_billing.environment', $originalEnvironment);
        }

        if ($originalEnvironment !== 'sandbox') {
            $this->command?->warn('QA credits are sandbox-only: configure STRIPE_SECRET_KEY with sk_test_ on the QA server to view them through API.');
        }
    }

    private function seedSandboxFixtures(): void
    {
        // Fresh QA databases may not have had the application role seeder run.
        // Ensure only these two role identities exist; never rewrite the
        // permissions of existing non-QA roles or accounts.
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('app_user', 'web');

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
                'purpose' => 'Maik Cat sandbox QA accounts only',
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
        @chmod($file, 0600);
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
        // Keep the first run's rolling due date for the same monthly source
        // key. Recomputing now() on retry changes its expiry and correctly
        // trips CreditWalletService's immutable grant identity protection.
        $renewalDueKey = 'qa-local:'.$monthKey.':renewal_due:paid_cycle';
        $existingRenewalDue = CreditGrant::query()->where('source_type', 'paid_cycle')
            ->where('source_key', $renewalDueKey)->where('environment', 'sandbox')->first();
        $credit('renewal_due', 'paid_cycle', 2, $existingRenewalDue?->expires_at ?? CarbonImmutable::now('UTC')->addDays(2));
        $credit('expired_only', 'paid_cycle', 8, $month->subDay());
        $credit('topup_only', 'purchased_topup', 20);

        // QA scenarios used by the Android valuation screen. Real catalog
        // items are reused where possible, so live deployments do not acquire
        // dummy products just to seed wallet consumption receipts.
        $credit('low_balance', 'paid_cycle', 1, $paidExpiry);
        $credit('partially_used', 'paid_cycle', 5, $paidExpiry);
        $credit('exhausted', 'paid_cycle', 2, $paidExpiry);
        $credit('repeat_reveal', 'paid_cycle', 3, $paidExpiry);
        $credit('paid_priority', 'paid_cycle', 1, $paidExpiry);
        $credit('paid_priority', 'free_grant', 2, $freeExpiry);
        $credit('paid_priority', 'purchased_topup', 3);
        $wallet->hold($credit('held_only', 'paid_cycle', 4, $paidExpiry), 'qa-entire-wallet-held');
        $wallet->revoke($credit('revoked_only', 'free_grant', 4, $freeExpiry), 'qa-entire-wallet-revoked');

        $items = Item::query()->apiVisible()->orderBy('id')->limit(200)->get()
            ->unique(fn (Item $item) => Item::normalizeSerialValue($item->serial_code))->take(2)->values();
        if ($items->count() < 2) {
            // Empty dev/testing catalogs need self-contained QA objects.
            // Existing populated catalogs are left completely unchanged.
            $group = CarGroup::query()->firstOrCreate(['slug' => 'maik-qa-wallet-fixtures'], [
                'name' => 'MAIK QA WALLET FIXTURES',
                'excel_sheet_name' => 'MAIK QA WALLET FIXTURES',
                'region' => 'QA', 'source' => 'maik-qa-billing',
            ]);
            foreach (['A', 'B'] as $suffix) {
                Item::query()->firstOrCreate(['serial_code' => 'MAIK-QA-WALLET-'.$suffix], [
                    'car_group_id' => $group->getKey(),
                    'model' => 'QA WALLET FIXTURE', 'source' => 'maik-qa-billing',
                    'weight_kg' => 1.2, 'pt_ppm' => 100, 'pd_ppm' => 150, 'rh_ppm' => 20,
                ]);
            }
            $items = Item::query()->whereIn('serial_code', ['MAIK-QA-WALLET-A', 'MAIK-QA-WALLET-B'])->orderBy('serial_code')->get();
        }

        $spendOnceThisMonth = function (string $scenario, Item $item) use ($users, $wallet, $month): void {
            // The daily receipt identity intentionally uses the real date. A
            // rerun tomorrow must not spend that same QA scenario again.
            if (CreditConsumption::query()->where('user_id', $users[$scenario]->id)
                ->where('environment', 'sandbox')->where('item_id', $item->getKey())
                ->where('usage_date', '>=', $month->toDateString())
                ->where('usage_date', '<', $month->addMonthNoOverflow()->toDateString())->exists()) {
                return;
            }
            $wallet->spend($users[$scenario], $item, 'sandbox');
        };
        $spendOnceThisMonth('partially_used', $items[0]);
        $spendOnceThisMonth('partially_used', $items[1]);
        $spendOnceThisMonth('exhausted', $items[0]);
        $spendOnceThisMonth('exhausted', $items[1]);
        $spendOnceThisMonth('repeat_reveal', $items[0]);
        $spendOnceThisMonth('paid_priority', $items[0]);

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
        $complimentaryService = app(ComplimentaryCreditService::class);
        if (! ComplimentaryAllocation::query()->where('user_id', $users['scheduled_free']->id)->where('environment', 'sandbox')->exists()) {
            $complimentaryService->enroll($users['scheduled_free'], 5, 1,
                $month->addMonthNoOverflow(), $month->addMonthsNoOverflow(3), 'per_period', $plans[1], 'sandbox');
        }
        if (! ComplimentaryAllocation::query()->where('user_id', $users['revoked_free']->id)->where('environment', 'sandbox')->exists()) {
            $allocation = $complimentaryService->enroll($users['revoked_free'], 5, 1,
                $month, $month->addMonthsNoOverflow(3), 'per_period', $plans[1], 'sandbox');
            $allocation->update(['revoked_at' => CarbonImmutable::now('UTC')]);
        }
        if (! ComplimentaryAllocation::query()->where('user_id', $users['expired_free']->id)->where('environment', 'sandbox')->exists()) {
            $complimentaryService->enroll($users['expired_free'], 5, 1,
                $month->subMonthsNoOverflow(3), $month->subDay(), 'per_period', $plans[1], 'sandbox');
        }
        // Scheduler's allocateDue is intentionally environment-scoped and
        // does not grant historical cycles to users who were never enrolled.
        app(ComplimentaryCreditService::class)->allocateDue();

        // Exercise the REAL durable admin preview/confirm workflow only for
        // the explicitly named QA bulk recipient. Re-runs do not create new
        // batches or credit an already completed recipient twice.
        $batchService = app(AdminGrantBatchService::class);
        $admin = $users['admin'];
        // A server may have an older admin role lacking this new permission.
        // Grant it only to the QA actor during fixture creation, without
        // changing the permissions of real admins or bypassing authorization.
        $temporaryPermission = ! $admin->can('grant_bulk_credits');
        if ($temporaryPermission) {
            Permission::findOrCreate('grant_bulk_credits', 'web');
            $admin->givePermissionTo('grant_bulk_credits');
        }

        try {
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
        } finally {
            if ($temporaryPermission) {
                $admin->revokePermissionTo('grant_bulk_credits');
            }
        }

        $this->publishRealStripeTestOffersIfProvided($users['admin']);

        $scenariosFile = storage_path('app/private/maik-qa-scenarios.json');
        $report = [
            'period' => $monthKey,
            'billingEnvironment' => 'sandbox',
            'checkoutNote' => 'Published offers require Stripe test Price IDs and verification. Native Checkout remains disabled by API policy.',
            'valuationItemSamples' => $items->map(fn (Item $item) => [
                'id' => $item->getKey(), 'serialCode' => $item->serial_code,
            ])->all(),
            'scenarioNotes' => [
                'empty' => 'No subscription and no wallet credits (paywall)',
                'free' => 'Manual free credits',
                'paid' => 'Simulated paid-cycle wallet grant, not a real Stripe subscription',
                'topup' => 'Top-up credits with no expiry',
                'mixed' => 'Paid, free and top-up buckets in one wallet',
                'complimentary' => 'Recurring complimentary plan allocation',
                'once' => 'One-time complimentary allocation',
                'expired' => 'Expired grant and explicitly revoked top-up',
                'held' => 'Held paid credits, remaining available free credits',
                'bulk' => 'Completed admin-grant batch; cancelled preview also exists',
                'admin' => 'QA-only admin actor; temporary seeding permissions revoked',
                'renewed' => 'Previous expired cycle plus current-cycle wallet credits',
                'renewal_due' => 'Wallet credits expiring near first seeding time',
                'expired_only' => 'Only expired paid-cycle credits',
                'topup_only' => 'Only nonexpiring top-up credits',
                'low_balance' => 'Exactly one available credit',
                'partially_used' => 'Five paid credits; two distinct serial-family receipts',
                'exhausted' => 'Two paid credits fully consumed; zero remaining',
                'repeat_reveal' => 'One receipt; revealing same serial family on same UTC day is free',
                'paid_priority' => 'Paid credit spent first; free and top-up still available',
                'held_only' => 'All available grants on hold; paywall',
                'revoked_only' => 'All available grants revoked; paywall',
                'scheduled_free' => 'Complimentary allocation with future start; currently zero',
                'revoked_free' => 'Complimentary allocation revoked before issuance',
                'expired_free' => 'Complimentary allocation expired before issuance',
            ],
            'accounts' => collect($users)->map(fn (User $user, string $scenario) => [
                'email' => $user->email, 'userId' => $user->id,
                'walletBalance' => $wallet->balance($user, 'sandbox'),
                'usageReceipts' => CreditConsumption::query()->where('user_id', $user->id)->where('environment', 'sandbox')->count(),
            ])->all(),
        ];
        file_put_contents($scenariosFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($scenariosFile, 0600);

        $this->command?->info('Maik Cat QA data inserted/verified in the configured database. No Stripe charges.');
        foreach ($users as $scenario => $user) {
            $this->command?->line(sprintf('%-16s user_id=%d wallet=%d', $scenario, $user->id, $wallet->balance($user, 'sandbox')));
        }
        $this->command?->line('Private QA credentials: '.$file);
        $this->command?->line('QA scenario report: '.$scenariosFile);
    }

    private function publishRealStripeTestOffersIfProvided(User $admin): void
    {
        $path = storage_path('app/private/maik-qa-stripe-prices.json');
        if (! is_file($path)) {
            return;
        }
        if (! str_starts_with((string) config('stripe_billing.secret_key'), 'sk_test_')) {
            throw new RuntimeException('QA offer publication requires STRIPE_SECRET_KEY beginning with sk_test_.');
        }

        $prices = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($prices) || array_is_list($prices)) {
            throw new RuntimeException('QA Stripe price manifest must map offer keys to Stripe test product and price IDs.');
        }
        $actorTemporaryPermission = ! $admin->can('manage_subscription_products');
        if ($actorTemporaryPermission) {
            Permission::findOrCreate('manage_subscription_products', 'web');
            $admin->givePermissionTo('manage_subscription_products');
        }
        try {
            foreach ($prices as $offerKey => $ids) {
                if (! is_string($offerKey) || ! str_starts_with($offerKey, 'qa-') || ! is_array($ids)
                    || ! is_string($ids['stripe_product_id'] ?? null) || ! str_starts_with($ids['stripe_product_id'], 'prod_')
                    || ! is_string($ids['stripe_price_id'] ?? null) || ! str_starts_with($ids['stripe_price_id'], 'price_')) {
                    throw new RuntimeException('Invalid QA test price manifest mapping for '.(string) $offerKey);
                }
                $offer = BillingPlanPrice::query()->where('environment', 'sandbox')
                    ->where('offer_key', $offerKey)->where('revision', 1)->firstOrFail();
                if ($offer->published_at) {
                    if ($offer->stripe_product_id !== $ids['stripe_product_id'] || $offer->stripe_price_id !== $ids['stripe_price_id']) {
                        throw new RuntimeException('Published QA offer cannot change Stripe identity: '.$offerKey);
                    }

                    continue;
                }
                if ($offer->stripe_price_id && $offer->stripe_price_id !== $ids['stripe_price_id']) {
                    throw new RuntimeException('Existing QA offer has a different test Stripe price: '.$offerKey);
                }
                $offer->forceFill([
                    'stripe_product_id' => $ids['stripe_product_id'],
                    'stripe_price_id' => $ids['stripe_price_id'],
                ])->save();
                app(StripeOfferCatalogService::class)->verifyAndPublish(
                    $admin, $offer, 'QA test price verified against Stripe', app(StripeBillingGateway::class),
                );
            }
        } finally {
            if ($actorTemporaryPermission) {
                $admin->revokePermissionTo('manage_subscription_products');
            }
        }
    }
}
