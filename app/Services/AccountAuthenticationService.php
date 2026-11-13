<?php

namespace App\Services;

use App\Jobs\SendAuthEmailCode;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AccountAuthenticationService
{
    public function __construct(private readonly CreditWalletService $wallet) {}

    public function requestRegistration(string $email): void
    {
        $this->issue($email, 'registration', null);
    }

    public function requestPasswordReset(string $email): void
    {
        $this->issue($email, 'password_reset', User::query()->appUsers()->whereRaw('LOWER(email) = ?', [$email])->where('is_active', true)->first());
    }

    private function issue(string $email, string $purpose, ?User $user): void
    {
        $key = 'auth-email-send:'.hash('sha256', $purpose.':'.$email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new HttpException(429, 'Too many code requests. Try again later.');
        }
        RateLimiter::hit($key, 3600);

        if (($purpose === 'registration' && User::query()->whereRaw('LOWER(email) = ?', [$email])->exists())
            || ($purpose === 'password_reset' && ! $user)) {
            return;
        }

        DB::transaction(function () use ($email, $purpose, $user): void {
            DB::table('auth_email_codes')->insertOrIgnore([
                'email' => $email,
                'purpose' => $purpose,
                'code_hash' => '',
            ]);

            $query = DB::table('auth_email_codes')->whereRaw('LOWER(email) = ?', [$email])->where('purpose', $purpose);
            $challenge = $query->lockForUpdate()->first();

            if ($challenge->sent_at && Carbon::parse($challenge->sent_at)->gt(now()->subMinute())) {
                throw new HttpException(429, 'Wait 60 seconds before requesting another code.');
            }

            // A worker owns this generation while its bounded provider request is in flight.
            if ($challenge->delivery_lease_until && Carbon::parse($challenge->delivery_lease_until)->isFuture()) {
                throw new HttpException(429, 'A code request is still being processed.');
            }

            $code = (string) random_int(100000, 999999);
            $deliveryId = (string) Str::uuid();
            $expiresAt = now()->addMinutes(10);
            $query->update([
                'user_id' => $user?->id,
                'code_hash' => $this->digest($email, $purpose, $code),
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'sent_at' => now(),
                'delivery_id' => $deliveryId,
                'delivery_lease_until' => null,
                'delivered_at' => null,
                'delivery_failed_at' => null,
                'provider_message_id' => null,
            ]);

            $job = (new SendAuthEmailCode($deliveryId, $challenge->id, $purpose, $code, $expiresAt->timestamp))
                ->onConnection(config('services.mailtrap.auth_queue_connection'))
                ->onQueue('auth-emails');

            DB::afterCommit(function () use ($job, $deliveryId): void {
                try {
                    Bus::dispatch($job);
                } catch (Throwable $exception) {
                    DB::table('auth_email_codes')->where('delivery_id', $deliveryId)->update([
                        'delivery_failed_at' => now(),
                        'expires_at' => null,
                        'code_hash' => '',
                    ]);
                    Log::error('Authentication email could not be enqueued.', [
                        'delivery_id' => $deliveryId,
                        'exception_type' => $exception::class,
                    ]);

                    throw new HttpException(503, 'Email requests are temporarily unavailable. Please try again.');
                }
            });
        });
    }

    public function register(string $email, string $code, string $name, string $password): void
    {
        $this->consume($email, 'registration', $code, function (object $challenge) use ($email, $name, $password): bool {
            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return false;
            }

            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
                'is_active' => true,
                'unlimited_devices' => false,
            ])->save();
            $user->assignRole('app_user');
            $this->wallet->grantWelcomeCredits($user);

            return true;
        });
    }

    public function resetPassword(string $email, string $code, string $password): void
    {
        $this->consume($email, 'password_reset', $code, function (object $challenge) use ($email, $password): bool {
            $user = User::query()->appUsers()->whereKey($challenge->user_id)
                ->whereRaw('LOWER(email) = ?', [$email])->where('is_active', true)->lockForUpdate()->first();

            if (! $user) {
                return false;
            }

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            $user->tokens()->delete();

            // Password recovery is not a device-transfer authorization.
            return true;
        });
    }

    private function consume(string $email, string $purpose, string $code, Closure $action): void
    {
        $error = DB::transaction(function () use ($email, $purpose, $code, $action): ?string {
            $query = DB::table('auth_email_codes')->whereRaw('LOWER(email) = ?', [$email])->where('purpose', $purpose);
            $challenge = $query->lockForUpdate()->first();

            if ($challenge?->expires_at && Carbon::parse($challenge->expires_at)->lte(now())) {
                return 'code_expired';
            }

            if (! $challenge || ! $challenge->expires_at || $challenge->attempts >= 5) {
                return 'code';
            }

            if (! hash_equals($challenge->code_hash, $this->digest($email, $purpose, $code))) {
                $query->increment('attempts');

                return 'code';
            }

            $success = $action($challenge);
            $query->update(['code_hash' => '', 'expires_at' => null, 'attempts' => 5]);

            return $success ? null : 'code';
        });

        if ($error !== null) {
            throw ValidationException::withMessages([$error => 'Invalid or expired code. Request a new code and try again.']);
        }
    }

    private function digest(string $email, string $purpose, string $code): string
    {
        return hash_hmac('sha256', $purpose.':'.$email.':'.$code, (string) config('app.key'));
    }
}
