<?php

use App\Models\ComplimentaryAllocation;
use App\Models\CreditGrant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Credits\AdminGrantBatchService;
use App\Services\Credits\ComplimentaryCreditService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['stripe_billing.environment' => 'production']));

it('freezes eligible selected recipients and resumes idempotently', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $first = User::factory()->create();
    $second = User::factory()->create();
    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('admin');
    $service = app(AdminGrantBatchService::class);
    $batch = $service->preview($admin, 'selected', [$first->id, $second->id, $otherAdmin->id], 4, null);
    expect($batch->recipient_count)->toBe(2);
    $service->confirm($admin, $batch, $batch->snapshot_hash);
    $service->processChunk($batch->id);
    expect($batch->fresh()->status)->toBe('completed')
        ->and(CreditGrant::query()->where('source_type', 'free_grant')->count())->toBe(2);
    $service->processChunk($batch->id);
    expect(CreditGrant::query()->count())->toBe(2);
});

it('stops after permission revocation and does not auto enroll future users', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    User::factory()->create();
    $service = app(AdminGrantBatchService::class);
    $batch = $service->preview($admin, 'all', [], 6, null);
    $before = $batch->recipient_count;
    User::factory()->create();
    $service->confirm($admin, $batch, $batch->snapshot_hash);
    $admin->revokePermissionTo('grant_bulk_credits');
    $admin->removeRole('admin');
    $service->processChunk($batch->id);
    expect($batch->fresh()->status)->toBe('paused')
        ->and(CreditGrant::query()->count())->toBe(0)
        ->and($batch->recipients()->count())->toBe($before);
});

it('cancels pending recipients and rejects changed preview confirmation', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    User::factory()->count(3)->create();
    $service = app(AdminGrantBatchService::class);
    $batch = $service->preview($admin, 'all', [], 2, null);
    expect(fn () => $service->confirm($admin, $batch, str_repeat('a', 64)))
        ->toThrow(HttpException::class);
    $service->confirm($admin, $batch, $batch->snapshot_hash);
    $service->cancel($admin, $batch->fresh());
    $service->processChunk($batch->id);
    expect($batch->fresh()->status)->toBe('cancelled')
        ->and($batch->recipients()->where('status', 'cancelled')->count())->toBe($batch->recipient_count)
        ->and(CreditGrant::query()->count())->toBe(0);
});

it('freezes more than two chunks in deterministic ID order without enrolling later users', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    User::factory()->count(235)->create();
    $service = app(AdminGrantBatchService::class);
    $expiry = CarbonImmutable::parse('2027-01-15', 'UTC');
    $batch = $service->preview($admin, 'all', [], 8, $expiry);
    $ids = $batch->recipients()->orderBy('user_id')->pluck('user_id')->all();
    $digest = hash_init('sha256');
    hash_update($digest, json_encode(['all', 8, $expiry->getTimestamp(), config('stripe_billing.environment')], JSON_THROW_ON_ERROR).':');
    foreach ($ids as $id) {
        hash_update($digest, $id.',');
    }
    expect($batch->recipient_count)->toBe(235)
        ->and($batch->snapshot_hash)->toBe(hash_final($digest))
        ->and(count($ids))->toBe(235);
    $future = User::factory()->create();
    $service->confirm($admin, $batch, $batch->snapshot_hash);
    expect($batch->recipients()->where('user_id', $future->id)->exists())->toBeFalse();
    $service->processChunk($batch->id);
    expect($batch->recipients()->where('status', 'completed')->count())->toBe(50);
});

it('assigns selected complimentary local-plan snapshots once without Stripe and schedules later periods', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    $actor = User::factory()->create();
    $actor->assignRole('admin');
    $user = User::factory()->create();
    $plan = SubscriptionPlan::factory()->create(['name' => 'Local Basic', 'duration_months' => 1]);
    $start = CarbonImmutable::parse('2026-01-31 12:00:00', 'UTC');
    $end = CarbonImmutable::parse('2026-04-02 12:00:00', 'UTC');
    $service = app(AdminGrantBatchService::class);
    $batch = $service->previewComplimentary($actor, 'selected', [$user->id], 7, 1, $start, $end, 'per_period', $plan->id);
    expect($batch->plan_snapshot['name'])->toBe('Local Basic');
    $plan->update(['name' => 'Changed Plan']);
    $service->confirm($actor, $batch, $batch->snapshot_hash);
    $service->processChunk($batch->id);
    $service->processChunk($batch->id);
    $allocation = ComplimentaryAllocation::query()->sole();
    expect($allocation->plan_snapshot['name'])->toBe('Local Basic')
        ->and($allocation->batch_recipient_key)->toBe('admin-batch:'.$batch->id.':user:'.$user->id)
        ->and(ComplimentaryAllocation::query()->count())->toBe(1)
        ->and(CreditGrant::query()->count())->toBe(0);
    $this->travelTo($start);
    expect(app(ComplimentaryCreditService::class)->allocateDue())->toBe(1);
    $this->travelTo(CarbonImmutable::parse('2026-02-28 12:00:00', 'UTC'));
    expect(app(ComplimentaryCreditService::class)->allocateDue())->toBe(1);
});

it('freezes complimentary batch environment across config changes and only grants in original environment', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    config(['stripe_billing.environment' => 'sandbox']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $user = User::factory()->create();
    $plan = SubscriptionPlan::factory()->create();
    $start = CarbonImmutable::parse('2026-11-01', 'UTC');
    $service = app(AdminGrantBatchService::class);
    $batch = $service->previewComplimentary($admin, 'selected', [$user->id], 3, 1, $start, $start->addMonthsNoOverflow(2), 'per_period', $plan->id);
    expect($batch->environment)->toBe('sandbox');
    config(['stripe_billing.environment' => 'production']);
    $service->confirm($admin, $batch, $batch->snapshot_hash);
    $service->processChunk($batch->id);
    expect(ComplimentaryAllocation::query()->sole()->environment)->toBe('sandbox')
        ->and(app(ComplimentaryCreditService::class)->allocateDue($start))->toBe(0);
    config(['stripe_billing.environment' => 'sandbox']);
    expect(app(ComplimentaryCreditService::class)->allocateDue($start))->toBe(1)
        ->and(CreditGrant::query()->where('environment', 'production')->count())->toBe(0);
});
