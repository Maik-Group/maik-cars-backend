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
    config()->set('stripe_billing.environment', 'sandbox');
    config()->set('stripe_billing.secret_key', 'sk_test_fake');
    config()->set('stripe_billing.return_url', null);
    config()->set('stripe_billing.webhook_secret', 'whsec_test_fake');
});

it('keeps Stripe checkout closed by default and makes catalog reading side-effect free', function (): void {
    stripeOffer();
    $response = $this->getJson('/api/v1/billing/plans')->assertOk();
    $response->assertJsonPath('data.topUps.0.credits', 15)
        ->assertJsonPath('data.topUps.0.checkoutEligible', false)
        ->assertJsonPath('data.topUps.0.checkoutUnavailableReason', 'CHECKOUT_NOT_CONFIGURED')
        ->assertJsonPath('data.checkoutUnavailableReason', 'CHECKOUT_NOT_CONFIGURED')
        ->assertJsonPath('data.externalCheckoutAllowed', false);
    expect(BillingOrder::query()->count())->toBe(0)
        ->and(CreditGrant::query()->count())->toBe(0);
});

it('disables checkout when a signed webhook secret is missing', function (): void {
    stripeOffer();
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    config()->set('stripe_billing.webhook_secret', null);
    $this->getJson('/api/v1/billing/plans')->assertOk()
        ->assertJsonPath('data.topUps.0.checkoutEligible', false)
        ->assertJsonPath('data.checkoutUnavailableReason', 'STRIPE_WEBHOOK_NOT_CONFIGURED');
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->postJson('/api/v1/billing/checkouts', [
        'kind' => 'topup', 'priceVersionId' => BillingPlanPrice::query()->sole()->id,
        'purchaseContextId' => 'web',
    ], ['Idempotency-Key' => 'webhook-not-configured'])->assertForbidden();
    expect(BillingOrder::count())->toBe(0);
});

it('creates idempotent hosted topup checkout for the owner with immutable server prices', function (): void {
    $offer = stripeOffer();
    $user = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($user);
    config()->set('stripe_billing.secret_key', 'sk_test_fake');
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('customer')->once()->andReturn(['id' => 'cus_test_1']);
        $mock->shouldReceive('checkout')->once()
            ->andReturn(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/test', 'expires_at' => now()->addMinutes(30)->timestamp]);
    });
    $data = ['kind' => 'topup', 'priceVersionId' => $offer->id, 'purchaseContextId' => 'web'];
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

it('rejects native app checkout contexts without making Stripe requests', function (): void {
    $offer = stripeOffer();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('customer');
        $mock->shouldNotReceive('checkout');
    });
    foreach (['android-app', 'ios-app'] as $context) {
        $this->postJson('/api/v1/billing/checkouts', [
            'kind' => 'topup', 'priceVersionId' => $offer->id,
            'purchaseContextId' => $context,
        ], ['Idempotency-Key' => 'native-rejected-test-123'])->assertForbidden();
    }
    expect(BillingOrder::count())->toBe(0);
});

it('opens Stripe directly only for the explicit sandbox QA mobile context', function (): void {
    $offer = stripeOffer();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('customer')->once()->andReturn(['id' => 'cus_qa_direct']);
        $mock->shouldReceive('checkout')->once()->andReturn([
            'id' => 'cs_qa_direct', 'url' => 'https://checkout.stripe.com/c/pay/direct-test',
            'expires_at' => now()->addMinutes(30)->timestamp,
        ]);
    });
    $request = ['kind' => 'topup', 'priceVersionId' => $offer->id, 'purchaseContextId' => 'qa-mobile'];
    $this->postJson('/api/v1/billing/checkouts', $request, ['Idempotency-Key' => 'qa-direct-test-123'])
        ->assertCreated()->assertJsonPath('data.checkoutUrl', 'https://checkout.stripe.com/c/pay/direct-test');
    config()->set('stripe_billing.secret_key', 'sk_live_fake');
    config()->set('stripe_billing.environment', 'production');
    $this->postJson('/api/v1/billing/checkouts', $request, ['Idempotency-Key' => 'qa-direct-production-123'])
        ->assertForbidden();
    expect(BillingOrder::count())->toBe(1);
});

