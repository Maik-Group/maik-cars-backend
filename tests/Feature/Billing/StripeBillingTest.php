<?php

use App\Models\BillingOrder;
use App\Models\BillingPlanPrice;
use App\Models\BillingSubscription;
use App\Models\CreditGrant;
use App\Models\StripeCustomer;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeCheckoutService;
use App\Services\Subscriptions\StripeEventProcessor;
use App\Services\Subscriptions\StripeFulfillmentService;
use App\Services\Subscriptions\StripeOrderReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function stripeOffer(string $kind = 'topup', int $months = 0): BillingPlanPrice
{
    return BillingPlanPrice::query()->create([
        'offer_key' => 'test-'.$kind, 'revision' => 1,
        'environment' => 'sandbox', 'kind' => $kind, 'name' => 'Test plan',
        'duration_months' => $months ?: null, 'credits' => 15,
        'currency' => 'USD', 'amount_minor' => 1900, 'terms_version' => 'v1',
        'offer_snapshot' => ['credits' => 15, 'durationMonths' => $months],
        'stripe_product_id' => 'prod_test_1', 'stripe_price_id' => 'price_test_1',
        'verified_at' => now('UTC'), 'published_at' => now('UTC'),
    ]);
}

beforeEach(function (): void {
    config()->set('subscriptions.environment', 'sandbox');
    config()->set('stripe_billing.environment', 'sandbox');
    config()->set('stripe_billing.checkout_enabled', false);
    config()->set('stripe_billing.purchase_contexts', []);
    config()->set('stripe_billing.secret_key', null);
});

it('keeps Stripe checkout closed by default and makes catalog reading side-effect free', function (): void {
    stripeOffer();
    $response = $this->getJson('/api/v1/billing/plans')->assertOk();
    $response->assertJsonPath('data.topUps.0.credits', 15)
        ->assertJsonPath('data.topUps.0.checkoutEligible', false)
        ->assertJsonPath('data.externalCheckoutAllowed', false);
    expect(BillingOrder::query()->count())->toBe(0)
        ->and(CreditGrant::query()->count())->toBe(0);
});

it('creates idempotent hosted topup checkout for the owner with immutable server prices', function (): void {
    $offer = stripeOffer();
    $user = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($user);
    config()->set('stripe_billing.checkout_enabled', true);
    config()->set('stripe_billing.purchase_contexts', ['test-web']);
    config()->set('stripe_billing.secret_key', 'sk_test_fake');
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('customer')->once()->andReturn(['id' => 'cus_test_1']);
        $mock->shouldReceive('checkout')->once()
            ->andReturn(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/test', 'expires_at' => now()->addMinutes(30)->timestamp]);
    });
    $data = ['kind' => 'topup', 'priceVersionId' => $offer->id, 'purchaseContextId' => 'test-web'];
    $first = $this->postJson('/api/v1/billing/checkouts', $data, ['Idempotency-Key' => 'stable-purchase-test-1'])
        ->assertCreated()->assertJsonPath('data.status', 'checkout_created');
    $second = $this->postJson('/api/v1/billing/checkouts', $data, ['Idempotency-Key' => 'stable-purchase-test-1'])
        ->assertCreated();
    expect($first->json('data.orderId'))->toBe($second->json('data.orderId'))
        ->and(BillingOrder::count())->toBe(1)
        ->and(CreditGrant::count())->toBe(0);
    $this->postJson('/api/v1/billing/checkouts', $data + ['credits' => 100000], ['Idempotency-Key' => 'stable-purchase-test-2'])
        ->assertUnprocessable();
    $this->getJson('/api/v1/billing/checkouts/'.$first->json('data.orderId'))
        ->assertOk()->assertJsonPath('data.paymentStatus', 'unpaid');
});

