<?php

use App\Models\AdminGrantBatch;
use App\Models\BillingPlanPrice;
use App\Models\ComplimentaryAllocation;
use App\Models\CreditConsumption;
use App\Models\CreditGrant;
use App\Models\Item;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeCheckoutService;
use Carbon\CarbonImmutable;
use Database\Seeders\MaikCatQaBillingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery as MockeryFacade;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds sandbox-only QA fixtures even when the server admin role lacks bulk grants', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $adminRole = Role::findByName('admin');
    $adminRole->revokePermissionTo('grant_bulk_credits');
    $unrelatedAdmin = User::factory()->create();
    $unrelatedAdmin->assignRole('admin');

    $originalStorage = storage_path();
    $storage = sys_get_temp_dir().'/maik-qa-seeder-test-'.Str::random(16);
    app()->useStoragePath($storage);
    Queue::fake();
    config()->set('stripe_billing.environment', 'production');
    config()->set('stripe_billing.secret_key', 'sk_live_not_used_for_qa_seeding');
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));

    try {
        $this->seed(MaikCatQaBillingSeeder::class);

        $qaAdmin = User::query()->where('email', MaikCatQaBillingSeeder::ACCOUNTS['admin'])->firstOrFail();
        $bulk = User::query()->where('email', MaikCatQaBillingSeeder::ACCOUNTS['bulk'])->firstOrFail();
        $scenarioUser = static fn (string $key) => User::query()->where('email', MaikCatQaBillingSeeder::ACCOUNTS[$key])->firstOrFail();
        $wallet = app(CreditWalletService::class);
        expect(config('stripe_billing.environment'))->toBe('production')
            ->and($qaAdmin->can('grant_bulk_credits'))->toBeFalse()
            ->and($unrelatedAdmin->fresh()->can('grant_bulk_credits'))->toBeFalse()
            ->and(Role::findByName('admin')->hasPermissionTo('grant_bulk_credits'))->toBeFalse()
            ->and(AdminGrantBatch::query()->where('environment', 'production')->count())->toBe(0)
            ->and(AdminGrantBatch::query()->where('environment', 'sandbox')->where('status', 'completed')->count())->toBe(1)
            ->and(CreditGrant::query()->where('environment', 'production')->count())->toBe(0)
            ->and(CreditGrant::query()->where('environment', 'sandbox')->where('user_id', $bulk->id)->sum('credits'))->toBe(3)
            ->and(is_file($storage.'/app/private/maik-qa-credentials.json'))->toBeTrue()
            ->and(is_file($storage.'/app/private/maik-qa-scenarios.json'))->toBeTrue()
            ->and(count(MaikCatQaBillingSeeder::ACCOUNTS))->toBe(25)
            ->and($wallet->balance($scenarioUser('empty'), 'sandbox'))->toBe(0)
            ->and($wallet->balance($scenarioUser('low_balance'), 'sandbox'))->toBe(1)
            ->and($wallet->balance($scenarioUser('partially_used'), 'sandbox'))->toBe(3)
            ->and($wallet->balance($scenarioUser('exhausted'), 'sandbox'))->toBe(0)
            ->and($wallet->balance($scenarioUser('repeat_reveal'), 'sandbox'))->toBe(2)
            ->and($wallet->balance($scenarioUser('paid_priority'), 'sandbox'))->toBe(5)
            ->and($wallet->balance($scenarioUser('held_only'), 'sandbox'))->toBe(0)
            ->and($wallet->balance($scenarioUser('revoked_only'), 'sandbox'))->toBe(0)
            ->and($wallet->balance($scenarioUser('scheduled_free'), 'sandbox'))->toBe(0)
            ->and($wallet->balance($scenarioUser('revoked_free'), 'sandbox'))->toBe(0)
            ->and($wallet->balance($scenarioUser('expired_free'), 'sandbox'))->toBe(0)
            ->and(CreditConsumption::query()->where('user_id', $scenarioUser('partially_used')->id)->count())->toBe(2)
            ->and(CreditConsumption::query()->where('user_id', $scenarioUser('exhausted')->id)->count())->toBe(2)
            ->and(CreditConsumption::query()->where('user_id', $scenarioUser('repeat_reveal')->id)->count())->toBe(1)
            ->and(CreditConsumption::query()->where('user_id', $scenarioUser('paid_priority')->id)->count())->toBe(1)
            ->and(CreditGrant::query()->where('user_id', $scenarioUser('paid_priority')->id)
                ->where('source_type', 'paid_cycle')->value('remaining'))->toBe(0)
            ->and(ComplimentaryAllocation::query()->where('user_id', $scenarioUser('revoked_free')->id)->whereNotNull('revoked_at')->exists())->toBeTrue();

        $receipt = CreditConsumption::query()->where('user_id', $scenarioUser('repeat_reveal')->id)->firstOrFail();
        expect($wallet->spend($scenarioUser('repeat_reveal'), Item::findOrFail($receipt->item_id), 'sandbox')['counted'])->toBeFalse();

        $report = json_decode((string) file_get_contents($storage.'/app/private/maik-qa-scenarios.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($report['accounts']['exhausted']['walletBalance'])->toBe(0)
            ->and($report['accounts']['partially_used']['walletBalance'])->toBe(3)
            ->and($report['accounts']['repeat_reveal']['usageReceipts'])->toBe(1)
            ->and(count($report['valuationItemSamples']))->toBe(2);

        // Confirm generated credentials actually work with the mobile login
        // endpoint, and that the API exposes the sandbox wallet only when the
        // server is configured for sandbox billing.
        $manifest = json_decode((string) file_get_contents($storage.'/app/private/maik-qa-credentials.json'), true, flags: JSON_THROW_ON_ERROR);
        $login = $this->postJson('/api/auth/login', $manifest['accounts']['partially_used']);
        $login->assertOk()->assertJsonPath('user.email', MaikCatQaBillingSeeder::ACCOUNTS['partially_used']);
        $token = $login->json('token');
        config()->set('stripe_billing.environment', 'sandbox');
        $this->withToken($token)->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.balance', 3);
        config()->set('stripe_billing.environment', 'production');
        $this->withToken($token)->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.balance', 0);

        $dueGrant = CreditGrant::query()->where('source_key', 'qa-local:202611:renewal_due:paid_cycle')
            ->where('environment', 'sandbox')->sole();
        $originalDue = $dueGrant->expires_at->getTimestamp();
        $grantCount = CreditGrant::query()->count();
        $batchCount = AdminGrantBatch::query()->count();
        // This used to throw "Conflicting credit grant identity" on the server.
        $this->travel(1)->days();
        $this->seed(MaikCatQaBillingSeeder::class);
        expect(CreditGrant::query()->count())->toBe($grantCount)
            ->and($dueGrant->fresh()->expires_at->getTimestamp())->toBe($originalDue)
            ->and(AdminGrantBatch::query()->count())->toBe($batchCount)
            ->and(CreditConsumption::query()->where('user_id', $scenarioUser('partially_used')->id)->count())->toBe(2)
            ->and(CreditConsumption::query()->where('user_id', $scenarioUser('exhausted')->id)->count())->toBe(2)
            ->and($qaAdmin->fresh()->can('grant_bulk_credits'))->toBeFalse()
            ->and(config('stripe_billing.environment'))->toBe('production');
    } finally {
        $this->travelBack();
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($storage);
    }
});

