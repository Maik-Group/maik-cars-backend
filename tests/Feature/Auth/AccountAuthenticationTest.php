<?php

use App\Models\User;
use App\Services\AuthEmailSender;
use App\Services\Credits\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('app_user', 'web');
    $this->sender = Mockery::mock(AuthEmailSender::class);
    $this->app->instance(AuthEmailSender::class, $this->sender);
});

function captureAccountCode(object $test, string $email, string $purpose): object
{
    $captured = (object) ['code' => null];
    $test->sender->shouldReceive('sendCode')->once()->withArgs(function ($recipient, $code, $actualPurpose) use ($email, $purpose, $captured) {
        $captured->code = $code;

        return $recipient === $email && preg_match('/^\d{6}$/', $code) === 1 && $actualPurpose === $purpose;
    });

    return $captured;
}

function accountVerificationPayload(string $email, string $code): array
{
    return ['email' => $email, 'code' => $code, 'name' => 'New Customer', 'password' => 'securePass123!', 'passwordConfirmation' => 'securePass123!'];
}

it('creates only a verified regular account after proof of email ownership', function (): void {
    config(['stripe_billing.environment' => 'sandbox']);
    $email = 'new@example.test';
    $captured = captureAccountCode($this, $email, 'registration');

    postJson('/api/auth/register', ['email' => 'NEW@example.test'])->assertOk();
    expect(User::where('email', $email)->exists())->toBeFalse();
    $this->assertDatabaseCount('credit_grants', 0);
    expect(DB::table('auth_email_codes')->value('code_hash'))->not->toBe($captured->code);

    postJson('/api/auth/verify-email', accountVerificationPayload($email, $captured->code))->assertCreated();
    $user = User::where('email', $email)->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole('app_user'))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('securePass123!', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    postJson('/api/auth/verify-email', accountVerificationPayload($email, $captured->code))->assertUnprocessable();
    expect(User::where('email', $email)->count())->toBe(1);
    expect(app(CreditWalletService::class)->balance($user))->toBe(5);
    $this->assertDatabaseCount('credit_grants', 1);
    $this->assertDatabaseHas('credit_grants', [
        'user_id' => $user->id, 'source_type' => 'free_grant', 'credits' => 5, 'expires_at' => null,
    ]);
});

it('persists failed attempts and locks a code after five incorrect attempts', function (): void {
    $email = 'attempts@example.test';
    $captured = captureAccountCode($this, $email, 'registration');
    postJson('/api/auth/register', ['email' => $email])->assertOk();

    $wrong = $captured->code === '111111' ? '222222' : '111111';
    for ($i = 0; $i < 5; $i++) {
        postJson('/api/auth/verify-email', accountVerificationPayload($email, $wrong))->assertUnprocessable();
    }
    expect(DB::table('auth_email_codes')->value('attempts'))->toBe(5);
    postJson('/api/auth/verify-email', accountVerificationPayload($email, $captured->code))->assertUnprocessable();
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('expires codes and replaces them on resend after the cooldown', function (): void {
    $email = 'resend@example.test';
    $first = captureAccountCode($this, $email, 'registration');
    postJson('/api/auth/register', ['email' => $email])->assertOk();
    postJson('/api/auth/resend-verification', ['email' => $email])->assertTooManyRequests();
    $this->travel(11)->minutes();
    postJson('/api/auth/verify-email', accountVerificationPayload($email, $first->code))->assertUnprocessable();

    $next = captureAccountCode($this, $email, 'registration');
    postJson('/api/auth/resend-verification', ['email' => $email])->assertOk();
    postJson('/api/auth/verify-email', accountVerificationPayload($email, $next->code))->assertCreated();
});

it('resets passwords with an email code and revokes tokens without changing the device binding', function (): void {
    $user = User::factory()->create(['email' => 'recover@example.test', 'is_active' => true, 'authorized_device_id' => 'original-device']);
    $user->assignRole('app_user');
    $user->createToken('old-device');
    $captured = captureAccountCode($this, $user->email, 'password_reset');
    postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    $payload = ['email' => $user->email, 'code' => $captured->code, 'password' => 'newPassword123!', 'password_confirmation' => 'newPassword123!'];
    postJson('/api/auth/verify-email', $payload + ['name' => 'Other'])->assertUnprocessable();
    postJson('/api/auth/reset-password', $payload)->assertOk();
    expect(Hash::check('newPassword123!', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->authorized_device_id)->toBe('original-device')
        ->and($user->tokens()->count())->toBe(0);

    postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
});

it('does not send codes for unknown or privileged accounts or existing registration addresses', function (): void {
    $admin = User::factory()->create(['email' => 'admin@example.test', 'is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->sender->shouldNotReceive('sendCode');
    $unknown = postJson('/api/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk();
    postJson('/api/auth/forgot-password', ['email' => $admin->email])->assertOk()->assertExactJson($unknown->json());
    postJson('/api/auth/register', ['email' => $admin->email])->assertOk();
});

it('records a terminal delivery failure without reporting a rejected email request', function (): void {
    $this->sender->shouldReceive('sendCode')->once()->andThrow(new RuntimeException('provider failure'));
    postJson('/api/auth/register', ['email' => 'failure@example.test'])->assertOk();
    expect(DB::table('auth_email_codes')->value('delivery_failed_at'))->not->toBeNull();
});

it('does not permit mobile customers to replace verified email or use administrative import routes', function (): void {
    $user = User::factory()->create(['email' => 'customer@example.test']);
    $user->assignRole('app_user');
    Sanctum::actingAs($user);

    $this->patchJson('/api/profile', ['name' => $user->name, 'email' => 'other@example.test'])->assertUnprocessable();
    expect($user->fresh()->email)->toBe($user->email);
    postJson('/api/imports', [])->assertForbidden();
    postJson('/api/v1/metals/refresh', [])->assertForbidden();
});