it('fulfills a verified paid topup only once and keeps purchased points nonexpiring', function (): void {
    $offer = stripeOffer();
    $user = User::factory()->create(['is_active' => true]);
    StripeCustomer::query()->create(['user_id' => $user->id, 'environment' => 'sandbox', 'stripe_customer_id' => 'cus_test_topup']);
    $order = BillingOrder::query()->create([
        'id' => (string) Str::uuid(), 'user_id' => $user->id,
        'environment' => 'sandbox', 'billing_plan_price_id' => $offer->id,
        'kind' => 'topup', 'purchase_context_id' => 'test-web',
        'idempotency_hash' => hash('sha256', 'samekey'),
        'request_hash' => hash('sha256', 'same-request'),
        'offer_snapshot' => ['credits' => 15, 'amountMinor' => 1900, 'currency' => 'USD'],
        'stripe_checkout_session_id' => 'cs_topup', 'status' => 'checkout_created',
    ]);
    $session = [
        'id' => 'cs_topup', 'client_reference_id' => $order->id,
        'customer' => 'cus_test_topup', 'mode' => 'payment', 'status' => 'complete',
        'payment_status' => 'paid', 'amount_total' => 1900, 'currency' => 'usd',
        'payment_intent' => 'pi_topup', 'metadata' => ['order_id' => $order->id, 'billing_environment' => 'sandbox'],
    ];
    $service = app(StripeFulfillmentService::class);
    $service->fulfillCheckout($session, 'sandbox');
    $service->fulfillCheckout($session, 'sandbox');
    expect(CreditGrant::query()->where('source_type', 'purchased_topup')->count())->toBe(1)
        ->and(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(15)
        ->and(CreditGrant::query()->first()->expires_at)->toBeNull()
        ->and($order->fresh()->fulfillment_status)->toBe('fulfilled');
});

it('grants exactly one configured allowance per paid invoice period, not checkout completion', function (): void {
    $offer = stripeOffer('subscription', 1);
    $user = User::factory()->create(['is_active' => true]);
    StripeCustomer::query()->create(['user_id' => $user->id, 'environment' => 'sandbox', 'stripe_customer_id' => 'cus_invoice']);
    BillingSubscription::query()->create([
        'user_id' => $user->id, 'environment' => 'sandbox',
        'billing_plan_price_id' => $offer->id, 'stripe_subscription_id' => 'sub_invoice_1',
        'stripe_customer_id' => 'cus_invoice', 'status' => 'incomplete',
    ]);
    $start = CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC')->timestamp;
    $end = CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC')->timestamp;
    $invoice = [
        'id' => 'in_test_1', 'customer' => 'cus_invoice', 'subscription' => 'sub_invoice_1',
        'status' => 'paid', 'paid_out_of_band' => false, 'collection_method' => 'charge_automatically',
        'billing_reason' => 'subscription_create', 'amount_paid' => 1900, 'currency' => 'usd',
        'payment_intent' => 'pi_invoice_1',
        'lines' => ['has_more' => false, 'data' => [[
            'quantity' => 1, 'period' => ['start' => $start, 'end' => $end],
            'parent' => ['subscription_item_details' => ['subscription' => 'sub_invoice_1', 'proration' => false]],
            'pricing' => ['price_details' => ['price' => 'price_test_1']],
        ]]],
    ];
    $fulfillment = app(StripeFulfillmentService::class);
    $fulfillment->fulfillInvoice($invoice, 'sandbox');
    $fulfillment->fulfillInvoice($invoice, 'sandbox');
    expect(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(15)
        ->and(CreditGrant::where('source_type', 'paid_cycle')->count())->toBe(1)
        ->and(BillingSubscription::count())->toBe(1);
    $invoice['id'] = 'in_unexpected_duplicate';
    $invoice['payment_intent'] = 'pi_invoice_2';
    $fulfillment->fulfillInvoice($invoice, 'sandbox');
    expect(CreditGrant::where('source_type', 'paid_cycle')->count())->toBe(1);
    $invoice['id'] = 'in_bad_proration';
    $invoice['lines']['data'][0]['parent']['subscription_item_details']['proration'] = true;
    expect(fn () => $fulfillment->fulfillInvoice($invoice, 'sandbox'))->toThrow(RuntimeException::class);
});

it('requires a previously linked Stripe subscription before honoring a paid invoice', function (): void {
    stripeOffer('subscription', 1);
    $user = User::factory()->create(['is_active' => true]);
    StripeCustomer::query()->create(['user_id' => $user->id, 'environment' => 'sandbox', 'stripe_customer_id' => 'cus_unlinked']);
    $now = CarbonImmutable::parse('2026-11-01', 'UTC');
    $invoice = [
        'id' => 'in_unlinked', 'customer' => 'cus_unlinked', 'subscription' => 'sub_unlinked',
        'status' => 'paid', 'paid_out_of_band' => false,
        'collection_method' => 'charge_automatically', 'billing_reason' => 'subscription_create',
        'amount_paid' => 1900, 'currency' => 'usd', 'payment_intent' => 'pi_unlinked',
        'lines' => ['has_more' => false, 'data' => [[
            'quantity' => 1, 'period' => ['start' => $now->timestamp, 'end' => $now->addMonth()->timestamp],
            'parent' => ['subscription_item_details' => ['subscription' => 'sub_unlinked', 'proration' => false]],
            'pricing' => ['price_details' => ['price' => 'price_test_1']],
        ]]],
    ];
    expect(fn () => app(StripeFulfillmentService::class)->fulfillInvoice($invoice, 'sandbox'))
        ->toThrow(RuntimeException::class, 'Unlinked paid subscription');
    expect(CreditGrant::count())->toBe(0);
});

it('rejects unsigned Stripe webhooks before persisting any payment event', function (): void {
    $this->postJson('/api/v1/billing/stripe/webhook', ['id' => 'evt_unsigned'], ['Stripe-Signature' => 'invalid'])
        ->assertStatus(400)->assertJsonPath('code', 'INVALID_STRIPE_SIGNATURE');
    expect(StripeWebhookEvent::count())->toBe(0);
});

it('preserves signed webhook evidence and rejects a different billing environment', function (): void {
    $payload = [
        'id' => 'evt_signed_test', 'type' => 'product.created', 'livemode' => false,
        'data' => ['object' => ['id' => 'prod_test']],
    ];
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock) use ($payload): void {
        $mock->shouldReceive('verifyEvent')->once()->andReturn($payload);
    });
    $this->postJson('/api/v1/billing/stripe/webhook', $payload, ['Stripe-Signature' => 'test-valid'])
        ->assertOk()->assertJsonPath('received', true);
    $saved = StripeWebhookEvent::query()->firstOrFail();
    expect($saved->stripe_event_id)->toBe('evt_signed_test')
        ->and(json_decode($saved->encrypted_payload, true)['id'])->toBe('evt_signed_test');
});

