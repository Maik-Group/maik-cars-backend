<?php

use App\Filament\Resources\BillingPlanPrices\BillingPlanPriceResource;
use App\Filament\Support\CurrencyOfferPricing;
use App\Models\BillingPlanPrice;
use App\Models\User;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeCheckoutService;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

test('editing USD and EUR pricing retains published snapshots and creates separate audited drafts', function (): void {
    config()->set('stripe_billing.environment', 'sandbox');
    config()->set('stripe_billing.secret_key', 'sk_test_dual_currency_test');
    Permission::findOrCreate('manage_subscription_products', 'web');
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('manage_subscription_products');

    $service = app(StripeOfferCatalogService::class);
    $usd = $service->createDraft($actor, [
        'offer_key' => 'credits-10', 'revision' => 1, 'environment' => 'sandbox',
        'kind' => 'topup', 'name' => '10 Credits', 'duration_months' => null,
        'credits' => 10, 'currency' => 'USD', 'amount_minor' => 399,
        'terms_version' => 'v1', 'stripe_product_id' => 'prod_usd', 'stripe_price_id' => 'price_usd',
    ], 'Initial USD price');
    $gateway = Mockery::mock(StripeBillingGateway::class);
    $gateway->shouldReceive('price')->with('price_usd')->once()->andReturn([
        'id' => 'price_usd', 'active' => true, 'currency' => 'usd', 'unit_amount' => 399,
        'product' => 'prod_usd', 'type' => 'one_time', 'recurring' => null,
    ]);
    $service->verifyAndPublish($actor, $usd, 'Published', $gateway);

    CurrencyOfferPricing::save($actor, 'credits-10', 'topup', '10 Credits', 10, null, null, [
        'price_usd' => '4.99', 'price_eur' => '3.49',
        'stripe_product_id_eur' => 'prod_eur', 'stripe_price_id_eur' => 'price_eur',
    ]);

    expect($usd->fresh()->amount_minor)->toBe(399)
        ->and($usd->fresh()->checkoutEligible())->toBeTrue()
        ->and(CurrencyOfferPricing::pending('credits-10', 'USD')?->amount_minor)->toBe(499)
        ->and(CurrencyOfferPricing::pending('credits-10', 'EUR')?->amount_minor)->toBe(349)
        ->and(CurrencyOfferPricing::display('credits-10', 'USD'))->toContain('draft pending')
        ->and(BillingPlanPrice::query()->where('offer_key', 'credits-10')->count())->toBe(3)
        ->and(BillingPlanPriceResource::getEloquentQuery()->count())->toBe(1);

    $gateway->shouldReceive('price')->with('price_eur')->once()->andReturn([
        'id' => 'price_eur', 'active' => true, 'currency' => 'eur', 'unit_amount' => 349,
        'product' => 'prod_eur', 'type' => 'one_time', 'recurring' => null,
    ]);
    $service->verifyAndPublish($actor, CurrencyOfferPricing::pending('credits-10', 'EUR'), 'Published EUR', $gateway);
    $offers = app(StripeCheckoutService::class)->catalog()['topUps'];
    expect(collect($offers)->pluck('currency')->sort()->values()->all())->toBe(['EUR', 'USD']);
    expect(collect($offers)->firstWhere('currency', 'USD')['amountMinor'])->toBe(399);
    expect(collect($offers)->firstWhere('currency', 'EUR')['amountMinor'])->toBe(349);
});

test('EUR price cannot be added without matching Stripe identifiers when only one is supplied', function (): void {
    config()->set('stripe_billing.environment', 'sandbox');
    Permission::findOrCreate('manage_subscription_products', 'web');
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('manage_subscription_products');

    expect(fn () => CurrencyOfferPricing::save(
        $actor, 'credits-20', 'topup', '20 Credits', 20, null, null,
        ['price_eur' => '8.50', 'stripe_product_id_eur' => 'prod_only']
    ))->toThrow(ValidationException::class);
    expect(BillingPlanPrice::query()->count())->toBe(0);
});

test('retiring a package currency retires all published revisions so no older price resurfaces', function (): void {
    config()->set('stripe_billing.environment', 'sandbox');
    config()->set('stripe_billing.secret_key', 'sk_test_retire_currency');
    Permission::findOrCreate('manage_subscription_products', 'web');
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('manage_subscription_products');
    $service = app(StripeOfferCatalogService::class);
    $gateway = Mockery::mock(StripeBillingGateway::class);

    foreach ([1 => 399, 2 => 499] as $revision => $amount) {
        $draft = $service->createDraft($actor, [
            'offer_key' => 'credits-retire', 'revision' => $revision, 'environment' => 'sandbox',
            'kind' => 'topup', 'name' => 'Retire credits', 'duration_months' => null,
            'credits' => 10, 'currency' => 'USD', 'amount_minor' => $amount,
            'terms_version' => 'v1', 'stripe_product_id' => 'prod_'.$revision,
            'stripe_price_id' => 'price_'.$revision,
        ], 'New revision');
        $gateway->shouldReceive('price')->with('price_'.$revision)->once()->andReturn([
            'id' => 'price_'.$revision, 'active' => true, 'currency' => 'usd',
            'unit_amount' => $amount, 'product' => 'prod_'.$revision,
            'type' => 'one_time', 'recurring' => null,
        ]);
        $service->verifyAndPublish($actor, $draft, 'Published', $gateway);
    }

    CurrencyOfferPricing::retireCurrency($actor, 'credits-retire', 'USD');
    expect(BillingPlanPrice::query()->where('offer_key', 'credits-retire')->whereNull('retired_at')->count())->toBe(0);
    expect(app(StripeCheckoutService::class)->catalog()['topUps'])->toBe([]);
});
