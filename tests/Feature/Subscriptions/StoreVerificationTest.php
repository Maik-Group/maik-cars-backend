<?php

use App\Exceptions\SubscriptionAccessException;
use App\Models\PlanStoreProduct;
use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscriptions\AdminSubscriptionService;
use App\Services\Subscriptions\BillingAccountService;
use App\Services\Subscriptions\EntitlementResolver;
use App\Services\Subscriptions\GooglePlayAuthorization;
use App\Services\Subscriptions\GooglePlayBillingProvider;
use App\Services\Subscriptions\GooglePushIdentity;
use App\Services\Subscriptions\StorePurchaseService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use phpseclib3\Crypt\RSA;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    config(['stripe_billing.environment' => 'production']);
});

function verifiedAppleFixture(User $user, array $overrides = []): array
{
    return [
        'provider' => 'apple', 'environment' => 'production', 'identity' => 'original-store-transaction', 'reference' => 'original-store-transaction',
        'product_id' => 'maik.standard.monthly', 'account_binding' => app(BillingAccountService::class)->identifiers($user)['apple'],
        'state' => 'active', 'auto_renews' => true, 'transaction_id' => 'store-transaction-1',
        'starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->addMonthNoOverflow()->toIso8601String(),
        ...$overrides,
    ];
}

function fakeVerifiedApple(array $claims): void
{
    Process::fake(['*' => Process::result(output: json_encode(['ok' => true, 'data' => $claims], JSON_THROW_ON_ERROR))]);
}

function subscriptionStoreMapping(string $platform = 'ios'): PlanStoreProduct
{
    $plan = SubscriptionPlan::factory()->create(['key' => 'standard-monthly', 'name' => 'Standard monthly', 'tier_key' => 'standard', 'is_active' => true, 'is_visible' => true]);
    $mapping = PlanStoreProduct::query()->create([
        'subscription_plan_id' => $plan->id, 'platform' => $platform, 'environment' => 'production',
        'product_id' => 'maik.standard.monthly', 'base_plan_id' => $platform === 'ios' ? '' : 'monthly', 'plan_revision' => $plan->revision,
    ]);
    $mapping->forceFill(['validation_state' => 'verified'])->save();

    return $mapping;
}

test('unverified purchases never grant access', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Process::fake(['*' => Process::result(output: '{"ok":false,"retryable":false}', exitCode: 1)]);
    $this->postJson('/api/v1/subscriptions/purchases/apple', ['reference' => 'forged'])->assertStatus(422)->assertJsonPath('code', 'INVALID_PURCHASE');
    $this->assertDatabaseCount('billing_agreements', 0);
    $this->assertDatabaseCount('subscription_entitlements', 0);
});

test('each restore requests fresh owned verification rather than reusing a previously processed result', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $body = ['purchases' => [['provider' => 'apple', 'reference' => 'same-store-reference']]];
    $first = $this->postJson('/api/v1/subscriptions/restore', $body)->assertStatus(202)->json('data.eventIds.0');
    SubscriptionEvent::query()->where('correlation_id', $first)->update(['state' => 'processed']);
    $second = $this->postJson('/api/v1/subscriptions/restore', $body)->assertStatus(202)->json('data.eventIds.0');
    expect($second)->not->toBe($first);
    $this->assertDatabaseHas('subscription_events', ['correlation_id' => $second, 'user_id' => $user->id, 'state' => 'pending']);
    $this->assertDatabaseCount('purchase_transactions', 0);
});

test('verified ownership and transaction identities are idempotent and sensitive references are encrypted', function (): void {
    $user = User::factory()->create();
    subscriptionStoreMapping();
    fakeVerifiedApple(verifiedAppleFixture($user));
    Sanctum::actingAs($user);
    $route = '/api/v1/subscriptions/purchases/apple';
    $this->postJson($route, ['reference' => 'store-transaction-1'])->assertOk()->assertJsonPath('data.verified', true);
    $this->postJson($route, ['reference' => 'store-transaction-1'])->assertOk();
    $this->assertDatabaseCount('billing_agreements', 1);
    $this->assertDatabaseCount('purchase_transactions', 1);
    $this->assertDatabaseCount('subscription_entitlements', 1);
    expect(DB::table('billing_agreements')->value('purchase_reference'))->not->toBe('original-store-transaction');
    $this->getJson('/api/v1/subscriptions/status')->assertOk()->assertJsonPath('data.active', true)->assertJsonMissingPath('data.purchaseReference');
});

