<?php

use App\Models\CreditLedgerEntry;
use App\Models\Item;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Mobile\MetalsSpotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 23:58:00', 'UTC'));
    app(ItemPriceSettingsService::class)->updateRatePercent(100);
    $spot = Mockery::mock(MetalsSpotService::class);
    $spot->shouldReceive('all')->andReturn(['data' => [
        ['key' => 'platinum', 'price_gram' => 10],
        ['key' => 'palladium', 'price_gram' => 20],
        ['key' => 'rhodium', 'price_gram' => 30],
    ]]);
    app()->instance(MetalsSpotService::class, $spot);
});

it('returns 402 without a debit and returns a 90 second lease on paid reveal', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $item = Item::factory()->create(['weight_kg' => 1, 'pt_ppm' => 100, 'pd_ppm' => 0, 'rh_ppm' => 0]);
    $route = '/api/v2/items/'.$item->id.'/valuation';
    $this->postJson($route)->assertStatus(402)->assertJsonMissingPath('data');
    $this->assertDatabaseCount('credit_consumptions', 0);
    app(CreditWalletService::class)->grant($user, 'paid_cycle', 1, 'invoice:http', now('UTC')->toImmutable()->addDay());
    $result = $this->postJson($route)->assertOk()
        ->assertJsonPath('access.chargedCredits', 1)
        ->assertJsonPath('access.viewDateUtc', '2026-11-01')
        ->assertJsonPath('access.validUntil', '2026-11-01T23:59:30+00:00')
        ->assertJsonPath('access.freeReopenUntil', '2026-11-02T00:00:00+00:00');
    expect($result->json('wallet.counted'))->toBeTrue();
    $this->assertDatabaseCount('credit_consumptions', 1);
});

it('allows read-only revalidation of existing receipt and refuses new family or next day', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $wallet = app(CreditWalletService::class);
    $wallet->grant($user, 'paid_cycle', 2, 'invoice:next-day', now('UTC')->toImmutable()->addDays(3));
    $item = Item::factory()->create(['weight_kg' => 1, 'pt_ppm' => 100, 'pd_ppm' => 0, 'rh_ppm' => 0]);
    $other = Item::factory()->create(['weight_kg' => 1, 'pt_ppm' => 100, 'pd_ppm' => 0, 'rh_ppm' => 0]);
    $route = '/api/v2/items/'.$item->id.'/valuation';
    $this->postJson($route)->assertOk()->assertJsonPath('access.chargedCredits', 1);
    $this->postJson($route, ['revalidate_date' => '2026-11-01'])->assertOk()
        ->assertJsonPath('access.chargedCredits', 0);
    $this->postJson('/api/v2/items/'.$other->id.'/valuation', ['revalidate_date' => '2026-11-01'])->assertStatus(409);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 00:00:01', 'UTC'));
    $this->postJson($route, ['revalidate_date' => '2026-11-01'])->assertStatus(409);
    $this->postJson($route, ['revalidate_date' => '2026-11-02'])->assertStatus(409);
    expect($wallet->balance($user))->toBe(1);
    $this->assertDatabaseCount('credit_consumptions', 1);
    expect(CreditLedgerEntry::query()->where('entry_type', 'spend')->count())->toBe(1);
});
