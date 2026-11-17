<?php

use App\Filament\Pages\SubscriptionDiagnostics;
use App\Filament\Resources\BillingAgreements\BillingAgreementResource;
use App\Filament\Resources\BillingPlanPrices\BillingPlanPriceResource;
use App\Filament\Resources\BillingPlanPrices\Pages\CreateBillingPlanPrice;
use App\Filament\Resources\SubscriptionAuditLogs\SubscriptionAuditLogResource;
use App\Filament\Resources\SubscriptionEntitlements\SubscriptionEntitlementResource;
use App\Filament\Resources\SubscriptionPlans\Schemas\SubscriptionPlanForm;
use App\Filament\Resources\SubscriptionPlans\SubscriptionPlanResource;
use App\Models\BillingAgreement;
use App\Models\PlanStoreProduct;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionCatalogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

test('legacy subscription management screens are unavailable to every role', function (string $role): void {
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);
    foreach ([SubscriptionEntitlementResource::getUrl('index'), BillingAgreementResource::getUrl('index'), SubscriptionAuditLogResource::getUrl('index'), SubscriptionDiagnostics::getUrl()] as $url) {
        $this->get($url)->assertForbidden();
    }
})->with(['super_admin', 'admin', 'content_manager', 'app_user']);

test('the remaining plan page is available only to permitted administrators', function (string $role, bool $allowed): void {
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    $response = $this->get(SubscriptionPlanResource::getUrl('index'));
    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([['super_admin', true], ['admin', true], ['content_manager', false], ['app_user', false]]);

test('credit package page presents the simplified catalog', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('super_admin');
    $this->actingAs($actor);

    $this->get(BillingPlanPriceResource::getUrl('index'))->assertOk()->assertSee('Credit Packages');
    $this->get(BillingPlanPriceResource::getUrl('create'))->assertOk()->assertSee('Number of credits')->assertSee('Price');
});

test('credit package creation stores the display price as minor units and uses a one-time offer', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('super_admin');
    $this->actingAs($actor);

    Livewire::test(CreateBillingPlanPrice::class)
        ->fillForm([
            'name' => '50 Credits', 'offer_key' => 'credits-50', 'credits' => 50,
            'price' => '12.50', 'currency' => 'EUR', 'environment' => 'sandbox',
            'stripe_product_id' => 'prod_50', 'stripe_price_id' => 'price_50',
        ])->call('create')->assertHasNoFormErrors();

    $this->assertDatabaseHas('billing_plan_prices', [
        'offer_key' => 'credits-50', 'kind' => 'topup', 'credits' => 50,
        'amount_minor' => 1250, 'currency' => 'EUR', 'terms_version' => 'v1',
    ]);
});

test('hidden subscription fields keep valid defaults and preserve existing legacy values', function (): void {
    $form = SubscriptionPlanForm::attributesForSave([
        'key' => 'credit-plan', 'name' => 'Plan', 'duration_months' => 1, 'is_active' => true, 'sort_order' => 0,
    ], null);
    expect($form['tier_key'])->toBe('credit-plan')
        ->and($form['service_level'])->toBe(1)
        ->and($form['daily_allowance'])->toBeNull()
        ->and($form['features']['valuation'])->toBeTrue()
        ->and($form['is_visible'])->toBeTrue();

    $plan = SubscriptionPlan::factory()->make([
        'tier_key' => 'legacy', 'service_level' => 5, 'daily_allowance' => 9,
        'features' => ['valuation' => false], 'is_visible' => false,
    ]);
    $preserved = SubscriptionPlanForm::attributesForSave(['key' => 'credit-plan', 'is_active' => true], $plan);
    expect($preserved['tier_key'])->toBe('legacy')
        ->and($preserved['service_level'])->toBe(5)
        ->and($preserved['daily_allowance'])->toBe(9)
        ->and($preserved['features']['valuation'])->toBeFalse()
        ->and($preserved['is_visible'])->toBeFalse();
});

test('catalog changes validate durations, audit reasons and reject direct unauthorized service calls', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $catalog = app(SubscriptionCatalogService::class);
    $attributes = ['key' => 'standard-monthly', 'name' => 'Standard', 'tier_key' => 'standard', 'duration_months' => 1, 'service_level' => 1, 'daily_allowance' => 5, 'features' => ['valuation' => true], 'is_active' => false, 'is_visible' => false, 'sort_order' => 0];
    expect(fn () => $catalog->savePlan($admin, null, $attributes, 'Not permitted'))->toThrow(AuthorizationException::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    expect(fn () => $catalog->savePlan($superAdmin, null, [...$attributes, 'duration_months' => 2], 'Unsupported duration'))->toThrow(ValidationException::class);
    $plan = $catalog->savePlan($superAdmin, null, $attributes, 'Approved business configuration');
    expect($plan->revision)->toBe(1);
    $catalog->savePlan($superAdmin, $plan, [...$attributes, 'daily_allowance' => 8], 'Prospective revision');
    expect($plan->fresh()->revision)->toBe(2);
    $this->assertDatabaseCount('subscription_audit_logs', 2);
    $this->actingAs($superAdmin)->get(SubscriptionPlanResource::getUrl('edit', ['record' => $plan]))->assertOk()->assertSee('Existing agreements retain their benefits')->assertDontSee('Daily allowance');
    $this->actingAs($admin)->get(SubscriptionPlanResource::getUrl('edit', ['record' => $plan]))->assertForbidden();
});

test('legacy billing agreement detail remains inaccessible without deleting billing records', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $recipient = User::factory()->create();
    $plan = SubscriptionPlan::factory()->create();
    $product = PlanStoreProduct::query()->create(['subscription_plan_id' => $plan->id, 'platform' => 'ios', 'environment' => 'production', 'product_id' => 'qa-monthly', 'plan_revision' => 1]);
    $agreement = BillingAgreement::query()->create(['user_id' => $recipient->id, 'plan_store_product_id' => $product->id, 'provider' => 'apple', 'environment' => 'production', 'identity_fingerprint' => hash('sha256', 'qa-original'), 'reference_fingerprint' => hash('sha256', 'qa-reference'), 'purchase_reference' => 'secret-store-reference', 'provider_state' => 'active', 'features' => ['valuation' => true], 'daily_allowance' => 5]);
    $this->actingAs($admin)->get(BillingAgreementResource::getUrl('view', ['record' => $agreement]))->assertForbidden();
    $this->assertDatabaseHas('billing_agreements', ['id' => $agreement->id]);
});
