<?php

use App\Exceptions\AuthEmailDeliveryException;
use App\Services\AccountAuthenticationService;
use App\Services\AuthEmailSender;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('database.connections.sqlite.url'))->toBeEmpty();
    Artisan::call('migrate', ['--force' => true]);
    config(['services.mailtrap.auth_queue_connection' => 'auth-email']);
    $this->sender = Mockery::mock(AuthEmailSender::class);
    $this->app->instance(AuthEmailSender::class, $this->sender);
});

function workAuthEmailQueue(): void
{
    Artisan::call('queue:work', ['connection' => 'auth-email', '--queue' => 'auth-emails', '--once' => true]);
}

it('publishes an encrypted job only after the challenge commits', function (): void {
    $code = null;
    $this->sender->shouldReceive('sendCode')->once()->withArgs(function ($email, $value, $purpose) use (&$code) {
        $code = $value;

        return $email === 'queued@example.test' && $purpose === 'registration';
    })->andReturn('11111111-2222-3333-4444-555555555555');

    DB::beginTransaction();
    $this->postJson('/api/auth/register', ['email' => 'queued@example.test'])->assertOk();
    expect(DB::table('jobs')->count())->toBe(0);
    DB::commit();

    $job = DB::table('jobs')->first();
    expect($job->queue)->toBe('auth-emails')
        ->and($job->payload)->not->toContain('queued@example.test')
        ->and(DB::table('auth_email_codes')->value('delivered_at'))->toBeNull();

    workAuthEmailQueue();
    expect($code)->toMatch('/^\d{6}$/')
        ->and($job->payload)->not->toContain('s:6:"'.$code.'"')
        ->and(DB::table('auth_email_codes')->value('provider_message_id'))->toBe('11111111-2222-3333-4444-555555555555');

    unset($job->id);
    DB::table('jobs')->insert((array) $job);
    workAuthEmailQueue();
    expect(DB::table('jobs')->count())->toBe(0);
});

it('retries a temporary delivery failure with the same code', function (AuthEmailDeliveryException $failure, int $delay): void {
    $codes = [];
    $this->sender->shouldReceive('sendCode')->twice()->andReturnUsing(function ($email, $code) use (&$codes, $failure) {
        $codes[] = $code;
        if (count($codes) === 1) {
            throw $failure;
        }

        return '11111111-2222-3333-4444-555555555555';
    });
    app(AccountAuthenticationService::class)->requestRegistration('retry@example.test');

    workAuthEmailQueue();
    expect(DB::table('jobs')->value('available_at'))->toBeGreaterThanOrEqual(now()->timestamp + $delay - 1)
        ->and(DB::table('auth_email_codes')->value('delivered_at'))->toBeNull();
    $this->travel($delay + 1)->seconds();
    workAuthEmailQueue();

    expect($codes)->toHaveCount(2)
        ->and($codes[0])->toBe($codes[1])
        ->and(DB::table('auth_email_codes')->value('delivered_at'))->not->toBeNull();
})->with([
    'timeout' => [new AuthEmailDeliveryException('timeout', retryable: true), 10],
    'network' => [new AuthEmailDeliveryException('network', retryable: true), 10],
    '429' => [new AuthEmailDeliveryException('rate_limited', retryable: true, httpStatus: 429, retryAfter: 25), 25],
    '503' => [new AuthEmailDeliveryException('provider_unavailable', retryable: true, httpStatus: 503), 10],
]);

it('records a permanent failure without retrying or leaking the provider exception', function (): void {
    $this->sender->shouldReceive('sendCode')->once()->andThrow(new RuntimeException('sensitive provider detail'));
    app(AccountAuthenticationService::class)->requestRegistration('failed@example.test');

    workAuthEmailQueue();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('auth_email_codes')->value('delivery_failed_at'))->not->toBeNull()
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('unexpected')->not->toContain('sensitive provider detail')
        ->and(DB::table('failed_jobs')->value('payload'))->not->toContain('failed@example.test');
});

it('skips expired and superseded queued generations', function (): void {
    $this->sender->shouldNotReceive('sendCode');
    $accounts = app(AccountAuthenticationService::class);
    $accounts->requestRegistration('stale@example.test');
    $this->travel(61)->seconds();
    $accounts->requestRegistration('stale@example.test');
    workAuthEmailQueue();
    expect(DB::table('jobs')->count())->toBe(1);

    $this->travel(10)->minutes();
    workAuthEmailQueue();
    expect(DB::table('jobs')->count())->toBe(0);
});