it('holds only refunded source credits then releases or revokes without negative balances', function (): void {
    $offer = stripeOffer();
    $user = User::factory()->create(['is_active' => true]);
    $grant = app(CreditWalletService::class)->grant(
        $user, 'purchased_topup', 15, 'stripe-topup:sandbox:'.($orderId = (string) Str::uuid()), null, 'sandbox',
    );
    $other = app(CreditWalletService::class)->grant($user, 'free_grant', 2, 'admin:other', null, 'sandbox');
    BillingOrder::query()->create([
        'id' => $orderId, 'user_id' => $user->id, 'environment' => 'sandbox',
        'billing_plan_price_id' => $offer->id, 'kind' => 'topup', 'purchase_context_id' => 'test-web',
        'idempotency_hash' => hash('sha256', $orderId),
        'request_hash' => hash('sha256', 'refundtest'), 'offer_snapshot' => ['credits' => 15, 'amountMinor' => 1900, 'currency' => 'USD'],
        'stripe_payment_intent_id' => 'pi_refund_test', 'status' => 'fulfilled', 'fulfillment_status' => 'fulfilled',
    ]);
    $service = app(StripeFulfillmentService::class);
    $refund = ['id' => 're_refund_test', 'payment_intent' => 'pi_refund_test', 'status' => 'pending', 'amount' => 1900, 'currency' => 'usd'];
    $service->refund($refund, 'sandbox');
    expect(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(2)
        ->and($grant->fresh()->held_at)->not->toBeNull();
    $service->refund([...$refund, 'status' => 'failed'], 'sandbox');
    expect(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(17);
    $service->refund([...$refund, 'status' => 'succeeded'], 'sandbox');
    expect(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(2)
        ->and($grant->fresh()->remaining)->toBe(0)
        ->and($other->fresh()->remaining)->toBe(2);
});

it('fails closed for an unsupported partial refund until source-specific review', function (): void {
    $offer = stripeOffer();
    $user = User::factory()->create(['is_active' => true]);
    $orderId = (string) Str::uuid();
    app(CreditWalletService::class)->grant($user, 'purchased_topup', 15, 'stripe-topup:sandbox:'.$orderId, null, 'sandbox');
    BillingOrder::query()->create([
        'id' => $orderId, 'user_id' => $user->id, 'environment' => 'sandbox',
        'billing_plan_price_id' => $offer->id, 'kind' => 'topup', 'purchase_context_id' => 'test-web',
        'idempotency_hash' => hash('sha256', $orderId),
        'request_hash' => hash('sha256', 'partialtest'), 'offer_snapshot' => ['credits' => 15, 'amountMinor' => 1900, 'currency' => 'USD'],
        'stripe_payment_intent_id' => 'pi_partial_refund', 'status' => 'fulfilled',
    ]);
    app(StripeFulfillmentService::class)->refund([
        'id' => 're_partial', 'payment_intent' => 'pi_partial_refund',
        'status' => 'succeeded', 'amount' => 100, 'currency' => 'usd',
    ], 'sandbox');
    expect(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(0)
        ->and(DB::table('billing_refunds')->value('status'))->toBe('needs_review');
});

it('reconciles a lost Checkout response by looking up a matching hosted session without creating another', function (): void {
    $offer = stripeOffer();
    $user = User::factory()->create(['is_active' => true]);
    StripeCustomer::query()->create([
        'user_id' => $user->id, 'environment' => 'sandbox', 'stripe_customer_id' => 'cus_reconcile',
    ]);
    $orderId = (string) Str::uuid();
    $order = BillingOrder::query()->create([
        'id' => $orderId, 'user_id' => $user->id, 'environment' => 'sandbox',
        'billing_plan_price_id' => $offer->id, 'kind' => 'topup',
        'purchase_context_id' => 'test-web',
        'idempotency_hash' => hash('sha256', 'order-unknown'),
        'request_hash' => hash('sha256', 'order-request'),
        'offer_snapshot' => ['credits' => 15, 'amountMinor' => 1900, 'currency' => 'USD'],
        'status' => 'needs_reconciliation',
    ]);
    $session = [
        'id' => 'cs_found_after_timeout', 'client_reference_id' => $orderId,
        'customer' => 'cus_reconcile', 'mode' => 'payment',
        'status' => 'open', 'payment_status' => 'unpaid',
        'amount_total' => 1900, 'currency' => 'usd',
        'url' => 'https://checkout.stripe.com/c/pay/resumed',
        'metadata' => ['order_id' => $orderId, 'billing_environment' => 'sandbox'],
    ];
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock) use ($session): void {
        $mock->shouldReceive('checkoutSessionsForCustomer')->once()
            ->with('cus_reconcile', null)->andReturn(['data' => [$session], 'has_more' => false]);
        $mock->shouldNotReceive('checkout');
        $mock->shouldNotReceive('customer');
    });
    expect(app(StripeOrderReconciliationService::class)->reconcile($order))->toBeTrue()
        ->and($order->fresh()->stripe_checkout_session_id)->toBe('cs_found_after_timeout')
        ->and($order->fresh()->status)->toBe('checkout_created')
        ->and(CreditGrant::count())->toBe(0);
});

it('keeps one source on hold while another dispute or refund remains unresolved', function (): void {
    $offer = stripeOffer();
    $user = User::factory()->create(['is_active' => true]);
    $orderId = (string) Str::uuid();
    $grant = app(CreditWalletService::class)->grant(
        $user, 'purchased_topup', 15, 'stripe-topup:sandbox:'.$orderId, null, 'sandbox',
    );
    BillingOrder::query()->create([
        'id' => $orderId, 'user_id' => $user->id, 'environment' => 'sandbox',
        'billing_plan_price_id' => $offer->id, 'kind' => 'topup', 'purchase_context_id' => 'test-web',
        'idempotency_hash' => hash('sha256', 'multi-risk:'.$orderId),
        'request_hash' => hash('sha256', 'multi-risk'),
        'offer_snapshot' => ['credits' => 15, 'amountMinor' => 1900, 'currency' => 'USD'],
        'stripe_payment_intent_id' => 'pi_multi_risk',
        'status' => 'fulfilled', 'fulfillment_status' => 'fulfilled',
    ]);
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('charge')->times(4)
            ->with('ch_multi_risk')->andReturn(['id' => 'ch_multi_risk', 'payment_intent' => 'pi_multi_risk']);
    });
    $service = app(StripeFulfillmentService::class);
    $refund = [
        'id' => 're_multi_risk', 'payment_intent' => 'pi_multi_risk',
        'status' => 'pending', 'amount' => 1900, 'currency' => 'usd',
    ];
    $service->refund($refund, 'sandbox');
    $service->dispute(['id' => 'dp_first', 'charge' => 'ch_multi_risk', 'status' => 'needs_response'], 'sandbox');
    $service->dispute(['id' => 'dp_second', 'charge' => 'ch_multi_risk', 'status' => 'needs_response'], 'sandbox');
    $service->dispute(['id' => 'dp_first', 'charge' => 'ch_multi_risk', 'status' => 'won'], 'sandbox');
    expect($grant->fresh()->held_at)->not->toBeNull()
        ->and(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(0)
        ->and(DB::table('credit_recovery_cases')
            ->where('reason', 'like', 'dispute:%')->count())->toBe(2);
    $service->refund([...$refund, 'status' => 'failed'], 'sandbox');
    expect($grant->fresh()->held_at)->not->toBeNull();
    $service->dispute(['id' => 'dp_second', 'charge' => 'ch_multi_risk', 'status' => 'won'], 'sandbox');
    expect($grant->fresh()->held_at)->toBeNull()
        ->and(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(15);
});

it('expires an ambiguous order after locating a verified expired Checkout session', function (): void {
    $offer = stripeOffer();
    $user = User::factory()->create(['is_active' => true]);
    StripeCustomer::query()->create([
        'user_id' => $user->id, 'environment' => 'sandbox', 'stripe_customer_id' => 'cus_expired',
    ]);
    $id = (string) Str::uuid();
    $order = BillingOrder::query()->create([
        'id' => $id, 'user_id' => $user->id, 'environment' => 'sandbox',
        'billing_plan_price_id' => $offer->id, 'kind' => 'topup', 'purchase_context_id' => 'test-web',
        'idempotency_hash' => hash('sha256', 'ambiguous-expired:'.$id),
        'request_hash' => hash('sha256', 'expired-request'),
        'offer_snapshot' => ['credits' => 15, 'amountMinor' => 1900, 'currency' => 'USD'],
        'status' => 'needs_reconciliation',
    ]);
    $session = [
        'id' => 'cs_expired_recovered', 'client_reference_id' => $id, 'customer' => 'cus_expired',
        'mode' => 'payment', 'status' => 'expired', 'payment_status' => 'unpaid',
        'amount_total' => 1900, 'currency' => 'usd',
        'metadata' => ['order_id' => $id, 'billing_environment' => 'sandbox'],
    ];
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock) use ($session): void {
        $mock->shouldReceive('checkoutSessionsForCustomer')->once()
            ->andReturn(['data' => [$session], 'has_more' => false]);
        $mock->shouldNotReceive('checkout');
    });
    expect(app(StripeOrderReconciliationService::class)->reconcile($order))->toBeTrue()
        ->and($order->fresh()->status)->toBe('expired')
        ->and($order->fresh()->stripe_checkout_session_id)->toBe('cs_expired_recovered')
        ->and(CreditGrant::count())->toBe(0);
});

