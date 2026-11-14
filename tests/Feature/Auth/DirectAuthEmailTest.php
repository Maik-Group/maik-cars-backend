<?php

use App\Exceptions\AuthEmailDeliveryException;
use App\Services\AuthEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['queue.default' => 'database']);
    Queue::fake();
    $this->sender = Mockery::mock(AuthEmailSender::class);
    $this->app->instance(AuthEmailSender::class, $this->sender);
});

it('sends the code directly before returning even when the database queue is configured', function (): void {
    $this->sender->shouldReceive('sendCode')->once()->withArgs(function ($email, $code, $purpose): bool {
        $challenge = DB::table('auth_email_codes')->where('email', $email)->first();
        expect($challenge)->not->toBeNull()
            ->and($challenge->code_hash)->not->toBe($code)
            ->and($challenge->delivery_lease_until)->not->toBeNull()
            ->and($challenge->delivered_at)->toBeNull();

        return $email === 'direct@example.test' && $purpose === 'registration' && preg_match('/^\d{6}$/', $code) === 1;
    })->andReturn('11111111-2222-3333-4444-555555555555');

    $this->postJson('/api/auth/register', ['email' => 'direct@example.test'])->assertOk();

    expect(DB::table('auth_email_codes')->value('provider_message_id'))->toBe('11111111-2222-3333-4444-555555555555')
        ->and(DB::table('auth_email_codes')->value('delivered_at'))->not->toBeNull()
        ->and(DB::table('auth_email_codes')->value('delivery_lease_until'))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('invalidates a failed direct delivery without retrying or exposing provider information', function (Throwable $failure): void {
    Log::spy();
    $this->sender->shouldReceive('sendCode')->once()->andThrow($failure);

    $response = $this->postJson('/api/auth/register', ['email' => 'failed@example.test'])->assertOk();
    $response->assertJsonMissing(['message' => 'sensitive provider detail']);
    $challenge = DB::table('auth_email_codes')->first();

    expect($challenge->delivery_failed_at)->not->toBeNull()
        ->and($challenge->delivery_lease_until)->toBeNull()
        ->and($challenge->expires_at)->toBeNull()
        ->and($challenge->code_hash)->toBe('')
        ->and(DB::table('jobs')->count())->toBe(0);
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('warning')->once()->with('Authentication email delivery failed.', Mockery::on(fn (array $context): bool => ! str_contains(json_encode($context), 'sensitive provider detail')));
})->with([
    'network' => [new AuthEmailDeliveryException('network', retryable: true)],
    'rate limit' => [new AuthEmailDeliveryException('rate_limited', retryable: true, httpStatus: 429)],
    'unexpected' => [new RuntimeException('sensitive provider detail')],
]);

it('permits a new direct delivery after the cooldown following a failed send', function (): void {
    $this->sender->shouldReceive('sendCode')->once()->andThrow(new AuthEmailDeliveryException('timeout', retryable: true));
    $this->postJson('/api/auth/register', ['email' => 'retry@example.test'])->assertOk();
    $this->postJson('/api/auth/resend-verification', ['email' => 'retry@example.test'])->assertTooManyRequests();
    $this->travel(61)->seconds();
    $this->sender->shouldReceive('sendCode')->once()->andReturn('11111111-2222-3333-4444-555555555555');
    $this->postJson('/api/auth/resend-verification', ['email' => 'retry@example.test'])->assertOk();
    expect(DB::table('auth_email_codes')->value('delivered_at'))->not->toBeNull()
        ->and(DB::table('auth_email_codes')->value('delivery_failed_at'))->toBeNull();
    Queue::assertNothingPushed();
});