it('creates and publishes all seven real Stripe test prices without a manifest or duplicate API calls', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $originalStorage = storage_path();
    $storage = sys_get_temp_dir().'/maik-qa-stripe-test-'.Str::random(16);
    app()->useStoragePath($storage);
    Queue::fake();
    config()->set('stripe_billing.secret_key', 'sk_test_qa_key');
    config()->set('stripe_billing.environment', 'sandbox');
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));

    $gateway = $this->mock(StripeBillingGateway::class);
    $offers = [
        'qa-wallet-1m' => ['kind' => 'subscription', 'months' => 1, 'amount' => 999],
        'qa-wallet-3m' => ['kind' => 'subscription', 'months' => 3, 'amount' => 2499],
        'qa-wallet-6m' => ['kind' => 'subscription', 'months' => 6, 'amount' => 4499],
        'qa-wallet-12m' => ['kind' => 'subscription', 'months' => 12, 'amount' => 7999],
        'qa-topup-10' => ['kind' => 'topup', 'months' => null, 'amount' => 399],
        'qa-topup-30' => ['kind' => 'topup', 'months' => null, 'amount' => 999],
        'qa-topup-100' => ['kind' => 'topup', 'months' => null, 'amount' => 2499],
    ];
    foreach ($offers as $key => $terms) {
        $gateway->shouldReceive('findQaPrice')->once()
            ->with(MockeryFacade::on(fn (BillingPlanPrice $offer) => $offer->offer_key === $key))
            ->andReturnNull();
        $gateway->shouldReceive('createQaProduct')->once()
            ->with(MockeryFacade::on(fn (BillingPlanPrice $offer) => $offer->offer_key === $key))
            ->andReturn(['id' => 'prod_'.$key, 'livemode' => false]);
        $gateway->shouldReceive('createQaPrice')->once()
            ->with(MockeryFacade::on(fn (BillingPlanPrice $offer) => $offer->offer_key === $key), 'prod_'.$key)
            ->andReturn(['id' => 'price_'.$key, 'livemode' => false]);
        $gateway->shouldReceive('price')->once()->with('price_'.$key)->andReturn([
            'id' => 'price_'.$key, 'active' => true, 'currency' => 'usd', 'unit_amount' => $terms['amount'],
            'product' => 'prod_'.$key, 'type' => $terms['kind'] === 'subscription' ? 'recurring' : 'one_time',
            'recurring' => $terms['kind'] === 'subscription'
                ? ['interval' => 'month', 'interval_count' => $terms['months']] : null,
        ]);
    }

    try {
        $this->seed(MaikCatQaBillingSeeder::class);
        $qaAdmin = User::query()->where('email', MaikCatQaBillingSeeder::ACCOUNTS['admin'])->firstOrFail();
        expect(BillingPlanPrice::query()->where('environment', 'sandbox')->get()->every(fn (BillingPlanPrice $offer) => $offer->checkoutEligible()))->toBeTrue()
            ->and($qaAdmin->can('manage_subscription_products'))->toBeFalse()
            ->and(BillingPlanPrice::query()->whereNotNull('published_at')->count())->toBe(7)
            ->and(app(StripeCheckoutService::class)->catalog()['externalCheckoutAllowed'])->toBeFalse();

        $this->seed(MaikCatQaBillingSeeder::class);
        expect(BillingPlanPrice::query()->whereNotNull('published_at')->count())->toBe(7)
            ->and($qaAdmin->fresh()->can('manage_subscription_products'))->toBeFalse();
    } finally {
        $this->travelBack();
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($storage);
    }
});

