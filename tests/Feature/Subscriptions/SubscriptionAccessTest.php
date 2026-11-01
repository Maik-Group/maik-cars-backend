<?php

use App\Exceptions\SubscriptionAccessException;
use App\Models\DailyUsage;
use App\Models\Item;
use App\Models\SubscriptionAuditLog;
use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscriptions\AdminSubscriptionService;
use App\Services\Subscriptions\BillingAccountService;
use App\Services\Subscriptions\DailyUsageService;
use App\Services\Subscriptions\EntitlementResolver;
use App\Services\Subscriptions\GrantTerms;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    config(['subscriptions.environment' => 'production', 'subscriptions.enforcement_enabled' => true]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('cutover activation is explicit idempotent and keeps data protected after purchase shutdown', function (): void {
    config(['subscriptions.enforcement_enabled' => false, 'subscriptions.cutover_at' => '2026-10-21 11:00:00']);
    $this->artisan('subscriptions:activate-enforcement', ['--reason' => 'Approved cutover'])->assertFailed();
    $this->assertDatabaseMissing('settings', ['key' => 'subscriptions.enforcement_activated_at']);
    config(['subscriptions.enforcement_enabled' => true]);
    $this->artisan('subscriptions:activate-enforcement', ['--reason' => 'Approved cutover'])->assertSuccessful();
    $this->artisan('subscriptions:activate-enforcement', ['--reason' => 'Retry cutover'])->assertSuccessful();
    config(['subscriptions.enforcement_enabled' => false, 'subscriptions.purchases_enabled' => false]);
    expect(app(EntitlementResolver::class)->enforcementEnabled())->toBeTrue();
    $this->assertDatabaseCount('subscription_audit_logs', 1);
});

test('automatic revalidation can reuse a claim but cannot spend a new-day or new-family allowance', function (): void {
    $user = User::factory()->create();
    subscriptionTestGrant($user, 2);
    $item = Item::factory()->create();
    $service = app(DailyUsageService::class);
    $service->claim($user, $item);
    expect($service->claim($user, $item, '2026-10-21')['usage']['counted'])->toBeFalse();
    expect(fn () => $service->claim($user, Item::factory()->create(), '2026-10-21'))->toThrow(SubscriptionAccessException::class, 'REVEAL_REQUIRED');
    CarbonImmutable::setTestNow('2026-10-22 00:00:00');
    expect(fn () => $service->claim($user, $item, '2026-10-21'))->toThrow(SubscriptionAccessException::class, 'REVEAL_REQUIRED');
    $this->assertDatabaseCount('daily_usage', 1);
    $this->assertDatabaseCount('daily_usage_reveals', 1);
});

function subscriptionTestGrant(User $user, ?int $allowance = 2, array $overrides = []): SubscriptionEntitlement
{
    return SubscriptionEntitlement::query()->create([
        'user_id' => $user->getKey(), 'source' => 'grant', 'environment' => 'production',
        'daily_allowance' => $allowance, 'features' => ['valuation' => true],
        'starts_at' => CarbonImmutable::now()->subDay(), 'ends_at' => CarbonImmutable::now()->addDay(),
        'reason' => 'Approved test grant', ...$overrides,
    ]);
}

test('effective allowances use the highest source and unlimited rather than summing', function (): void {
    $user = User::factory()->create();
    subscriptionTestGrant($user, 2);
    subscriptionTestGrant($user, 5);
    $resolver = app(EntitlementResolver::class);
    expect($resolver->resolve($user)['limit'])->toBe(5);
    subscriptionTestGrant($user, null);
    expect($resolver->resolve($user)['limit'])->toBeNull();
    subscriptionTestGrant($user, 100, ['revoked_at' => now()]);
    expect($resolver->resolve($user)['sources'])->toHaveCount(3);
});

test('future expired revoked wrong-environment and featureless grants do not grant access', function (array $overrides): void {
    $user = User::factory()->create();
    subscriptionTestGrant($user, 3, $overrides);
    expect(app(EntitlementResolver::class)->resolve($user)['active'])->toBeFalse();
})->with([
    'future' => [['starts_at' => '2026-10-22 12:00:00']],
    'exact expiry' => [['ends_at' => '2026-10-21 12:00:00']],
    'revoked' => [['revoked_at' => '2026-10-21 11:00:00']],
    'sandbox isolation' => [['environment' => 'sandbox']],
    'feature missing' => [['features' => []]],
]);

test('family reveals charge once and previously revealed families remain available after exhaustion', function (): void {
    $user = User::factory()->create();
    subscriptionTestGrant($user, 1);
    $first = Item::factory()->create(['serial_code' => 'ab-12']);
    $sibling = Item::factory()->create(['serial_code' => 'AB 12']);
    $other = Item::factory()->create(['serial_code' => 'OTHER']);
    $usage = app(DailyUsageService::class);
    expect($usage->claim($user, $first)['usage'])->toMatchArray(['used' => 1, 'remaining' => 0, 'counted' => true]);
    expect($usage->claim($user, $sibling)['usage']['counted'])->toBeFalse();
    try {
        $usage->claim($user, $other);
        $this->fail('A new family exceeded the daily allowance.');
    } catch (SubscriptionAccessException $exception) {
        expect($exception->errorCode)->toBe('DAILY_QUOTA_EXHAUSTED');
    }
    expect($usage->claim($user, $first)['usage']['counted'])->toBeFalse();
    $this->assertDatabaseCount('daily_usage_reveals', 1);
    expect(DailyUsage::query()->sole()->used)->toBe(1);
});

test('empty serials fall back to item identity and UTC midnight creates a fresh bucket', function (): void {
    $user = User::factory()->create();
    subscriptionTestGrant($user, 2);
    $first = Item::factory()->create(['serial_code' => '']);
    $other = Item::factory()->create(['serial_code' => '']);
    $usage = app(DailyUsageService::class);
    expect($usage->claim($user, $first)['usage']['used'])->toBe(1);
    expect($usage->claim($user, $other)['usage']['used'])->toBe(2);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 00:00:00', 'UTC'));
    expect($usage->claim($user, $first)['usage']['used'])->toBe(1);
    $this->assertDatabaseCount('daily_usage', 2);
});

test('an expired or revoked grant cannot reuse an earlier reveal', function (): void {
    $user = User::factory()->create();
    $grant = subscriptionTestGrant($user);
    $item = Item::factory()->create();
    app(DailyUsageService::class)->claim($user, $item);
    $grant->update(['revoked_at' => now()]);
    expect(fn () => app(DailyUsageService::class)->claim($user, $item))->toThrow(SubscriptionAccessException::class, 'SUBSCRIPTION_REQUIRED');
});

test('inactive accounts never gain access even from unlimited grants', function (): void {
    $user = User::factory()->create(['is_active' => false]);
    subscriptionTestGrant($user, null);
    expect(fn () => app(EntitlementResolver::class)->resolve($user))->toThrow(SubscriptionAccessException::class, 'ACCOUNT_INACTIVE');
});

test('billing identities remain stable and different accounts cannot share them', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $accounts = app(BillingAccountService::class);
    $binding = $accounts->identifiers($first);
    expect($accounts->identifiers($first))->toBe($binding)
        ->and($accounts->identifiers($second)['apple'])->not->toBe($binding['apple'])
        ->and($binding['google'])->toBe(hash('sha256', $binding['apple']));
});

