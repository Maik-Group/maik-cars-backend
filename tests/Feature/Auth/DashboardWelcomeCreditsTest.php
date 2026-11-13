<?php

use App\Filament\Resources\AppUsers\Pages\CreateAppUser;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('grants five welcome credits when the dashboard creates an app user', function (): void {
    config(['stripe_billing.environment' => 'sandbox']);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    Role::findOrCreate('app_user', 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($admin);

    Livewire::test(CreateAppUser::class)
        ->fillForm(['name' => 'New Customer', 'email' => 'dashboard@example.test', 'password' => 'qa-password-123'])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'dashboard@example.test')->firstOrFail();
    $wallet = app(CreditWalletService::class);
    expect($user->hasRole('app_user'))->toBeTrue()
        ->and($wallet->balance($user, freeOnly: true))->toBe(5);

    $wallet->grantWelcomeCredits($user);
    $this->assertDatabaseCount('credit_grants', 1);
});