it('processes refund.failed as current provider evidence and deduplicates its durable event', function (): void {
    $event = StripeWebhookEvent::query()->create([
        'environment' => 'sandbox', 'stripe_event_id' => 'evt_refund_failed',
        'type' => 'refund.failed', 'received_at' => now('UTC'),
        'status' => 'pending', 'encrypted_payload' => json_encode([
            'id' => 'evt_refund_failed', 'type' => 'refund.failed',
            'data' => ['object' => ['id' => 're_status_failed']],
        ], JSON_THROW_ON_ERROR),
    ]);
    $remote = ['id' => 're_status_failed', 'payment_intent' => 'pi_test', 'status' => 'failed'];
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock) use ($remote): void {
        $mock->shouldReceive('refund')->once()->with('re_status_failed')->andReturn($remote);
    });
    $this->mock(StripeFulfillmentService::class, function (MockInterface $mock) use ($remote): void {
        $mock->shouldReceive('refund')->once()->with($remote, 'sandbox');
    });
    $processor = app(StripeEventProcessor::class);
    $processor->process($event->id);
    $processor->process($event->id);
    expect($event->fresh()->status)->toBe('processed')
        ->and($event->fresh()->attempts)->toBe(1);
});

it('detects credit ledger drift without silently changing source balances', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $wallet = app(CreditWalletService::class);
    $grant = $wallet->grant($user, 'free_grant', 5, 'audit:free', null, 'sandbox');
    $this->artisan('stripe:audit-wallet')->assertExitCode(0);
    $grant->forceFill(['remaining' => 4])->save();
    $this->artisan('stripe:audit-wallet')->assertExitCode(1);
    expect($grant->fresh()->remaining)->toBe(4);
});

it('rejects cross-environment Stripe requests before provider or wallet mutation', function (): void {
    stripeOffer();
    config()->set('stripe_billing.environment', 'production');
    expect(fn () => app(StripeCheckoutService::class)->catalog())
        ->toThrow(HttpException::class, 'BILLING_ENVIRONMENT_MISMATCH');
    $this->postJson('/api/v1/billing/stripe/webhook', ['id' => 'evt_wrong'], ['Stripe-Signature' => 'invalid'])
        ->assertStatus(400);
    expect(CreditGrant::count())->toBe(0);
});
