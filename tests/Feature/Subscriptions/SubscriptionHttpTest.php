<?php

use App\Models\Item;
use App\Models\SubscriptionEntitlement;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Legacy subscription fixtures explicitly use production identities.
    // Keep this isolated test deterministic when local QA runs sandbox.
    config(['stripe_billing.environment' => 'production']);
    app(ItemPriceSettingsService::class)->updateRatePercent(100);
    $spot = Mockery::mock(MetalsSpotService::class);
    $spot->shouldReceive('all')->andReturn(['data' => [
        ['key' => 'platinum', 'price_gram' => 10], ['key' => 'palladium', 'price_gram' => 20], ['key' => 'rhodium', 'price_gram' => 30],
    ]]);
    app()->instance(MetalsSpotService::class, $spot);
});

test('all ordinary catalog paths redact protected values without consuming usage', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $item = Item::factory()->create(['weight_kg' => 1, 'pt_ppm' => 100, 'pd_ppm' => 0, 'rh_ppm' => 0]);
    Item::factory()->create(['car_group_id' => $item->car_group_id, 'weight_kg' => 1, 'pt_ppm' => 50]);
    $user->savedItems()->attach($item);
    foreach ([['/api/items', 'data.0'], ['/api/home/top_items', 'topItems.0'], ['/api/items/'.$item->id, 'data'], ['/api/items/'.$item->id, 'related.0'], ['/api/items/'.$item->id.'/similar', 'data.0'], ['/api/saved-items', 'data.0']] as [$route, $prefix]) {
        $response = $this->getJson($route)->assertOk();
        foreach (['price', 'basePrice', 'weightKg', 'ptPpm', 'pdPpm', 'rhPpm'] as $field) {
            $response->assertJsonPath($prefix.'.'.$field, null);
        }
        $response->assertJsonPath($prefix.'.access.valuationRequired', true);
    }
    $this->assertDatabaseCount('daily_usage', 0);
});

test('legacy daily entitlements no longer allow opening product valuations without a paid search', function (): void {
    $user = User::factory()->create(['discount_margin_percent' => -10]);
    Sanctum::actingAs($user);
    SubscriptionEntitlement::query()->create([
        'user_id' => $user->id, 'source' => 'grant', 'environment' => 'production', 'daily_allowance' => 1,
        'features' => ['valuation' => true], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'reason' => 'Test approval',
    ]);
    $item = Item::factory()->create(['serial_code' => 'HTTP-1', 'weight_kg' => 1, 'pt_ppm' => 100, 'pd_ppm' => 0, 'rh_ppm' => 0]);
    $route = '/api/v1/items/'.$item->id.'/valuation';
    $this->postJson($route, ['currency' => 'USD'])->assertStatus(422)->assertJsonMissingPath('data');
    $this->assertDatabaseCount('daily_usage_reveals', 0);
    $this->assertDatabaseCount('credit_consumptions', 0);
});

test('legacy valuations require authentication and a search receipt without leaking assays', function (): void {
    $item = Item::factory()->create();
    $route = '/api/v1/items/'.$item->id.'/valuation';
    $this->postJson($route)->assertUnauthorized();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->postJson($route)->assertStatus(422)->assertJsonMissingPath('data');
    $user->update(['is_active' => false]);
    $this->postJson($route, ['searchId' => '00000000-0000-4000-8000-000000000001'])->assertForbidden();
    $item->update(['weight_kg' => 0]);
    $this->postJson($route)->assertStatus(422);
    $this->assertDatabaseCount('daily_usage', 0);
});

test('a search whose valuation cannot be calculated costs no credit', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $item = Item::factory()->create(['weight_kg' => 1, 'pt_ppm' => 100, 'pd_ppm' => 0, 'rh_ppm' => 0]);
    app(CreditWalletService::class)
        ->grant($user, 'paid_cycle', 1, 'legacy-search-unavailable', now('UTC')->toImmutable()->addDay());
    $spot = Mockery::mock(MetalsSpotService::class);
    $spot->shouldReceive('all')->andReturn(['data' => []]);
    app()->instance(MetalsSpotService::class, $spot);
    $this->postJson('/api/v2/items/search', ['text' => $item->serial_code],
        ['Idempotency-Key' => 'failed-search-price-1234'])->assertStatus(503);
    $this->assertDatabaseCount('daily_usage', 0);
    $this->assertDatabaseCount('credit_consumptions', 0);
    $this->getJson('/api/items/'.$item->id)->assertOk()->assertJsonPath('data.access.valuationRequired', true);
});

test('status exposes only account binding and safe access data', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/subscriptions/status')->assertOk()
        ->assertJsonPath('data.active', false)->assertJsonPath('data.purchasesEnabled', true)
        ->assertJsonStructure(['data' => ['accountBinding' => ['apple', 'google'], 'usage' => ['limit', 'used', 'remaining', 'resetsAt']]])
        ->assertJsonMissingPath('data.purchaseReference');
});