it('creates prepaid term orders that do not renew and fulfills exactly once on verified payment', function (): void {
    $offer = stripeOffer('subscription', 3);
    $user = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($user);
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('customer')->once()->andReturn(['id' => 'cus_term']);
        $mock->shouldReceive('checkout')->once()->withArgs(function ($order) {
            expect($order->offer_snapshot['billingMode'])->toBe('prepaid_term');
            return true;
        })->andReturn(['id' => 'cs_term', 'url' => 'https://checkout.stripe.com/c/pay/prepaid',
            'expires_at' => now()->addMinutes(30)->timestamp]);
    });
    $result = $this->postJson('/api/v1/billing/checkouts', [
        'kind' => 'subscription', 'priceVersionId' => $offer->id, 'purchaseContextId' => 'qa-mobile',
    ], ['Idempotency-Key' => 'prepaid-term-test-123'])->assertCreated();
    $order = BillingOrder::findOrFail($result->json('data.orderId'));
    $session = ['id' => 'cs_term', 'client_reference_id' => $order->getKey(),
        'customer' => 'cus_term', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
        'amount_total' => 1900, 'currency' => 'usd', 'payment_intent' => 'pi_term',
        'metadata' => ['order_id' => $order->getKey(), 'billing_environment' => 'sandbox']];
    $fulfill = app(StripeFulfillmentService::class);
    $fulfill->fulfillCheckout($session, 'sandbox');
    $fulfill->fulfillCheckout($session, 'sandbox');
    expect(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(15)
        ->and(CreditGrant::where('source_key', 'stripe-term:sandbox:'.$order->id)->count())->toBe(1)
        ->and(BillingSubscription::count())->toBe(0)
        ->and($order->fresh()->period_end)->not->toBeNull();
    $this->getJson('/api/v1/billing/subscription')->assertOk()
        ->assertJsonPath('data.paidSubscription.billingMode', 'prepaid_term')
        ->assertJsonPath('data.paidSubscription.cancelAtPeriodEnd', true);
    $this->postJson('/api/v1/billing/checkouts', [
        'kind' => 'subscription', 'priceVersionId' => $offer->id, 'purchaseContextId' => 'qa-mobile',
    ], ['Idempotency-Key' => 'another-prepaid-term-123'])->assertStatus(409);
});

it('resumes an existing prepaid Stripe checkout instead of rejecting the Buy Plan button', function (): void {
    $offer = stripeOffer('subscription', 1);
    $user = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($user);
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('customer')->once()->andReturn(['id' => 'cus_resume']);
        $mock->shouldReceive('checkout')->once()->andReturn([
            'id' => 'cs_resume', 'url' => 'https://checkout.stripe.com/c/pay/resume',
            'expires_at' => now()->addMinutes(30)->timestamp,
        ]);
    });
    $payload = ['kind' => 'subscription', 'priceVersionId' => $offer->id, 'purchaseContextId' => 'qa-mobile'];
    $first = $this->postJson('/api/v1/billing/checkouts', $payload, [
        'Idempotency-Key' => 'qa-resume-first-attempt',
    ])->assertCreated();
    $second = $this->postJson('/api/v1/billing/checkouts', $payload, [
        'Idempotency-Key' => 'qa-resume-second-attempt',
    ])->assertCreated()
        ->assertJsonPath('data.checkoutUrl', 'https://checkout.stripe.com/c/pay/resume');
    expect($second->json('data.orderId'))->toBe($first->json('data.orderId'))
        ->and(BillingOrder::query()->count())->toBe(1);
});

it('does not resume an older renewable checkout as a new prepaid purchase', function (): void {
    $offer = stripeOffer('subscription', 1);
    $user = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($user);
    config()->set('stripe_billing.return_url', 'https://example.test/billing/return');
    BillingOrder::query()->create([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'environment' => 'sandbox',
        'billing_plan_price_id' => $offer->id, 'kind' => 'subscription',
        'purchase_context_id' => 'web',
        'idempotency_hash' => hash('sha256', 'older-web-session'),
        'request_hash' => hash('sha256', 'older-web-session'),
        'offer_snapshot' => ['credits' => 15, 'durationMonths' => 1, 'amountMinor' => 1900, 'currency' => 'USD'],
        'stripe_checkout_session_id' => 'cs_legacy', 'status' => 'checkout_created',
        'checkout_url' => 'https://checkout.stripe.com/c/pay/legacy',
        'expires_at' => now()->addMinutes(20),
    ]);
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('customer');
        $mock->shouldNotReceive('checkout');
    });
    $this->postJson('/api/v1/billing/checkouts', [
        'kind' => 'subscription', 'priceVersionId' => $offer->id, 'purchaseContextId' => 'qa-mobile',
    ], ['Idempotency-Key' => 'new-prepaid-after-legacy'])->assertStatus(409);
    expect(BillingOrder::query()->count())->toBe(1);
});

