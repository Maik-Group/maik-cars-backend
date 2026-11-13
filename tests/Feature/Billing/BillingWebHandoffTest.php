<?php

use App\Models\BillingPlanPrice;
use App\Models\CreditGrant;
use App\Models\User;
use App\Services\Subscriptions\StripeBillingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('stripe_billing.secret_key', 'sk_test_fake');
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
});

function billingWebTestOffer(bool $published = true, string $key = 'web-test'): BillingPlanPrice
{
    return BillingPlanPrice::create([
        'offer_key' => $key, 'revision' => 1, 'environment' => 'sandbox',
        'kind' => 'topup', 'name' => 'Extra credits', 'credits' => 20,
        'currency' => 'USD', 'amount_minor' => 500, 'terms_version' => 'v1',
        'offer_snapshot' => [], 'stripe_product_id' => 'prod_test',
        'stripe_price_id' => $published ? 'price_test' : 'price_draft_test',
        'published_at' => $published ? now() : null, 'verified_at' => $published ? now() : null,
    ]);
}

it('issues a one-use hashed handoff for a Sanctum account and denies replay', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $offer = billingWebTestOffer();
    giveActiveBillingPlan($user);
    Sanctum::actingAs($user);
    $url = $this->postJson('/api/v1/billing/web-handoff', [
        'kind' => 'topup', 'priceVersionId' => $offer->id,
    ])->assertOk()->json('data.url');
    $token = parse_url($url, PHP_URL_QUERY);
    parse_str($token, $query);
    expect(DB::table('billing_web_handoffs')->first()->token_hash)
        ->toBe(hash('sha256', $query['token']));
    $this->get($url)->assertRedirect(route('billing.web.shop', [
        'kind' => 'topup', 'priceVersionId' => $offer->id,
    ]));
    $this->get('/billing/shop')->assertOk()->assertSee($user->email);
    $this->get($url)->assertForbidden();
    expect(DB::table('billing_web_handoffs')->first()->consumed_at)->not->toBeNull();
});

it('rejects expired handoffs and inactive accounts', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $offer = billingWebTestOffer();
    giveActiveBillingPlan($user);
    Sanctum::actingAs($user);
    $url = $this->postJson('/api/v1/billing/web-handoff', [
        'kind' => 'topup', 'priceVersionId' => $offer->id,
    ])->assertOk()->json('data.url');
    DB::table('billing_web_handoffs')->update(['expires_at' => now()->subSecond()]);
    $this->get($url)->assertForbidden();
    DB::table('billing_web_handoffs')->update(['expires_at' => now()->addMinutes(5)]);
    $user->update(['is_active' => false]);
    $this->get($url)->assertForbidden();
});

it('checks actual published offers and creates checkout only via the web session', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $offer = billingWebTestOffer();
    $this->get('/billing/shop')->assertForbidden();
    giveActiveBillingPlan($user);
    Sanctum::actingAs($user);
    $url = $this->postJson('/api/v1/billing/web-handoff', [
        'kind' => 'topup', 'priceVersionId' => $offer->id,
    ])->assertOk()->json('data.url');
    $this->get($url)->assertRedirect();
    $this->get('/billing/shop')->assertOk()->assertSee('Extra credits');
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('customer')->once()->andReturn(['id' => 'cus_test']);
        $mock->shouldReceive('checkout')->once()->andReturn([
            'id' => 'cs_test', 'url' => 'https://checkout.stripe.com/c/pay/test',
            'expires_at' => now()->addMinutes(30)->timestamp,
        ]);
    });
    $this->post('/billing/shop/checkout', [
        'kind' => 'topup', 'priceVersionId' => $offer->id,
    ])->assertRedirect('https://checkout.stripe.com/c/pay/test');
    $this->get('/billing/return')->assertOk();
    expect(CreditGrant::count())->toBe(0);
});

it('rejects unsigned, unpublished, mismatched and production handoffs before issuing a token', function (): void {
    $offer = billingWebTestOffer();
    $request = ['kind' => 'topup', 'priceVersionId' => $offer->id];
    $this->postJson('/api/v1/billing/web-handoff', $request)->assertUnauthorized();

    $user = User::factory()->create(['is_active' => true]);
    giveActiveBillingPlan($user);
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/billing/web-handoff', ['kind' => 'subscription', 'priceVersionId' => $offer->id])->assertStatus(409);
    $draft = billingWebTestOffer(false, 'web-draft-test');
    $this->postJson('/api/v1/billing/web-handoff', [
        'kind' => 'topup', 'priceVersionId' => $draft->id,
    ])->assertStatus(409);
    config()->set('stripe_billing.secret_key', 'sk_live_fake');
    $this->postJson('/api/v1/billing/web-handoff', $request)->assertForbidden();
    expect(DB::table('billing_web_handoffs')->count())->toBe(0);
});

it('never exposes sandbox complimentary grants in production membership summaries or vice versa', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    foreach (['sandbox', 'production'] as $environment) {
        DB::table('complimentary_allocations')->insert([
            'user_id' => $user->id, 'environment' => $environment, 'credits' => $environment === 'sandbox' ? 7 : 9,
            'months' => 1, 'issuance_mode' => 'once', 'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/billing/subscription')
        ->assertOk()->assertJsonCount(1, 'data.complimentaryEntitlements')
        ->assertJsonPath('data.complimentaryEntitlements.0.creditsPerAllocation', 7);
    config()->set('stripe_billing.secret_key', 'sk_live_fake');
    $this->getJson('/api/v1/billing/subscription')
        ->assertOk()->assertJsonCount(1, 'data.complimentaryEntitlements')
        ->assertJsonPath('data.complimentaryEntitlements.0.creditsPerAllocation', 9);
});
