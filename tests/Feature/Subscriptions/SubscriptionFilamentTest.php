<?php

use App\Filament\Pages\SubscriptionDiagnostics;
use App\Filament\Resources\BillingAgreements\BillingAgreementResource;
use App\Filament\Resources\SubscriptionAuditLogs\SubscriptionAuditLogResource;
use App\Filament\Resources\SubscriptionEntitlements\SubscriptionEntitlementResource;
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

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

test('subscription administration pages authorize each default role', function (string $role, bool $allowed): void {
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);
    foreach ([SubscriptionPlanResource::getUrl('index'), SubscriptionEntitlementResource::getUrl('index'), BillingAgreementResource::getUrl('index'), SubscriptionAuditLogResource::getUrl('index'), SubscriptionDiagnostics::getUrl()] as $url) {
        $response = $this->get($url);
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }
})->with([['super_admin', true], ['admin', true], ['content_manager', false], ['app_user', false]]);

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
    $this->actingAs($superAdmin)->get(SubscriptionPlanResource::getUrl('edit', ['record' => $plan]))->assertOk()->assertSee('Existing agreements retain their benefits');
    $this->actingAs($admin)->get(SubscriptionPlanResource::getUrl('edit', ['record' => $plan]))->assertForbidden();
});

test('agreement detail shows safe billing evidence and never exposes encrypted store references', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $recipient = User::factory()->create();
    $plan = SubscriptionPlan::factory()->create();
    $product = PlanStoreProduct::query()->create(['subscription_plan_id' => $plan->id, 'platform' => 'ios', 'environment' => 'production', 'product_id' => 'qa-monthly', 'plan_revision' => 1]);
    $agreement = BillingAgreement::query()->create(['user_id' => $recipient->id, 'plan_store_product_id' => $product->id, 'provider' => 'apple', 'environment' => 'production', 'identity_fingerprint' => hash('sha256', 'qa-original'), 'reference_fingerprint' => hash('sha256', 'qa-reference'), 'purchase_reference' => 'secret-store-reference', 'provider_state' => 'active', 'features' => ['valuation' => true], 'daily_allowance' => 5]);
    $this->actingAs($admin)->get(BillingAgreementResource::getUrl('view', ['record' => $agreement]))->assertOk()->assertSee($recipient->name)->assertDontSee('secret-store-reference')->assertDontSee($agreement->identity_fingerprint);
});
