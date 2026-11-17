<?php

use App\Filament\Resources\AppUsers\AppUserResource;
use App\Filament\Resources\AppUsers\Pages\EditAppUser;
use App\Filament\Resources\AppUsers\Pages\ListAppUsers;
use App\Filament\Resources\BillingPlanPrices\BillingPlanPriceResource;
use App\Filament\Resources\SubscriptionPlans\Pages\CreateSubscriptionPlan;
use App\Filament\Resources\SubscriptionPlans\SubscriptionPlanResource;
use App\Models\CreditGrant;
use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Credits\AdminUserGrantService;
use App\Services\Credits\CreditWalletService;
use App\Services\Subscriptions\ActiveBillingPlan;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['stripe_billing.environment' => 'sandbox']);
    Notification::fake();
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('super_admin');
});

test('admin grants credits to selected and all active app users without touching admins', function (): void {
    $first = User::factory()->create(['is_active' => true]);
    $second = User::factory()->create(['is_active' => true]);
    $inactive = User::factory()->create(['is_active' => false]);
    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('admin');
    $service = app(AdminUserGrantService::class);

    $selected = $service->credits($this->admin, 'selected', [$first->id, $second->id], 8);
    expect($selected['granted'])->toBe(2)
        ->and(app(CreditWalletService::class)->balance($first))->toBe(8)
        ->and(app(CreditWalletService::class)->balance($second))->toBe(8);

    $all = $service->credits($this->admin, 'all', [], 3);
    expect($all['granted'])->toBe(2)
        ->and(app(CreditWalletService::class)->balance($first))->toBe(11)
        ->and(app(CreditWalletService::class)->balance($second))->toBe(11)
        ->and(app(CreditWalletService::class)->balance($inactive))->toBe(0)
        ->and(app(CreditWalletService::class)->balance($otherAdmin))->toBe(0);
});

test('granting a subscription issues plan credits and skips existing memberships', function (): void {
    $plan = SubscriptionPlan::factory()->create([
        'is_active' => true, 'credits' => 42, 'duration_months' => 1,
        'features' => ['valuation' => true],
    ]);
    $first = User::factory()->create(['is_active' => true]);
    $second = User::factory()->create(['is_active' => true]);
    $service = app(AdminUserGrantService::class);

    $result = $service->subscriptions($this->admin, 'selected', [$first->id, $second->id], $plan);
    expect($result)->toBe(['granted' => 2, 'skipped' => 0])
        ->and(ActiveBillingPlan::endsAt($first, 'sandbox'))->not->toBeNull()
        ->and(app(CreditWalletService::class)->balance($first))->toBe(42)
        ->and(app(CreditWalletService::class)->balance($second))->toBe(42);

    $repeated = $service->subscriptions($this->admin, 'all', [], $plan);
    expect($repeated)->toBe(['granted' => 0, 'skipped' => 2])
        ->and(SubscriptionEntitlement::query()->where('source', 'grant')->count())->toBe(2)
        ->and(CreditGrant::query()->where('source_type', 'complimentary')->count())->toBe(2);
});

test('only an active subscription with credits can be granted', function (): void {
    $plan = SubscriptionPlan::factory()->create(['is_active' => false, 'credits' => 10]);
    $recipient = User::factory()->create(['is_active' => true]);
    expect(fn () => app(AdminUserGrantService::class)->subscriptions($this->admin, 'selected', [$recipient->id], $plan))
        ->toThrow(ValidationException::class);
    expect(CreditGrant::query()->count())->toBe(0);
});

test('users table has no delete operation and has only two grant buttons', function (): void {
    expect(AppUserResource::canDelete(new User))->toBeFalse()
        ->and(AppUserResource::canDeleteAny())->toBeFalse();
    Livewire::actingAs($this->admin)->test(ListAppUsers::class)
        ->assertActionVisible('grantCredits')
        ->assertActionVisible('grantSubscriptions')
        ->assertDontSee(__('admin.ui.app_users.reset_all'));
});

test('credit package and subscription plans share the subscription navigation group', function (): void {
    expect(BillingPlanPriceResource::getNavigationGroup())->toBe('Subscription')
        ->and(SubscriptionPlanResource::getNavigationGroup())->toBe('Subscription');
});

test('the plan create form saves credits and automatically generates its hidden key', function (): void {
    Livewire::actingAs($this->admin)
        ->test(CreateSubscriptionPlan::class)
        ->fillForm([
            'name' => 'Premium Three Months', 'credits' => 105, 'duration_months' => 3,
            'is_active' => true, 'sort_order' => 1,
        ])
        ->call('create')->assertHasNoFormErrors();

    $this->assertDatabaseHas('subscription_plans', [
        'key' => 'premium-three-months', 'name' => 'Premium Three Months',
        'credits' => 105, 'duration_months' => 3,
    ]);
});

test('credit package catalog excludes subscription and other environment offers', function (): void {
    $service = app(StripeOfferCatalogService::class);
    $base = [
        'offer_key' => 'extra-small', 'revision' => 1, 'environment' => 'sandbox',
        'kind' => 'topup', 'name' => '10 Extra Credits', 'duration_months' => null,
        'credits' => 10, 'currency' => 'EUR', 'amount_minor' => 399,
        'terms_version' => 'v1', 'stripe_product_id' => 'prod_test', 'stripe_price_id' => 'price_test',
    ];
    $service->createDraft($this->admin, $base, 'Create extra-credit pack');
    $service->createDraft($this->admin, [
        ...$base, 'offer_key' => 'month', 'name' => 'Monthly Plan',
        'kind' => 'subscription', 'duration_months' => 1,
        'stripe_price_id' => 'price_month',
    ], 'Create subscription price');
    $service->createDraft($this->admin, [
        ...$base, 'offer_key' => 'extra-live', 'name' => 'Live credits',
        'environment' => 'production', 'stripe_price_id' => 'price_live',
    ], 'Create live credits');

    expect(BillingPlanPriceResource::getEloquentQuery()->pluck('name')->all())->toBe(['10 Extra Credits']);
});

test('the app user grant actions operate through Filament and show the current balance', function (): void {
    $recipient = User::factory()->create(['is_active' => true]);
    Livewire::actingAs($this->admin)
        ->test(ListAppUsers::class)
        ->callAction('grantCredits', data: [
            'scope' => 'selected', 'user_ids' => [$recipient->id], 'credits' => 6,
        ])->assertNotified();

    expect(app(CreditWalletService::class)->balance($recipient))->toBe(6);

    Livewire::actingAs($this->admin)
        ->test(EditAppUser::class, ['record' => $recipient->id])
        ->assertSee('Subscription &amp; credits', escape: false)
        ->assertSee('6 credits');
});

test('mobile billing status describes administrative subscriptions without marking them as paid', function (): void {
    $plan = SubscriptionPlan::factory()->create([
        'name' => 'Administrative Monthly', 'is_active' => true, 'credits' => 25,
        'duration_months' => 1, 'features' => ['valuation' => true],
    ]);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    app(AdminUserGrantService::class)->subscriptions($this->admin, 'selected', [$user->id], $plan);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/billing/subscription')->assertOk()
        ->assertJsonPath('data.paidSubscription', null)
        ->assertJsonPath('data.administrativeSubscription.status', 'active')
        ->assertJsonPath('data.administrativeSubscription.planName', 'Administrative Monthly')
        ->assertJsonPath('data.administrativeSubscription.billingMode', 'administrative_grant');

    $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.balance', 25);
});
