<?php

use App\Models\BillingPlanPrice;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeCheckoutService;
use Database\Seeders\AuthUserSeeder;
use Database\Seeders\MaikCatCommercialOffersSeeder;
use Database\Seeders\PublishMaikCatCommercialOffersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('stripe_billing.secret_key', null);
    config()->set('stripe_billing.environment', null);
});

it('publishes all seven default commercial offers automatically with a configured Stripe test key', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AuthUserSeeder::class);
    $this->seed(MaikCatCommercialOffersSeeder::class);
    config()->set('stripe_billing.secret_key', 'sk_test_mock_only');
    config()->set('stripe_billing.environment', 'sandbox');
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    config()->set('stripe_billing.webhook_secret', 'whsec_mock_only');

    $gateway = $this->mock(StripeBillingGateway::class);
    $gateway->shouldReceive('findCommercialTestPrice')->times(7)->andReturnNull();
    $gateway->shouldReceive('createCommercialTestProduct')->times(7)
        ->andReturnUsing(fn (BillingPlanPrice $offer): array => [
            'id' => 'prod_mock_'.$offer->getKey(), 'livemode' => false,
        ]);
    $gateway->shouldReceive('createCommercialTestPrice')->times(7)
        ->andReturnUsing(fn (BillingPlanPrice $offer, string $productId): array => [
            'id' => 'price_mock_'.$offer->getKey(), 'livemode' => false,
        ]);
    $gateway->shouldReceive('price')->times(7)
        ->andReturnUsing(function (string $stripePriceId): array {
            $offer = BillingPlanPrice::query()->where('stripe_price_id', $stripePriceId)->firstOrFail();

            return [
                'id' => $stripePriceId,
                'product' => $offer->stripe_product_id,
                'active' => true,
                'currency' => strtolower($offer->currency),
                'unit_amount' => $offer->amount_minor,
                'type' => $offer->kind === 'subscription' ? 'recurring' : 'one_time',
                'recurring' => $offer->kind === 'subscription'
                    ? ['interval' => 'month', 'interval_count' => $offer->duration_months] : null,
            ];
        });

    $this->seed(PublishMaikCatCommercialOffersSeeder::class);
    $offers = BillingPlanPrice::query()->where('offer_key', 'like', 'maik-%')->get();
    expect($offers)->toHaveCount(7)
        ->and($offers->every(fn (BillingPlanPrice $offer) => $offer->checkoutEligible()))->toBeTrue()
        ->and($offers->every(fn (BillingPlanPrice $offer) => filled($offer->stripe_product_id) && filled($offer->stripe_price_id)))->toBeTrue();

    $catalog = app(StripeCheckoutService::class)->catalog();
    expect($catalog['subscriptions'])->toHaveCount(4)
        ->and($catalog['topUps'])->toHaveCount(3);

    // Re-seeding an already published catalog must never create more Stripe objects.
    $this->seed(PublishMaikCatCommercialOffersSeeder::class);
    expect(BillingPlanPrice::query()->count())->toBe(7);
});

it('never publishes automatically without sandbox test credentials', function (): void {
    $this->seed(MaikCatCommercialOffersSeeder::class);
    $gateway = $this->mock(StripeBillingGateway::class);
    $gateway->shouldNotReceive('findCommercialTestPrice');
    $gateway->shouldNotReceive('createCommercialTestProduct');
    $gateway->shouldNotReceive('createCommercialTestPrice');

    $this->seed(PublishMaikCatCommercialOffersSeeder::class);
    config()->set('stripe_billing.secret_key', 'sk_live_never_publish');
    config()->set('stripe_billing.environment', 'production');
    $this->seed(PublishMaikCatCommercialOffersSeeder::class);
    config()->set('stripe_billing.secret_key', 'sk_test_mismatch');
    config()->set('stripe_billing.environment', 'production');
    $this->seed(PublishMaikCatCommercialOffersSeeder::class);

    expect(BillingPlanPrice::query()->whereNotNull('published_at')->count())->toBe(0)
        ->and(BillingPlanPrice::query()->whereNotNull('stripe_price_id')->count())->toBe(0);
});

it('refuses automatic publication when the seeded administrator is not authorized', function (): void {
    $this->seed(MaikCatCommercialOffersSeeder::class);
    config()->set('stripe_billing.secret_key', 'sk_test_mock_only');
    config()->set('stripe_billing.environment', 'sandbox');
    $this->mock(StripeBillingGateway::class)->shouldNotReceive('createCommercialTestProduct');

    expect(fn () => $this->seed(PublishMaikCatCommercialOffersSeeder::class))
        ->toThrow(RuntimeException::class, 'seeded administrator is missing');
    expect(BillingPlanPrice::query()->whereNotNull('published_at')->count())->toBe(0);
});

it('fails instead of declaring success if Stripe responds with a live product', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AuthUserSeeder::class);
    $this->seed(MaikCatCommercialOffersSeeder::class);
    config()->set('stripe_billing.secret_key', 'sk_test_mock_only');
    config()->set('stripe_billing.environment', 'sandbox');

    $gateway = $this->mock(StripeBillingGateway::class);
    $gateway->shouldReceive('findCommercialTestPrice')->once()->andReturnNull();
    $gateway->shouldReceive('createCommercialTestProduct')->once()->andReturn([
        'id' => 'prod_untrusted', 'livemode' => true,
    ]);
    $gateway->shouldNotReceive('createCommercialTestPrice');

    expect(fn () => $this->seed(PublishMaikCatCommercialOffersSeeder::class))
        ->toThrow(RuntimeException::class, 'Invalid Stripe test product');
    expect(BillingPlanPrice::query()->whereNotNull('published_at')->count())->toBe(0)
        ->and(BillingPlanPrice::query()->whereNotNull('stripe_price_id')->count())->toBe(0);
});
