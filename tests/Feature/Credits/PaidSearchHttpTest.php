<?php

use App\Models\Item;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('stripe_billing.secret_key', 'sk_test_paid_search_feature');
    config()->set('stripe_billing.environment', 'sandbox');
    app(ItemPriceSettingsService::class)->updateRatePercent(100);
    $spot = Mockery::mock(MetalsSpotService::class);
    $spot->shouldReceive('all')->andReturn(['data' => [
        ['key' => 'platinum', 'price_gram' => 10],
        ['key' => 'palladium', 'price_gram' => 20],
        ['key' => 'rhodium', 'price_gram' => 30],
    ]]);
    app()->instance(MetalsSpotService::class, $spot);
});

function makePaidSearchItem(string $serial): Item
{
    return Item::factory()->create([
        'serial_code' => $serial, 'model' => 'TEST CAT',
        'weight_kg' => 1, 'pt_ppm' => 100, 'pd_ppm' => 0, 'rh_ppm' => 0,
    ]);
}

it('charges exactly one credit for a nonempty explicit search, not its pages or item details', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $item = makePaidSearchItem('SEARCH-ONE');
    $wallet = app(CreditWalletService::class);
    $wallet->grant($user, 'paid_cycle', 2, 'paid-search:one', now('UTC')->toImmutable()->addWeek());
    $result = $this->postJson('/api/v2/items/search', ['text' => 'SEARCH-ONE', 'perPage' => 10],
        ['Idempotency-Key' => 'search-intent-test-123456'])
        ->assertOk()->assertJsonPath('wallet.remaining', 1)->assertJsonCount(1, 'data');
    $searchId = $result->json('searchId');
    expect($searchId)->toBeString();
    $this->getJson('/api/v2/items/search/'.$searchId.'?page=2')->assertOk()->assertJsonPath('wallet.remaining', 1);
    $this->getJson('/api/v2/items/'.$item->id.'/valuation?searchId='.$searchId)
        ->assertOk()->assertJsonPath('data.itemId', $item->id)->assertJsonPath('access.chargedCredits', 0);
    $this->getJson('/api/v2/items/'.$item->id.'/valuation?searchId='.$searchId)->assertOk();
    $this->assertDatabaseCount('credit_consumptions', 1);
    expect($wallet->balance($user))->toBe(1);
});

it('does not charge an empty search and requires available credit only when results exist', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    makePaidSearchItem('SEARCH-HIT');
    $this->postJson('/api/v2/items/search', ['text' => 'no-results-999999'],
        ['Idempotency-Key' => 'empty-search-1234567890'])
        ->assertOk()->assertJsonPath('searchId', null)->assertJsonPath('wallet.remaining', 0);
    $this->postJson('/api/v2/items/search', ['text' => 'SEARCH-HIT'],
        ['Idempotency-Key' => 'search-when-empty-wallet'])
        ->assertStatus(402);
    $this->assertDatabaseCount('credit_consumptions', 0);
});

it('does not let SQL wildcards unlock the complete catalog as a single search', function (): void {
    Sanctum::actingAs(User::factory()->create());
    makePaidSearchItem('WILDCARD-CAT');
    foreach (['%', '_', '%%', '!'] as $term) {
        $this->postJson('/api/v2/items/search', ['text' => $term],
            ['Idempotency-Key' => 'invalid-search-'.bin2hex(random_bytes(10))])->assertStatus(422);
    }
    $this->assertDatabaseCount('credit_consumptions', 0);
});

it('does not duplicate a search charge on retry and rejects an idempotency key reused for another term', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    makePaidSearchItem('RETRY-CAT');
    app(CreditWalletService::class)->grant($user, 'paid_cycle', 2, 'paid-search:retry', now('UTC')->toImmutable()->addWeek());
    $headers = ['Idempotency-Key' => 'identical-search-key-1234'];
    $first = $this->postJson('/api/v2/items/search', ['text' => 'RETRY-CAT'], $headers)->assertOk();
    $again = $this->postJson('/api/v2/items/search', ['text' => 'RETRY-CAT'], $headers)->assertOk();
    expect($first->json('searchId'))->toBe($again->json('searchId'));
    $this->postJson('/api/v2/items/search', ['text' => 'ANOTHER'], $headers)->assertStatus(409);
    $this->assertDatabaseCount('credit_consumptions', 1);
});

it('scopes free detail reads to the paid search result and search owner', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $matched = makePaidSearchItem('MATCH-ALPHA');
    $notMatched = makePaidSearchItem('BETA-OTHER');
    app(CreditWalletService::class)->grant($user, 'paid_cycle', 1, 'paid-search:access', now('UTC')->toImmutable()->addWeek());
    $searchId = $this->postJson('/api/v2/items/search', ['text' => 'MATCH-ALPHA'],
        ['Idempotency-Key' => 'search-access-check-12345'])->assertOk()->json('searchId');
    $this->getJson('/api/v2/items/'.$notMatched->id.'/valuation?searchId='.$searchId)->assertStatus(403);
    $this->getJson('/api/v2/items/'.$matched->id.'/valuation')->assertStatus(422);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/items/'.$matched->id.'/valuation?searchId='.$searchId)->assertNotFound();
});

it('keeps old valuation POST endpoints from charging when a paid search session is missing', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $item = makePaidSearchItem('OLD-ROUTE');
    app(CreditWalletService::class)->grant($user, 'paid_cycle', 2, 'paid-search:legacy', now('UTC')->toImmutable()->addWeek());
    $this->postJson('/api/v1/items/'.$item->id.'/valuation')->assertStatus(422);
    $this->postJson('/api/v2/items/'.$item->id.'/valuation')->assertStatus(422);
    $this->getJson('/api/items/'.$item->id)->assertOk()->assertJsonPath('data.price', null);
    $this->assertDatabaseCount('credit_consumptions', 0);
});

it('keeps the last paid search accessible after the last credit is consumed and expires access after its lease', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $item = makePaidSearchItem('LAST-CREDIT');
    app(CreditWalletService::class)->grant($user, 'paid_cycle', 1, 'last-credit-search', now('UTC')->toImmutable()->addWeek());
    $result = $this->postJson('/api/v2/items/search', ['text' => 'LAST-CREDIT'],
        ['Idempotency-Key' => 'last-credit-paid-search-123'])
        ->assertOk()->assertJsonPath('wallet.remaining', 0);
    $sessionId = $result->json('searchId');
    expect($result->json('searchExpiresAt'))->toBeString();
    $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.balance', 0)
        ->assertJsonPath('data.activeSearchUntil', $result->json('searchExpiresAt'));
    $this->getJson('/api/v2/items/'.$item->id.'/valuation?searchId='.$sessionId)->assertOk();
    $this->travel(25)->hours();
    $this->getJson('/api/v2/items/'.$item->id.'/valuation?searchId='.$sessionId)->assertStatus(410);
    $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.activeSearchUntil', null);
});

it('immediately revokes search price access when its funding credit is revoked', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $item = makePaidSearchItem('REVOKED-SEARCH');
    $wallet = app(CreditWalletService::class);
    $grant = $wallet->grant($user, 'paid_cycle', 1, 'revoked-search', now('UTC')->toImmutable()->addWeek());
    $sessionId = $this->postJson('/api/v2/items/search', ['text' => 'REVOKED-SEARCH'],
        ['Idempotency-Key' => 'revoked-search-1234567'])->assertOk()->json('searchId');
    $wallet->revoke($grant, 'refund:test');
    $this->getJson('/api/v2/items/'.$item->id.'/valuation?searchId='.$sessionId)->assertForbidden();
    $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.activeSearchUntil', null);
});
