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
 * QA accounts and sandbox credit buckets are created locally. With sk_test_
 * configured, this also creates/verifies the 7 test Products/Prices on Stripe
 * and publishes the offers. Never creates a live payment, subscription or invoice.
 */
class MaikCatQaBillingSeeder extends Seeder
{
    /** Human-facing names for QA offers. Their identifiers stay internal. */
    public const OFFER_NAMES = [
        'qa-wallet-1m' => 'Monthly Plan',
        'qa-wallet-3m' => 'Quarterly Plan',
        'qa-wallet-6m' => 'Semiannual Plan',
        'qa-wallet-12m' => 'Annual Plan',
        'qa-topup-10' => '10 Extra Credits',
        'qa-topup-30' => '30 Extra Credits',
        'qa-topup-100' => '100 Extra Credits',
    ];

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

        // Four fixed QA prices. If sk_test_ is configured they will be created
        // on Stripe and published; otherwise they remain safe local drafts.
        $terms = [
            ['months' => 1, 'name' => 'Monthly Plan', 'credits' => 15, 'minor' => 999],
            ['months' => 3, 'name' => 'Quarterly Plan', 'credits' => 50, 'minor' => 2499],
            ['months' => 6, 'name' => 'Semiannual Plan', 'credits' => 110, 'minor' => 4499],
            ['months' => 12, 'name' => 'Annual Plan', 'credits' => 240, 'minor' => 7999],
        ];
        $plans = [];
        $offerKeys = [];
        foreach ($terms as $term) {
            $months = $term['months'];
            $key = 'qa-wallet-'.$months.'m';
            $offerKeys[] = $key;
            $plans[$months] = SubscriptionPlan::query()->firstOrCreate(['key' => $key], [
                'name' => $term['name'],
                'tier_key' => 'qa-'.$months.'m',
                'duration_months' => $months, 'service_level' => 1,
                'daily_allowance' => null, 'features' => ['valuation' => true],
                'descriptions' => ['en' => $term['credits'].' credits included for '.$months.' months. One-time payment; no automatic renewal.'],
                'is_active' => true, 'is_visible' => false, 'sort_order' => 1000 + $months,
            ]);
            BillingPlanPrice::query()->firstOrCreate([
                'environment' => 'sandbox', 'offer_key' => $key, 'revision' => 1,
            ], [
                'subscription_plan_id' => $plans[$months]->id, 'kind' => 'subscription',
                'name' => $term['name'],
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
            $offerKeys[] = 'qa-topup-'.$topup['credits'];
            BillingPlanPrice::query()->firstOrCreate([
                'environment' => 'sandbox', 'offer_key' => 'qa-topup-'.$topup['credits'], 'revision' => 1,
            ], [
                'kind' => 'topup', 'name' => $topup['credits'].' Extra Credits',
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

        $this->publishStripeTestOffers($users['admin'], $offerKeys);

        $this->command?->info('Maik Cat QA data inserted/verified in the configured database. No Stripe charges.');
        foreach ($users as $scenario => $user) {
            $this->command?->line(sprintf('%-16s user_id=%d wallet=%d', $scenario, $user->id, $wallet->balance($user, 'sandbox')));
        }
        $this->command?->line('Private QA credentials: '.$file);
        $this->command?->line('QA balances are available directly from the database via maik:qa-billing-report.');
    }

    private function publishStripeTestOffers(User $admin, array $offerKeys): void
    {
        if (! str_starts_with((string) config('stripe_billing.secret_key'), 'sk_test_')) {
            $this->command?->warn('QA offers remain drafts. To publish automatically, configure STRIPE_SECRET_KEY=sk_test_... (never sk_live_).');

            return;
        }
        if (config('stripe_billing.environment') !== 'sandbox') {
            throw new RuntimeException('QA Stripe offers may only be created in sandbox.');
        }
        $actorTemporaryPermission = ! $admin->can('manage_subscription_products');
        if ($actorTemporaryPermission) {
            Permission::findOrCreate('manage_subscription_products', 'web');
            $admin->givePermissionTo('manage_subscription_products');
        }
        try {
            $gateway = app(StripeBillingGateway::class);
            $catalog = app(StripeOfferCatalogService::class);
            foreach ($offerKeys as $offerKey) {
                $offer = BillingPlanPrice::query()->where('environment', 'sandbox')
                    ->where('offer_key', $offerKey)->where('revision', 1)->firstOrFail();
                if ($offer->published_at) {
                    continue;
                }
                if ($offer->retired_at) {
                    throw new RuntimeException('Retired QA offer must not be republished: '.$offerKey);
                }

                // A stable Stripe Price lookup_key recovers from a network/DB
                // failure between Stripe creation and persistence, even when
                // Stripe's short-lived idempotency cache has expired.
                if (! $offer->stripe_price_id) {
                    $existingPrice = $gateway->findQaPrice($offer);
                    if ($existingPrice !== null) {
                        if (($existingPrice['livemode'] ?? true) !== false) {
                            throw new RuntimeException('Stripe returned a non-test QA price: '.$offerKey);
                        }
                        $product = $existingPrice['product'] ?? null;
                        if (is_array($product)) {
                            $product = $product['id'] ?? null;
                        }
                        if (! is_string($product) || ! str_starts_with($product, 'prod_')
                            || ! is_string($existingPrice['id'] ?? null) || ! str_starts_with($existingPrice['id'], 'price_')
                            || ($offer->stripe_product_id && $offer->stripe_product_id !== $product)) {
                            throw new RuntimeException('Existing QA Stripe price/product identity mismatch: '.$offerKey);
                        }
                        $offer->forceFill(['stripe_product_id' => $product, 'stripe_price_id' => $existingPrice['id']])->save();
                    } else {
                        if (! $offer->stripe_product_id) {
                            $product = $gateway->createQaProduct($offer);
                            if (! is_string($product['id'] ?? null) || ! str_starts_with($product['id'], 'prod_')
                                || ($product['livemode'] ?? true) !== false) {
                                throw new RuntimeException('Stripe returned an invalid/non-test QA product: '.$offerKey);
                            }
                            // Save first, so reruns after a failed price call
                            // reuse this product instead of creating another.
                            $offer->forceFill(['stripe_product_id' => $product['id']])->save();
                        }
                        $price = $gateway->createQaPrice($offer, $offer->stripe_product_id);
                        if (! is_string($price['id'] ?? null) || ! str_starts_with($price['id'], 'price_')
                            || ($price['livemode'] ?? true) !== false) {
                            throw new RuntimeException('Stripe returned an invalid/non-test QA price: '.$offerKey);
                        }
                        $offer->forceFill(['stripe_price_id' => $price['id']])->save();
                    }
                }

                $catalog->verifyAndPublish($admin, $offer, 'Automatically created and verified Stripe QA test offer', $gateway);
                $this->command?->line('Stripe test offer published: '.$offerKey);
            }
        } finally {
            if ($actorTemporaryPermission) {
                $admin->revokePermissionTo('manage_subscription_products');
            }
        }
    }
}
