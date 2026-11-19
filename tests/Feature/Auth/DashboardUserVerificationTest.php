<?php

use App\Filament\Resources\AppUsers\Pages\CreateAppUser;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->administrator = User::factory()->create(['is_active' => true]);
    $this->administrator->assignRole('super_admin');
});

test('dashboard-created app users are immediately verified without an email challenge', function (): void {
    Livewire::actingAs($this->administrator)
        ->test(CreateAppUser::class)
        ->fillForm([
            'name' => 'Provisioned App User',
            'email' => 'provisioned-app@example.test',
            'password' => 'SecureAdminPassword123!',
            'is_active' => true,
            'preferred_language' => 'en',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'provisioned-app@example.test')->firstOrFail();

    expect($created->email_verified_at)->not->toBeNull()
        ->and($created->requiresMobileEmailVerification())->toBeFalse()
        ->and($created->hasRole('app_user'))->toBeTrue()
        ->and(Hash::check('SecureAdminPassword123!', $created->password))->toBeTrue();
});

test('dashboard-created admins are immediately verified', function (): void {
    Livewire::actingAs($this->administrator)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Provisioned Admin',
            'email' => 'provisioned-admin@example.test',
            'password' => 'SecureAdminPassword123!',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'provisioned-admin@example.test')->firstOrFail();

    expect($created->email_verified_at)->not->toBeNull()
        ->and($created->hasRole('admin'))->toBeTrue()
        ->and(Hash::check('SecureAdminPassword123!', $created->password))->toBeTrue();
});