test('account and environment mismatches are rejected before access is projected', function (array $overrides, string $code): void {
    $user = User::factory()->create();
    subscriptionStoreMapping();
    fakeVerifiedApple(verifiedAppleFixture($user, $overrides));
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/subscriptions/purchases/apple', ['reference' => 'store-transaction-1'])->assertJsonPath('code', $code)->assertJsonMissingPath('data');
    $this->assertDatabaseCount('subscription_entitlements', 0);
})->with([
    'another account' => [['account_binding' => 'other-account-binding'], 'PURCHASE_ACCOUNT_CONFLICT'],
    'sandbox on production' => [['environment' => 'sandbox'], 'PURCHASE_ENVIRONMENT_MISMATCH'],
]);

test('renewals preserve benefits and local suppression without suppressing independent grants', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $actor = User::factory()->create();
    $actor->assignRole('admin');
    $mapping = subscriptionStoreMapping();
    fakeVerifiedApple(verifiedAppleFixture($user));
    $service = app(StorePurchaseService::class);
    $agreement = $service->verify($user, 'apple', 'store-transaction-1');
    $mapping->plan->update(['daily_allowance' => 100]);
    app(AdminSubscriptionService::class)->revoke($actor, $agreement->entitlement, 'Support suspension');
    fakeVerifiedApple(verifiedAppleFixture($user, ['transaction_id' => 'renewal-2', 'auto_renews' => false]));
    $service->verify($user, 'apple', 'renewal-2');
    expect($agreement->fresh()->daily_allowance)->toBe(5)->and($agreement->fresh()->suppressed_at)->not->toBeNull();
    expect(app(EntitlementResolver::class)->resolve($user)['active'])->toBeFalse();
    SubscriptionEntitlement::query()->create(['user_id' => $user->id, 'source' => 'grant', 'environment' => 'production', 'features' => ['valuation' => true], 'daily_allowance' => 2, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'reason' => 'Independent access']);
    expect(app(EntitlementResolver::class)->resolve($user)['limit'])->toBe(2);
    $this->assertDatabaseCount('purchase_transactions', 2);
});

