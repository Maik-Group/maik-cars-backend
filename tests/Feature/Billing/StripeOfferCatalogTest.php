<?php

use App\Models\SubscriptionAuditLog;
use App\Models\User;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function offerAttributes(): array
{
    return ['offer_key' => 'standard', 'revision' => 1, 'environment' => 'sandbox',
        'kind' => 'subscription', 'name' => 'Standard', 'duration_months' => 3,
        'credits' => 30, 'currency' => 'USD', 'amount_minor' => 2900,
        'terms_version' => 'v1', 'stripe_product_id' => 'prod_123', 'stripe_price_id' => 'price_123'];
}

function offerActor(): User
{
    Permission::findOrCreate('manage_subscription_products', 'web');
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('manage_subscription_products');

    return $actor;
}

test('draft creation is local and authorized without a configured Stripe client', function () {
    $actor = offerActor();
    $offer = app(StripeOfferCatalogService::class)->createDraft($actor, offerAttributes(), 'New catalog revision');
    expect($offer->published_at)->toBeNull()
        ->and($offer->offer_snapshot['credits'])->toBe(30)
        ->and($offer->checkoutEligible())->toBeFalse();
    expect(SubscriptionAuditLog::query()->where('subject_id', $offer->id)->where('action', 'create_stripe_offer_draft')->exists())->toBeTrue();
});

test('unprivileged users cannot create, publish, or retire offers', function () {
    $actor = offerActor();
    $offer = app(StripeOfferCatalogService::class)->createDraft($actor, offerAttributes(), 'Draft');
    $other = User::factory()->create(['is_active' => true]);
    $service = app(StripeOfferCatalogService::class);
    expect(fn () => $service->createDraft($other, offerAttributes(), 'Unauthorized'))->toThrow(AuthorizationException::class);
    expect(fn () => $service->verifyAndPublish($other, $offer, 'Unauthorized', Mockery::mock(StripeBillingGateway::class)))->toThrow(AuthorizationException::class);
    expect(fn () => $service->retire($other, $offer, 'Unauthorized'))->toThrow(AuthorizationException::class);
});

test('publishing requires exact Stripe metadata and freezes the snapshot', function () {
    $actor = offerActor();
    $service = app(StripeOfferCatalogService::class);
    $offer = $service->createDraft($actor, offerAttributes(), 'Draft');
    $gateway = Mockery::mock(StripeBillingGateway::class);
    $valid = ['id' => 'price_123', 'active' => true, 'currency' => 'usd', 'unit_amount' => 2900,
        'product' => 'prod_123', 'type' => 'recurring', 'recurring' => ['interval' => 'month', 'interval_count' => 3]];
    $gateway->shouldReceive('price')->once()->andReturn([...$valid, 'unit_amount' => 2901]);
    expect(fn () => $service->verifyAndPublish($actor, $offer, 'Review', $gateway))->toThrow(ValidationException::class);
    expect($offer->fresh()->published_at)->toBeNull();
    $gateway->shouldReceive('price')->once()->andReturn($valid);
    $published = $service->verifyAndPublish($actor, $offer, 'Verified against Stripe', $gateway);
    expect($published->checkoutEligible())->toBeTrue();
    expect(fn () => $published->update(['credits' => 999]))->toThrow(LogicException::class);
    expect(fn () => $published->delete())->toThrow(LogicException::class);
    $service->retire($actor, $published, 'Retired revision');
    expect($published->fresh()->checkoutEligible())->toBeFalse();
    expect(fn () => $published->fresh()->update(['retired_at' => null]))->toThrow(LogicException::class);
    expect(SubscriptionAuditLog::query()->where('subject_id', $offer->id)->count())->toBe(3);
});

test('topups must match Stripe one-time prices and subscriptions require a term', function () {
    $actor = offerActor();
    $service = app(StripeOfferCatalogService::class);
    expect(fn () => $service->createDraft($actor, [...offerAttributes(), 'duration_months' => null], 'Invalid'))->toThrow(ValidationException::class);
    $topup = $service->createDraft($actor, [...offerAttributes(), 'offer_key' => 'refill', 'kind' => 'topup', 'duration_months' => null], 'Top-up');
    $gateway = Mockery::mock(StripeBillingGateway::class);
    $gateway->shouldReceive('price')->once()->andReturn(['id' => 'price_123', 'active' => true, 'currency' => 'usd', 'unit_amount' => 2900,
        'product' => 'prod_123', 'type' => 'one_time', 'recurring' => null]);
    expect($service->verifyAndPublish($actor, $topup, 'Verified', $gateway)->checkoutEligible())->toBeTrue();
});