it('refunded prepaid terms revoke their credits without creating negative wallet balances', function (): void {
    $offer = stripeOffer('subscription', 1);
    $user = User::factory()->create();
    StripeCustomer::query()->create(['environment' => 'sandbox', 'user_id' => $user->id, 'stripe_customer_id' => 'cus_refundterm']);
    $order = BillingOrder::query()->create([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'environment' => 'sandbox',
        'billing_plan_price_id' => $offer->id, 'kind' => 'subscription',
        'purchase_context_id' => 'web', 'idempotency_hash' => hash('sha256', 'term-refund'),
        'request_hash' => hash('sha256', 'term-refund-request'),
        'offer_snapshot' => ['billingMode' => 'prepaid_term', 'durationMonths' => 1,
            'credits' => 15, 'amountMinor' => 1900, 'currency' => 'USD'],
        'stripe_checkout_session_id' => 'cs_refundterm', 'status' => 'checkout_created',
    ]);
    $session = ['id' => 'cs_refundterm', 'client_reference_id' => $order->id,
        'customer' => 'cus_refundterm', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
        'amount_total' => 1900, 'currency' => 'usd', 'payment_intent' => 'pi_refundterm',
        'metadata' => ['order_id' => $order->id, 'billing_environment' => 'sandbox']];
    app(StripeFulfillmentService::class)->fulfillCheckout($session, 'sandbox');
    app(StripeFulfillmentService::class)->refund([
        'id' => 're_refundterm', 'payment_intent' => 'pi_refundterm', 'amount' => 1900,
        'currency' => 'usd', 'status' => 'succeeded',
    ], 'sandbox');
    expect(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(0);
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/billing/subscription')->assertOk()
        ->assertJsonPath('data.paidSubscription', null);
});

it('recovers paid subscription invoices and updates credits from verified Stripe evidence', function (): void {
    $offer = stripeOffer('subscription', 1);
    $user = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($user);
    StripeCustomer::query()->create([
        'user_id' => $user->getKey(), 'environment' => 'sandbox', 'stripe_customer_id' => 'cus_recovery',
    ]);
    $order = BillingOrder::query()->create([
        'id' => (string) Str::uuid(), 'user_id' => $user->getKey(), 'environment' => 'sandbox',
        'billing_plan_price_id' => $offer->getKey(), 'kind' => 'subscription', 'purchase_context_id' => 'web',
        'idempotency_hash' => hash('sha256', 'recover-key'), 'request_hash' => hash('sha256', 'recover-request'),
        'offer_snapshot' => ['credits' => 15, 'amountMinor' => 1900, 'currency' => 'USD'],
        'stripe_checkout_session_id' => 'cs_recover', 'status' => 'checkout_created',
    ]);
    $start = CarbonImmutable::now('UTC')->startOfDay();
    $session = [
        'id' => 'cs_recover', 'client_reference_id' => $order->getKey(),
        'customer' => 'cus_recovery', 'mode' => 'subscription', 'status' => 'complete',
        'payment_status' => 'paid', 'amount_total' => 1900, 'currency' => 'usd',
        'subscription' => 'sub_recovery',
        'metadata' => ['order_id' => $order->getKey(), 'billing_environment' => 'sandbox'],
    ];
    $invoice = [
        'id' => 'in_recovery', 'customer' => 'cus_recovery', 'subscription' => 'sub_recovery',
        'status' => 'paid', 'paid_out_of_band' => false, 'collection_method' => 'charge_automatically',
        'billing_reason' => 'subscription_create', 'amount_paid' => 1900, 'currency' => 'usd',
        'payment_intent' => 'pi_recovery',
        'lines' => ['has_more' => false, 'data' => [[
            'quantity' => 1, 'period' => ['start' => $start->timestamp, 'end' => $start->addMonth()->timestamp],
            'parent' => ['subscription_item_details' => ['subscription' => 'sub_recovery', 'proration' => false]],
            'pricing' => ['price_details' => ['price' => 'price_test_1']],
        ]]],
    ];
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock) use ($session, $invoice): void {
        $mock->shouldReceive('checkoutSession')->once()->with('cs_recover')->andReturn($session);
        $mock->shouldReceive('subscription')->once()->with('sub_recovery')
            ->andReturn(['id' => 'sub_recovery', 'customer' => 'cus_recovery', 'latest_invoice' => 'in_recovery']);
        $mock->shouldReceive('invoice')->once()->with('in_recovery')->andReturn($invoice);
    });
    $this->getJson('/api/v1/billing/checkouts/'.$order->getKey())->assertOk()
        ->assertJsonPath('data.fulfillmentStatus', 'fulfilled')
        ->assertJsonPath('data.creditsGranted', 15);
    expect(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(15)
        ->and($order->fresh()->status)->toBe('fulfilled');
});

it('rejects signed webhooks whose livemode differs from the Stripe secret key', function (): void {
    $this->mock(StripeBillingGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('verifyEvent')->twice()->andReturn(
            ['id' => 'evt_wrong_live', 'type' => 'product.created', 'livemode' => true],
            ['id' => 'evt_wrong_test', 'type' => 'product.created', 'livemode' => false],
        );
    });
    $this->postJson('/api/v1/billing/stripe/webhook', ['id' => 'evt_wrong_live'], ['Stripe-Signature' => 'valid'])
        ->assertStatus(400)->assertJsonPath('code', 'WEBHOOK_ENVIRONMENT_MISMATCH');
    config()->set('stripe_billing.secret_key', 'sk_live_fake');
    $this->postJson('/api/v1/billing/stripe/webhook', ['id' => 'evt_wrong_test'], ['Stripe-Signature' => 'valid'])
        ->assertStatus(400)->assertJsonPath('code', 'WEBHOOK_ENVIRONMENT_MISMATCH');
    expect(StripeWebhookEvent::count())->toBe(0);
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
    config()->set('stripe_billing.secret_key', 'sk_live_fake');
    expect(app(StripeCheckoutService::class)->catalog()['topUps'])->toBe([]);
    $this->postJson('/api/v1/billing/stripe/webhook', ['id' => 'evt_wrong'], ['Stripe-Signature' => 'invalid'])
        ->assertStatus(400);
    expect(CreditGrant::count())->toBe(0);
});