test('Google derives product binding and order amounts from provider responses and rejects pending access', function (): void {
    $user = User::factory()->create();
    $mapping = subscriptionStoreMapping('android');
    $binding = app(BillingAccountService::class)->identifiers($user)['google'];
    $authorization = Mockery::mock(GooglePlayAuthorization::class);
    $authorization->shouldReceive('token')->andReturn('test-oauth-token');
    app()->instance(GooglePlayAuthorization::class, $authorization);
    $purchase = ['subscriptionState' => 'SUBSCRIPTION_STATE_PENDING', 'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => $binding], 'lineItems' => [['productId' => $mapping->product_id, 'offerDetails' => ['basePlanId' => 'monthly'], 'autoRenewingPlan' => ['autoRenewEnabled' => true]]]];
    $pendingPurchase = $purchase;
    $purchase['subscriptionState'] = 'SUBSCRIPTION_STATE_ACTIVE';
    $purchase['startTime'] = now()->subDay()->toIso8601String();
    $purchase['lineItems'][0] += ['expiryTime' => now()->addMonthNoOverflow()->toIso8601String(), 'latestSuccessfulOrderId' => 'GPA-order-1'];
    Http::fake([
        '*subscriptionsv2/tokens/*' => Http::sequence()->push($pendingPurchase)->push($purchase),
        '*orders/GPA-order-1' => Http::response(['orderId' => 'GPA-order-1', 'purchaseToken' => 'test-token', 'state' => 'PROCESSED', 'total' => ['units' => '10', 'nanos' => 500000000, 'currencyCode' => 'EUR'], 'lineItems' => [['productId' => $mapping->product_id, 'subscriptionDetails' => ['basePlanId' => 'monthly', 'servicePeriodStartTime' => $purchase['startTime'], 'servicePeriodEndTime' => $purchase['lineItems'][0]['expiryTime']]]]]),
    ]);
    $agreement = app(StorePurchaseService::class)->verify($user, 'google', 'test-token');
    expect($agreement->provider_state)->toBe('pending')->and(app(EntitlementResolver::class)->resolve($user)['active'])->toBeFalse();
    $this->assertDatabaseCount('purchase_transactions', 0);
    app(StorePurchaseService::class)->verify($user, 'google', 'test-token');
    expect(app(EntitlementResolver::class)->resolve($user)['active'])->toBeTrue();
    $this->assertDatabaseHas('purchase_transactions', ['currency' => 'EUR', 'amount' => 10.5]);
});

test('provider ingress preserves casing, durably deduplicates authenticated notifications, and rejects unsigned push', function (): void {
    config(['subscriptions.google.push_audience' => 'https://staging.example.com/billing/google', 'subscriptions.google.push_service_account' => 'billing@example.iam.gserviceaccount.com']);
    $this->postJson('/billing/google', ['message' => ['messageId' => '1', 'data' => 'e30=']])->assertUnauthorized();
    Process::fake(['*' => Process::result(output: json_encode(['ok' => true, 'data' => ['id' => 'apple-notification-id', 'environment' => 'production', 'reference' => null, 'account_binding' => null]]))]);
    $this->postJson('/billing/apple', ['signedPayload' => 'signed-and-verified-by-boundary'])->assertNoContent();
    $this->postJson('/billing/apple', ['signedPayload' => 'signed-and-verified-by-boundary'])->assertNoContent();
    $this->assertDatabaseCount('subscription_events', 1);
    $this->assertDatabaseHas('subscription_events', ['duplicate_deliveries' => 1]);
    expect(DB::table('subscription_events')->value('payload'))->not->toContain('signed-and-verified-by-boundary');
    $this->assertDatabaseCount('subscription_entitlements', 0);
});

test('Google push verifies the real signature audience issuer and verified service-account identity', function (array $overrides, bool $wrongKey, bool $accepted): void {
    $key = RSA::createKey(2048);
    $private = $key->toString('PKCS8');
    $jwk = json_decode($key->getPublicKey()->toString('JWK'), true)['keys'][0];
    Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [[
        'kid' => 'qa-key', 'alg' => 'RS256', 'kty' => 'RSA', 'use' => 'sig', 'n' => $jwk['n'], 'e' => $jwk['e'],
    ]]])]);
    config(['subscriptions.google.push_audience' => 'https://staging.invalid/billing/google', 'subscriptions.google.push_service_account' => 'billing@example.iam.gserviceaccount.com']);
    if ($wrongKey) {
        $private = RSA::createKey(2048)->toString('PKCS8');
    }
    $jwt = JWT::encode(['iss' => 'https://accounts.google.com', 'aud' => 'https://staging.invalid/billing/google', 'email' => 'billing@example.iam.gserviceaccount.com', 'email_verified' => true, 'iat' => time() - 5, 'exp' => time() + 300, ...$overrides], $private, 'RS256', 'qa-key');
    if ($accepted) {
        app(GooglePushIdentity::class)->verify($jwt);
        $this->withToken($jwt)->postJson('/billing/google', ['message' => ['messageId' => 'qa-message', 'data' => base64_encode(json_encode(['packageName' => 'maik.cat.android', 'testNotification' => ['version' => '1.0']]))]])->assertNoContent();
        $this->assertDatabaseCount('subscription_events', 1);
    } else {
        expect(fn () => app(GooglePushIdentity::class)->verify($jwt))->toThrow(SubscriptionAccessException::class, 'INVALID_PUSH_IDENTITY');
        $this->assertDatabaseCount('subscription_events', 0);
    }
})->with([
    'valid' => [[], false, true], 'forged signature' => [[], true, false],
    'wrong audience' => [['aud' => 'https://attacker.invalid'], false, false],
    'wrong issuer' => [['iss' => 'https://attacker.invalid'], false, false],
    'wrong email' => [['email' => 'other@example.iam.gserviceaccount.com'], false, false],
    'unverified email' => [['email_verified' => false], false, false],
]);

