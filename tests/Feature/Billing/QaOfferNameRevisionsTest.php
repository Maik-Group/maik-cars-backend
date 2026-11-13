<?php

use App\Models\BillingOrder;
use App\Models\BillingPlanPrice;
use App\Models\User;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeCheckoutService;
use Database\Seeders\MaikCatQaOfferNameRevisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('seeds clean new display names without modifying a published historical QA price', function (): void {
    $old = BillingPlanPrice::query()->create([
        'environment' => 'sandbox', 'offer_key' => 'qa-topup-10', 'revision' => 1,
        'kind' => 'topup', 'name' => 'QA 10 credits — test price',
        'credits' => 10, 'currency' => 'USD', 'amount_minor' => 399,
        'terms_version' => 'qa-local-1', 'published_at' => now(), 'verified_at' => now(),
        'stripe_price_id' => 'price_qa_original', 'stripe_product_id' => 'prod_qa_original',
        'offer_snapshot' => ['name' => 'QA 10 credits — test price', 'credits' => 10],
    ]);

    $this->seed(MaikCatQaOfferNameRevisionSeeder::class);
    $this->seed(MaikCatQaOfferNameRevisionSeeder::class);

    $new = BillingPlanPrice::query()->where('offer_key', $old->offer_key)->where('revision', 2)->sole();
    expect(BillingPlanPrice::query()->count())->toBe(2)
        ->and($old->fresh()->name)->toBe('QA 10 credits — test price')
        ->and($old->fresh()->stripe_price_id)->toBe('price_qa_original')
        ->and($new->name)->toBe('10 Extra Credits')
        ->and($new->stripe_price_id)->toBeNull()
        ->and($new->published_at)->toBeNull();

    config()->set('stripe_billing.secret_key', 'sk_test_fake');
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    config()->set('stripe_billing.webhook_secret', 'whsec_fake');
    $catalog = app(StripeCheckoutService::class);
    expect(collect($catalog->catalog()['topUps'])->pluck('priceVersionId')->all())->toBe([$old->id]);

    $new->forceFill(['stripe_price_id' => 'price_qa_revision', 'stripe_product_id' => 'prod_qa_revision',
        'verified_at' => now(), 'published_at' => now()])->save();
    expect(collect($catalog->catalog()['topUps'])->pluck('priceVersionId')->all())->toBe([$new->id]);

    $historicalOrder = new BillingOrder;
    $historicalOrder->forceFill([
        'kind' => 'topup', 'environment' => 'sandbox', 'billing_plan_price_id' => $old->id,
        'offer_snapshot' => ['name' => 'QA 10 credits — test price'],
    ]);
    expect($catalog->currentDisplayNameFor($historicalOrder))->toBe('10 Extra Credits');
    expect($catalog->currentDisplayNameForPrice($old))->toBe('10 Extra Credits');
    // No purchased terms are altered just to rename the membership display.
    expect($historicalOrder->offer_snapshot['name'])->toBe('QA 10 credits — test price');
});

it('requires an explicit sandbox execution and authorized actor before remote QA offer publication', function (): void {
    $gateway = $this->mock(StripeBillingGateway::class);
    $gateway->shouldNotReceive('findQaPrice');
    $gateway->shouldNotReceive('createQaProduct');
    $gateway->shouldNotReceive('createQaPrice');
    config()->set('stripe_billing.environment', 'sandbox');
    config()->set('stripe_billing.secret_key', 'sk_test_fake');
    $this->artisan('stripe:publish-qa-offer-names')->assertFailed();
    $this->artisan('stripe:publish-qa-offer-names', ['--execute' => true])->assertFailed();
    Permission::findOrCreate('manage_subscription_products', 'web');
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('manage_subscription_products');
    config()->set('stripe_billing.secret_key', 'sk_live_fake');
    $this->artisan('stripe:publish-qa-offer-names', ['--execute' => true, '--actor' => $actor->id])->assertFailed();
});
