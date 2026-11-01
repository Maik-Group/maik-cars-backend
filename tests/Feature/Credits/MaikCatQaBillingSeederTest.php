<?php

use App\Models\AdminGrantBatch;
use App\Models\CreditGrant;
use App\Models\User;
use Database\Seeders\MaikCatQaBillingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds sandbox-only QA fixtures even when the server admin role lacks bulk grants', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $adminRole = Role::findByName('admin');
    $adminRole->revokePermissionTo('grant_bulk_credits');
    $unrelatedAdmin = User::factory()->create();
    $unrelatedAdmin->assignRole('admin');

    $originalStorage = storage_path();
    $storage = sys_get_temp_dir().'/maik-qa-seeder-test-'.Str::random(16);
    app()->useStoragePath($storage);
    Queue::fake();
    config()->set('stripe_billing.environment', 'production');

    try {
        $this->seed(MaikCatQaBillingSeeder::class);

        $qaAdmin = User::query()->where('email', MaikCatQaBillingSeeder::ACCOUNTS['admin'])->firstOrFail();
        $bulk = User::query()->where('email', MaikCatQaBillingSeeder::ACCOUNTS['bulk'])->firstOrFail();
        expect(config('stripe_billing.environment'))->toBe('production')
            ->and($qaAdmin->can('grant_bulk_credits'))->toBeFalse()
            ->and($unrelatedAdmin->fresh()->can('grant_bulk_credits'))->toBeFalse()
            ->and(Role::findByName('admin')->hasPermissionTo('grant_bulk_credits'))->toBeFalse()
            ->and(AdminGrantBatch::query()->where('environment', 'production')->count())->toBe(0)
            ->and(AdminGrantBatch::query()->where('environment', 'sandbox')->where('status', 'completed')->count())->toBe(1)
            ->and(CreditGrant::query()->where('environment', 'production')->count())->toBe(0)
            ->and(CreditGrant::query()->where('environment', 'sandbox')->where('user_id', $bulk->id)->sum('credits'))->toBe(3)
            ->and(is_file($storage.'/app/private/maik-qa-credentials.json'))->toBeTrue();

        $grantCount = CreditGrant::query()->count();
        $batchCount = AdminGrantBatch::query()->count();
        $this->seed(MaikCatQaBillingSeeder::class);
        expect(CreditGrant::query()->count())->toBe($grantCount)
            ->and(AdminGrantBatch::query()->count())->toBe($batchCount)
            ->and($qaAdmin->fresh()->can('grant_bulk_credits'))->toBeFalse()
            ->and(config('stripe_billing.environment'))->toBe('production');
    } finally {
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($storage);
    }
});
