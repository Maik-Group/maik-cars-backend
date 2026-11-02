<?php

use App\Models\BillingPlanPrice;
use App\Models\User;
use App\Services\Subscriptions\StripeBillingGateway;
use Database\Seeders\MaikCatCommercialOffersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('prepares distinct commercial sandbox drafts idempotently without changing an existing published offer', function (): void {
    $published = BillingPlanPrice::query()->create([
        'environment' => 'sandbox', 'offer_key' => 'qa-wallet-1m', 'revision' => 1,
        'kind' => 'subscription', 'name' => 'Legacy offer', 'duration_months' => 1,
        'credits' => 15, 'currency' => 'USD', 'amount_minor' => 999,
        'terms_version' => 'qa-local-1', 'published_at' => now(), 'verified_at' => now(),
        'stripe_price_id' => 'price_legacy', 'offer_snapshot' => ['credits' => 15],
    ]);

    $this->seed(MaikCatCommercialOffersSeeder::class);
    $this->seed(MaikCatCommercialOffersSeeder::class);

    expect(BillingPlanPrice::query()->count())->toBe(8)
        ->and($published->fresh()->credits)->toBe(15)
        ->and($published->fresh()->stripe_price_id)->toBe('price_legacy');

    foreach (MaikCatCommercialOffersSeeder::OFFERS as $definition) {
        $offer = BillingPlanPrice::query()->where('environment', 'sandbox')
            ->where('offer_key', $definition['key'])->sole();
        expect($offer->name)->toBe($definition['name'])
            ->and($offer->credits)->toBe($definition['credits'])
            ->and($offer->amount_minor)->toBe($definition['amount'])
            ->and($offer->published_at)->toBeNull()
            ->and($offer->checkoutEligible())->toBeFalse();
        if ($definition['kind'] === 'subscription') {
            expect($offer->subscriptionPlan?->key)->toBe($definition['key']);
        }
    }
});

it('requires explicit execution and sandbox test key before any Stripe publication', function (): void {
    $this->seed(MaikCatCommercialOffersSeeder::class);
    Permission::findOrCreate('manage_subscription_products', 'web');
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('manage_subscription_products');
    $gateway = $this->mock(StripeBillingGateway::class);
    $gateway->shouldNotReceive('findCommercialTestPrice');
    $gateway->shouldNotReceive('createCommercialTestProduct');
    $gateway->shouldNotReceive('createCommercialTestPrice');

    config()->set('stripe_billing.environment', 'sandbox');
    config()->set('stripe_billing.secret_key', 'sk_test_example');
    $this->artisan('stripe:publish-commercial-test-offers', ['--actor' => $actor->id])->assertFailed();
    config()->set('stripe_billing.secret_key', 'sk_live_example');
    $this->artisan('stripe:publish-commercial-test-offers', ['--actor' => $actor->id, '--execute' => true])->assertFailed();
    config()->set('stripe_billing.secret_key', 'sk_test_example');
    config()->set('stripe_billing.environment', 'production');
    $this->artisan('stripe:publish-commercial-test-offers', ['--actor' => $actor->id, '--execute' => true])->assertFailed();
    config()->set('stripe_billing.environment', 'sandbox');
    $this->artisan('stripe:publish-commercial-test-offers', ['--execute' => true])->assertFailed();
    expect(BillingPlanPrice::query()->whereNotNull('published_at')->count())->toBe(0);
});

it('shows commercial published offers instead of QA fixtures when publication begins', function (): void {
    config()->set('stripe_billing.secret_key', 'sk_test_example');
    config()->set('stripe_billing.return_url', 'https://example.test/return');
    config()->set('stripe_billing.webhook_secret', 'whsec_test_fake');
    $qa = BillingPlanPrice::query()->create([
        'environment' => 'sandbox', 'offer_key' => 'qa-wallet-1m', 'revision' => 1,
        'kind' => 'subscription', 'name' => 'QA test offer', 'duration_months' => 1,
        'credits' => 15, 'currency' => 'USD', 'amount_minor' => 999,
        'terms_version' => 'qa-local-1', 'published_at' => now(), 'verified_at' => now(),
        'stripe_price_id' => 'price_qa', 'offer_snapshot' => ['credits' => 15],
    ]);
    expect(collect(app(\App\Services\Subscriptions\StripeCheckoutService::class)->catalog()['subscriptions'])->pluck('priceVersionId')->all())->toBe([$qa->id]);
    $this->seed(MaikCatCommercialOffersSeeder::class);
    $commercial = BillingPlanPrice::query()->where('offer_key', 'maik-starter-monthly')->firstOrFail();
    $commercial->forceFill(['published_at' => now(), 'verified_at' => now(), 'stripe_price_id' => 'price_commercial'])->save();

    $catalog = app(\App\Services\Subscriptions\StripeCheckoutService::class)->catalog();
    expect(collect($catalog['subscriptions'])->pluck('priceVersionId')->all())->toBe([$commercial->id]);
});
