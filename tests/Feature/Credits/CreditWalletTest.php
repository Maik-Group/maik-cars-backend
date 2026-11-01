<?php

use App\Models\CreditConsumption;
use App\Models\CreditGrant;
use App\Models\CreditLedgerEntry;
use App\Models\Item;
use App\Models\User;
use App\Services\Credits\ComplimentaryCreditService;
use App\Services\Credits\CreditWalletService;
use App\Services\Subscriptions\AdminSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['stripe_billing.environment' => 'production']));

it('grants idempotently and spends once per serial family per UTC day', function (): void {
    $user = User::factory()->create();
    $wallet = app(CreditWalletService::class);
    $first = $wallet->grant($user, 'paid_cycle', 2, 'invoice:1', CarbonImmutable::now('UTC')->addMonth());
    expect($wallet->grant($user, 'paid_cycle', 2, 'invoice:1', CarbonImmutable::now('UTC')->addMonth())->id)->toBe($first->id);

    $item = Item::factory()->create(['serial_code' => 'ab-123', 'weight_kg' => 1, 'pt_ppm' => 1]);
    $other = Item::factory()->create(['serial_code' => 'AB 123', 'weight_kg' => 1, 'pt_ppm' => 1]);
    expect($wallet->spend($user, $item)['counted'])->toBeTrue()
        ->and($wallet->spend($user, $other)['counted'])->toBeFalse()
        ->and($wallet->balance($user))->toBe(1)
        ->and(CreditConsumption::query()->count())->toBe(1);
});

it('preserves same-day receipt after its source grant expires', function (): void {
    $user = User::factory()->create();
    $wallet = app(CreditWalletService::class);
    $grant = $wallet->grant($user, 'paid_cycle', 1, 'invoice:2', CarbonImmutable::now('UTC')->addHour());
    $item = Item::factory()->create(['serial_code' => 'receipt-1', 'weight_kg' => 1, 'pt_ppm' => 1]);
    $wallet->spend($user, $item);
    $grant->update(['expires_at' => CarbonImmutable::now('UTC')->subMinute()]);
    expect($wallet->spend($user, $item)['counted'])->toBeFalse()
        ->and($wallet->balance($user))->toBe(0);
});

it('spends paid credits before complimentary and topups', function (): void {
    $user = User::factory()->create();
    $wallet = app(CreditWalletService::class);
    $topup = $wallet->grant($user, 'purchased_topup', 1, 'topup:test', null);
    $free = $wallet->grant($user, 'free_grant', 1, 'free:test', CarbonImmutable::now('UTC')->addWeek());
    $paid = $wallet->grant($user, 'paid_cycle', 1, 'paid:test', CarbonImmutable::now('UTC')->addMonth());
    foreach (['first', 'second', 'third'] as $serial) {
        $wallet->spend($user, Item::factory()->create(['serial_code' => $serial]));
    }
    expect($paid->fresh()->remaining)->toBe(0)
        ->and($free->fresh()->remaining)->toBe(0)
        ->and($topup->fresh()->remaining)->toBe(0);
});

it('anchors complimentary periods to original start, including January 31 leap year', function (): void {
    $user = User::factory()->create();
    $service = app(ComplimentaryCreditService::class);
    $start = CarbonImmutable::parse('2024-01-31 12:00:00', 'UTC');
    $service->enroll($user, 12, 1, $start, CarbonImmutable::parse('2024-04-10 12:00:00', 'UTC'));
    expect($service->allocateDue(CarbonImmutable::parse('2024-02-28 12:00:00', 'UTC')))->toBe(1)
        ->and($service->allocateDue(CarbonImmutable::parse('2024-02-29 12:00:00', 'UTC')))->toBe(1)
        ->and($service->allocateDue(CarbonImmutable::parse('2024-03-30 12:00:00', 'UTC')))->toBe(0)
        ->and($service->allocateDue(CarbonImmutable::parse('2024-03-31 12:00:00', 'UTC')))->toBe(1);
    $expirations = CreditGrant::query()->orderBy('id')->pluck('expires_at')->map(fn ($value) => CarbonImmutable::parse($value)->toDateString())->all();
    expect($expirations)->toBe(['2024-02-29', '2024-03-31', '2024-04-10']);
});

it('allocates enrolled users only, idempotently, and never automatically enrolls later users', function (): void {
    $user = User::factory()->create();
    $later = User::factory()->create();
    $service = app(ComplimentaryCreditService::class);
    $service->enroll($user, 7, 1, CarbonImmutable::parse('2026-11-01', 'UTC'), CarbonImmutable::parse('2026-12-01', 'UTC'));
    expect($service->allocateDue(CarbonImmutable::parse('2026-11-01 15:00:00', 'UTC')))->toBe(1)
        ->and($service->allocateDue(CarbonImmutable::parse('2026-11-01 15:01:00', 'UTC')))->toBe(0)
        ->and(app(CreditWalletService::class)->balance($user))->toBe(7)
        ->and(app(CreditWalletService::class)->balance($later))->toBe(0);
});

