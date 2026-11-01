<?php

use App\Models\Item;
use App\Models\SubscriptionEntitlement;
use App\Models\User;
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

test('reveal returns discounted existing pricing and atomically counts the item family', function (): void {
    $user = User::factory()->create(['discount_margin_percent' => -10]);
    Sanctum::actingAs($user);
    SubscriptionEntitlement::query()->create([
        'user_id' => $user->id, 'source' => 'grant', 'environment' => 'production', 'daily_allowance' => 1,
        'features' => ['valuation' => true], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'reason' => 'Test approval',
    ]);
    $item = Item::factory()->create(['serial_code' => 'HTTP-1', 'weight_kg' => 1, 'pt_ppm' => 100, 'pd_ppm' => 0, 'rh_ppm' => 0]);
    $route = '/api/v1/items/'.$item->id.'/valuation';
    $this->postJson($route, ['currency' => 'USD'])->assertOk()
        ->assertJsonPath('data.price', 0.9)->assertJsonPath('data.basePrice', 1)
        ->assertJsonPath('usage.counted', true)->assertJsonPath('usage.remaining', 0);
    $this->postJson($route)->assertOk()->assertJsonPath('usage.counted', false);
    $other = Item::factory()->create(['serial_code' => 'HTTP-2']);
    $this->postJson('/api/v1/items/'.$other->id.'/valuation')->assertStatus(429)
        ->assertJsonPath('code', 'DAILY_QUOTA_EXHAUSTED')->assertJsonMissingPath('data');
    $this->assertDatabaseCount('daily_usage_reveals', 1);
});

test('reveal rejects guests missing access inactive accounts and hidden items without exposing assays', function (): void {
    $item = Item::factory()->create();
    $route = '/api/v1/items/'.$item->id.'/valuation';
    $this->postJson($route)->assertUnauthorized();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->postJson($route)->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED')->assertJsonMissingPath('data');
    $user->update(['is_active' => false]);
    $this->postJson($route)->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    $item->update(['weight_kg' => 0]);
    $this->postJson($route)->assertNotFound();
    $this->assertDatabaseCount('daily_usage', 0);
});

test('valuation failure consumes no allowance and protected item access remains enforced', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $item = Item::factory()->create(['weight_kg' => 1, 'pt_ppm' => 100, 'pd_ppm' => 0, 'rh_ppm' => 0]);
    $spot = Mockery::mock(MetalsSpotService::class);
    $spot->shouldReceive('all')->andReturn(['data' => []]);
    app()->instance(MetalsSpotService::class, $spot);
    $this->postJson('/api/v1/items/'.$item->id.'/valuation')->assertStatus(503)->assertJsonPath('code', 'VALUATION_UNAVAILABLE');
    $this->assertDatabaseCount('daily_usage', 0);
    $this->getJson('/api/items/'.$item->id)->assertOk()->assertJsonPath('data.access.valuationRequired', true);
});

test('status exposes only account binding and safe access data', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/subscriptions/status')->assertOk()
        ->assertJsonPath('data.active', false)->assertJsonPath('data.purchasesEnabled', true)
        ->assertJsonStructure(['data' => ['accountBinding' => ['apple', 'google'], 'usage' => ['limit', 'used', 'remaining', 'resetsAt']]])
        ->assertJsonMissingPath('data.purchaseReference');
});