it('recovers already-created Stripe QA Prices by lookup key without creating duplicate products', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $originalStorage = storage_path();
    $storage = sys_get_temp_dir().'/maik-qa-recovered-price-'.Str::random(16);
    app()->useStoragePath($storage);
    Queue::fake();
    config()->set('stripe_billing.secret_key', 'sk_test_qa_key');
    config()->set('stripe_billing.environment', 'sandbox');
    $gateway = $this->mock(StripeBillingGateway::class);
    foreach (['qa-wallet-1m', 'qa-wallet-3m', 'qa-wallet-6m', 'qa-wallet-12m', 'qa-topup-10', 'qa-topup-30', 'qa-topup-100'] as $key) {
        $gateway->shouldReceive('findQaPrice')->once()
            ->with(MockeryFacade::on(fn (BillingPlanPrice $offer) => $offer->offer_key === $key))
            ->andReturn(['id' => 'price_'.$key, 'product' => 'prod_'.$key, 'livemode' => false]);
        $gateway->shouldReceive('price')->once()->with('price_'.$key)->andReturnUsing(
            function () use ($key): array {
                $offer = BillingPlanPrice::query()->where('offer_key', $key)->sole();

                return [
                    'id' => 'price_'.$key, 'product' => 'prod_'.$key, 'active' => true,
                    'currency' => strtolower($offer->currency), 'unit_amount' => $offer->amount_minor,
                    'type' => $offer->kind === 'subscription' ? 'recurring' : 'one_time',
                    'recurring' => $offer->kind === 'subscription'
                        ? ['interval' => 'month', 'interval_count' => $offer->duration_months] : null,
                ];
            },
        );
    }
    $gateway->shouldNotReceive('createQaProduct');
    $gateway->shouldNotReceive('createQaPrice');
    try {
        $this->seed(MaikCatQaBillingSeeder::class);
        expect(BillingPlanPrice::query()->whereNotNull('published_at')->count())->toBe(7);
    } finally {
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($storage);
    }
});
