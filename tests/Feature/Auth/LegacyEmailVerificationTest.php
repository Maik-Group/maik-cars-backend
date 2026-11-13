<?php

use App\Models\User;
use App\Services\AuthEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('requires legacy mobile users to verify email before login or protected API access and restores access through recovery', function (): void {
    $user = User::factory()->unverified()->create(['password' => 'oldPassword123', 'authorized_device_id' => 'qa-device']);
    $user->assignRole(Role::findOrCreate('app_user', 'web'));
    $token = $user->createToken('legacy')->plainTextToken;
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'oldPassword123', 'deviceId' => 'qa-device'])
        ->assertForbidden()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
    $this->withToken($token)->getJson('/api/profile')->assertForbidden()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
    $this->getJson('/api/v2/wallet')->assertForbidden()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
    $this->getJson('/api/v1/billing/subscription')->assertForbidden()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');

    $code = null;
    $sender = Mockery::mock(AuthEmailSender::class);
    $sender->shouldReceive('sendCode')->once()->withArgs(function ($email, $value, $purpose) use ($user, &$code): bool {
        $code = $value;

        return $email === $user->email && $purpose === 'password_reset';
    });
    $this->app->instance(AuthEmailSender::class, $sender);
    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/auth/reset-password', ['email' => $user->email, 'code' => $code, 'password' => 'newPassword123', 'passwordConfirmation' => 'newPassword123'])->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->authorized_device_id)->toBe('qa-device')
        ->and($user->tokens()->count())->toBe(0);
    $this->app['auth']->forgetGuards();
    $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'newPassword123', 'deviceId' => 'qa-device'])->assertOk();
    $this->withToken($login->json('token'))->getJson('/api/profile')->assertOk();
});

it('keeps the administrative account policy separate from public mobile recovery', function (): void {
    $user = User::factory()->unverified()->create(['password' => 'adminPassword123', 'unlimited_devices' => true]);
    $user->assignRole(Role::findOrCreate('admin', 'web'));
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'adminPassword123'])->assertOk();
    expect($user->requiresMobileEmailVerification())->toBeFalse();
});
