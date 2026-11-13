<?php

use App\Models\User;
use App\Services\AuthEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('limits repeated login attempts without disclosing whether an email exists', function (): void {
    User::factory()->create(['email' => 'member@example.test', 'password' => 'correctPass123']);
    $payload = ['email' => 'member@example.test', 'password' => 'incorrectPass123', 'deviceId' => 'qa-device'];
    $unknown = $this->postJson('/api/auth/login', array_replace($payload, ['email' => 'unknown@example.test']))->assertUnprocessable();
    $this->postJson('/api/auth/login', $payload)->assertUnprocessable()->assertExactJson($unknown->json());
    for ($i = 0; $i < 8; $i++) {
        $this->postJson('/api/auth/login', $payload)->assertUnprocessable();
    }
    $this->postJson('/api/auth/login', $payload)->assertTooManyRequests();
    expect(User::query()->firstOrFail()->tokens()->count())->toBe(0);
});

it('rejects non-string login device identifiers instead of casting arrays', function (): void {
    User::factory()->create(['email' => 'member@example.test', 'password' => 'correctPass123']);
    $this->postJson('/api/auth/login', [
        'email' => 'member@example.test',
        'password' => 'correctPass123',
        'deviceId' => ['invalid'],
        'fcmToken' => ['invalid'],
    ])->assertUnprocessable();
    expect(User::query()->firstOrFail()->tokens()->count())->toBe(0);
});

it('requires registration proof and logs in only after account creation', function (): void {
    Role::findOrCreate('app_user', 'web');
    $code = null;
    $sender = Mockery::mock(AuthEmailSender::class);
    $sender->shouldReceive('sendCode')->once()->withArgs(function ($email, $value, $purpose) use (&$code): bool {
        $code = $value;

        return $email === 'new@example.test' && $purpose === 'registration';
    });
    $this->app->instance(AuthEmailSender::class, $sender);
    $login = ['email' => 'new@example.test', 'password' => 'validPass123', 'deviceId' => 'qa-device'];
    $this->postJson('/api/auth/register', ['email' => 'new@example.test'])->assertOk();
    $this->postJson('/api/auth/login', $login)->assertUnprocessable();
    $this->getJson('/api/profile')->assertUnauthorized();
    $this->postJson('/api/auth/verify-email', [
        'email' => 'different@example.test',
        'code' => $code,
        'name' => 'QA User',
        'password' => 'validPass123',
        'passwordConfirmation' => 'validPass123',
    ])->assertUnprocessable();
    $this->postJson('/api/auth/verify-email', [
        'email' => 'new@example.test',
        'code' => $code,
        'name' => 'QA User',
        'password' => 'validPass123',
        'passwordConfirmation' => 'validPass123',
    ])->assertCreated();
    $response = $this->postJson('/api/auth/login', $login)->assertOk();
    $this->withToken($response->json('token'))->getJson('/api/profile')->assertOk();
});

it('rejects old credentials and revokes existing tokens after password reset', function (): void {
    Role::findOrCreate('app_user', 'web');
    $user = User::factory()->create(['email' => 'member@example.test', 'password' => 'oldPass123', 'authorized_device_id' => 'qa-device']);
    $user->assignRole('app_user');
    $oldToken = $user->createToken('qa-old')->plainTextToken;
    $code = null;
    $sender = Mockery::mock(AuthEmailSender::class);
    $sender->shouldReceive('sendCode')->once()->withArgs(function ($email, $value, $purpose) use (&$code): bool {
        $code = $value;

        return $email === 'member@example.test' && $purpose === 'password_reset';
    });
    $this->app->instance(AuthEmailSender::class, $sender);
    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/auth/reset-password', ['email' => $user->email, 'code' => $code, 'password' => 'newPass123', 'passwordConfirmation' => 'newPass123'])->assertOk();
    $this->withToken($oldToken)->getJson('/api/profile')->assertUnauthorized();
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'oldPass123', 'deviceId' => 'qa-device'])->assertUnprocessable();
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'newPass123', 'deviceId' => 'qa-device'])->assertOk();
    expect($user->fresh()->authorized_device_id)->toBe('qa-device');
});

it('validates registration and reset payloads before issuing or consuming codes', function (): void {
    $sender = Mockery::mock(AuthEmailSender::class);
    $sender->shouldNotReceive('sendCode');
    $this->app->instance(AuthEmailSender::class, $sender);
    $this->postJson('/api/auth/register', ['email' => 'not-an-email'])->assertUnprocessable();
    $this->postJson('/api/auth/register', [])->assertUnprocessable();
    $this->postJson('/api/auth/verify-email', ['email' => 'new@example.test', 'code' => '123456', 'name' => 'QA User', 'password' => 'short', 'passwordConfirmation' => 'short'])->assertUnprocessable();
    $this->postJson('/api/auth/reset-password', ['email' => 'new@example.test', 'code' => '123456', 'password' => 'validPass123', 'passwordConfirmation' => 'different'])->assertUnprocessable();
    $this->postJson('/api/auth/reset-password', ['email' => 'new@example.test', 'code' => ['invalid'], 'password' => 'validPass123', 'passwordConfirmation' => 'validPass123'])->assertUnprocessable();
    expect(DB::table('auth_email_codes')->count())->toBe(0);
    expect(User::count())->toBe(0);
});