test('deferred Google replacement retains the paid-through item and its verified order', function (): void {
    $authorization = Mockery::mock(GooglePlayAuthorization::class);
    $authorization->shouldReceive('token')->andReturn('qa-oauth');
    app()->instance(GooglePlayAuthorization::class, $authorization);
    $end = now()->addDay()->toIso8601String();
    Http::fake([
        '*subscriptionsv2/tokens/new-token' => Http::response(['subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE', 'startTime' => now()->subDay()->toIso8601String(), 'linkedPurchaseToken' => 'old-token', 'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => 'bound-account'], 'lineItems' => [
            ['productId' => 'old-product', 'offerDetails' => ['basePlanId' => 'monthly'], 'autoRenewingPlan' => ['autoRenewEnabled' => false], 'latestSuccessfulOrderId' => 'GPA-old', 'expiryTime' => $end, 'deferredItemReplacement' => ['productId' => 'new-product']],
            ['productId' => 'new-product', 'offerDetails' => ['basePlanId' => 'annual'], 'autoRenewingPlan' => ['autoRenewEnabled' => true]],
        ]]),
        '*orders/GPA-old' => Http::response(['orderId' => 'GPA-old', 'purchaseToken' => 'original-paid-ancestor-token', 'state' => 'PROCESSED', 'lineItems' => [['productId' => 'old-product', 'subscriptionDetails' => ['basePlanId' => 'monthly', 'servicePeriodStartTime' => now()->subMonth()->toIso8601String(), 'servicePeriodEndTime' => $end]]]]),
    ]);
    $purchase = app(GooglePlayBillingProvider::class)->verify('new-token', 'production');
    expect($purchase->productId)->toBe('old-product')->and($purchase->basePlanId)->toBe('monthly')->and($purchase->transactionId)->toBe('GPA-old')->and($purchase->endsAt->toIso8601String())->toBe($end);
});

test('late Google ancestor notifications reverify the current token without reverting the agreement', function (): void {
    $user = User::factory()->create();
    $mapping = subscriptionStoreMapping('android');
    $binding = app(BillingAccountService::class)->identifiers($user)['google'];
    $authorization = Mockery::mock(GooglePlayAuthorization::class);
    $authorization->shouldReceive('token')->andReturn('qa-oauth');
    app()->instance(GooglePlayAuthorization::class, $authorization);
    $start = now()->subDay()->toIso8601String();
    $end = now()->addMonthNoOverflow()->toIso8601String();
    $old = ['subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE', 'startTime' => $start, 'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => $binding], 'lineItems' => [['productId' => $mapping->product_id, 'offerDetails' => ['basePlanId' => 'monthly'], 'autoRenewingPlan' => ['autoRenewEnabled' => true], 'expiryTime' => $end, 'latestSuccessfulOrderId' => 'GPA-old']]];
    $current = [...$old, 'linkedPurchaseToken' => 'old-token'];
    $current['lineItems'][0]['latestSuccessfulOrderId'] = 'GPA-current';
    Http::fake([
        '*subscriptionsv2/tokens/old-token' => Http::sequence()->push($old)->push([], 404),
        '*subscriptionsv2/tokens/current-token' => Http::response($current),
        '*orders/*' => fn ($request) => Http::response(['orderId' => basename($request->url()), 'state' => 'PROCESSED', 'lineItems' => [['productId' => $mapping->product_id, 'subscriptionDetails' => ['basePlanId' => 'monthly', 'servicePeriodStartTime' => $start, 'servicePeriodEndTime' => $end]]]]),
    ]);
    $service = app(StorePurchaseService::class);
    $agreement = $service->verify($user, 'google', 'old-token');
    $service->verify($user, 'google', 'current-token');
    $service->verify($user, 'google', 'old-token');
    expect($agreement->fresh()->purchase_reference)->toBe('current-token')->and($agreement->fresh()->provider_state)->toBe('active');
    $this->assertDatabaseCount('billing_agreements', 1);
    $this->assertDatabaseCount('purchase_transactions', 2);
    $this->assertDatabaseCount('subscription_entitlements', 1);
});