it('keeps two entitlements for one user separate and ends a once allocation', function (): void {
    $user = User::factory()->create();
    $service = app(ComplimentaryCreditService::class);
    $start = CarbonImmutable::parse('2024-01-31 12:00:00', 'UTC');
    $end = CarbonImmutable::parse('2024-04-10 12:00:00', 'UTC');
    $once = $service->enroll($user, 3, 1, $start, $end, 'once');
    $recurring = $service->enroll($user, 5, 1, $start, $end, 'per_period');
    expect($service->allocateDue($start))->toBe(2)
        ->and($service->allocateDue(CarbonImmutable::parse('2024-02-29 12:00:00', 'UTC')))->toBe(1)
        ->and(CreditGrant::query()->where('source_key', 'allocation:'.$once->id.':period:1')->exists())->toBeFalse()
        ->and(CreditGrant::query()->where('source_key', 'allocation:'.$recurring->id.':period:1')->exists())->toBeTrue()
        ->and($service->allocateDue($end))->toBe(0)
        ->and(fn () => $recurring->update(['credits' => 100]))->toThrow(LogicException::class);
});

it('rejects new legacy transition issuance', function (): void {
    $user = User::factory()->create();
    expect(fn () => app(AdminSubscriptionService::class)->grantTransition($user, CarbonImmutable::now('UTC'), 'legacy'))
        ->toThrow(LogicException::class, 'Transition grants are disabled.');
});

it('holds and releases only its source bucket without changing ledger credit totals', function (): void {
    $user = User::factory()->create();
    $wallet = app(CreditWalletService::class);
    $disputed = $wallet->grant($user, 'paid_cycle', 4, 'paid:disputed', null);
    $other = $wallet->grant($user, 'paid_cycle', 2, 'paid:other', null);
    $wallet->hold($disputed, 'charge dispute');
    $wallet->hold($disputed, 'duplicate webhook');
    expect($wallet->balance($user))->toBe(2);
    $wallet->spend($user, Item::factory()->create(['serial_code' => 'held-source-test']));
    expect($disputed->fresh()->remaining)->toBe(4)->and($other->fresh()->remaining)->toBe(1);
    $wallet->releaseHold($disputed, 'dispute won');
    $wallet->releaseHold($disputed, 'duplicate webhook');
    expect($wallet->balance($user))->toBe(5)
        ->and(CreditLedgerEntry::query()->where('entry_type', 'hold')->count())->toBe(1)
        ->and(CreditLedgerEntry::query()->where('entry_type', 'release_hold')->count())->toBe(1)
        ->and(CreditLedgerEntry::query()->whereIn('entry_type', ['hold', 'release_hold'])->sum('amount'))->toBe(0);
});

it('rejects reopened receipt after revocation and keeps natural expiry reopen', function (): void {
    $user = User::factory()->create();
    $wallet = app(CreditWalletService::class);
    $grant = $wallet->grant($user, 'paid_cycle', 2, 'paid:revocation', null);
    $item = Item::factory()->create(['serial_code' => 'revoke-family']);
    $wallet->spend($user, $item);
    $wallet->revoke($grant, 'refund');
    expect(fn () => $wallet->spend($user, $item))->toThrow(HttpException::class, 'RECEIPT_REVOKED');
});

it('rejects mismatched expiry for repeated grants and accepts credits above former business cap', function (): void {
    $user = User::factory()->create();
    $wallet = app(CreditWalletService::class);
    $expires = CarbonImmutable::now('UTC')->addDay()->startOfSecond();
    $wallet->grant($user, 'paid_cycle', 100000001, 'large:grant', $expires);
    expect($wallet->balance($user))->toBe(100000001)
        ->and(fn () => $wallet->grant($user, 'paid_cycle', 100000001, 'large:grant', $expires->addDay()))
        ->toThrow(LogicException::class, 'Conflicting credit grant identity');
});

it('never issues a sandbox complimentary allocation into production after environment switch', function (): void {
    config(['stripe_billing.environment' => 'sandbox']);
    $user = User::factory()->create();
    $service = app(ComplimentaryCreditService::class);
    $start = CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC');
    $allocation = $service->enroll($user, 4, 1, $start, $start->addMonthsNoOverflow(3));
    expect($allocation->environment)->toBe('sandbox')
        ->and($service->allocateDue($start))->toBe(1)
        ->and(app(CreditWalletService::class)->balance($user, 'sandbox'))->toBe(4);
    config(['stripe_billing.environment' => 'production']);
    expect($service->allocateDue($start->addMonthNoOverflow()))->toBe(0)
        ->and(app(CreditWalletService::class)->balance($user))->toBe(0)
        ->and(CreditGrant::query()->where('environment', 'production')->count())->toBe(0);
    config(['stripe_billing.environment' => 'sandbox']);
    expect($service->allocateDue($start->addMonthNoOverflow()))->toBe(1)
        ->and(CreditGrant::query()->where('environment', 'sandbox')->count())->toBe(2);
});