test('administrative grants snapshot benefits and leave no payment evidence', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $actor = User::factory()->create();
    $actor->assignRole('admin');
    $recipient = User::factory()->create();
    $plan = SubscriptionPlan::query()->create(['key' => 'test', 'name' => 'Test', 'tier_key' => 'standard', 'duration_months' => 1, 'daily_allowance' => 5, 'features' => ['valuation' => true]]);
    $start = CarbonImmutable::parse('2028-01-31', 'UTC');
    $grant = app(AdminSubscriptionService::class)->grant($actor, $recipient, $plan, GrantTerms::forPlan($plan, $start, 'Support approval'));
    $plan->update(['daily_allowance' => 10]);
    expect($grant->fresh()->daily_allowance)->toBe(5)
        ->and($grant->ends_at->toDateString())->toBe('2028-02-29');
    $this->assertDatabaseCount('purchase_transactions', 0);
    expect(SubscriptionAuditLog::query()->sole()->actor_id)->toBe($actor->id);
});

test('unauthorized grant invocation is rejected by the service', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $actor = User::factory()->create();
    $recipient = User::factory()->create();
    $plan = SubscriptionPlan::query()->create(['key' => 'test', 'name' => 'Test', 'tier_key' => 'standard', 'duration_months' => 1, 'daily_allowance' => 5, 'features' => ['valuation' => true]]);
    expect(fn () => app(AdminSubscriptionService::class)->grant($actor, $recipient, $plan, new GrantTerms(CarbonImmutable::now(), CarbonImmutable::now()->addMonthNoOverflow(), 'Unauthorized request')))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('subscription_entitlements', 0);
});

test('legacy transition issuance is disabled', function (): void {
    $user = User::factory()->create();
    $service = app(AdminSubscriptionService::class);
    $cutover = CarbonImmutable::parse('2026-11-01', 'UTC');
    expect(fn () => $service->grantTransition($user, $cutover, 'Announced transition'))->toThrow(LogicException::class);
    $this->assertDatabaseCount('subscription_audit_logs', 0);
    $this->assertDatabaseCount('purchase_transactions', 0);
});
