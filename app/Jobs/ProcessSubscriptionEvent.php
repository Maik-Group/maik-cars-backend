<?php

namespace App\Jobs;

use App\Exceptions\SubscriptionAccessException;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Subscriptions\GooglePlayBillingProvider;
use App\Services\Subscriptions\StorePurchaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessSubscriptionEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public readonly int $eventId, public readonly bool $forceVerification = false) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('subscription-event:'.$this->eventId))->releaseAfter(30)->expireAfter(300)];
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300, 900];
    }

    public function failed(?Throwable $exception): void
    {
        SubscriptionEvent::query()->whereKey($this->eventId)->where('state', '!=', 'processed')
            ->update(['state' => 'failed', 'error_code' => 'VERIFICATION_UNAVAILABLE']);
    }

    public function handle(StorePurchaseService $purchases, GooglePlayBillingProvider $google): void
    {
        $event = SubscriptionEvent::query()->findOrFail($this->eventId);
        if ($event->state === 'processed' && ! $this->forceVerification) {
            return;
        }
        $event->increment('attempts');
        $event->update(['state' => 'processing', 'error_code' => null]);
        try {
            $this->process($event, $purchases, $google);
            $event->update(['state' => 'processed', 'processed_at' => now(), 'error_code' => null]);
        } catch (SubscriptionAccessException $exception) {
            $event->update(['state' => $exception->httpStatus >= 500 ? 'pending' : 'failed', 'error_code' => $exception->errorCode]);
            if ($exception->httpStatus >= 500) {
                throw $exception;
            }
        } catch (Throwable) {
            // Queue failure reports contain a redacted code, never provider URLs or receipt arguments.
            $event->update(['state' => 'pending', 'error_code' => 'VERIFICATION_UNAVAILABLE']);
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }
    }

    private function process(SubscriptionEvent $event, StorePurchaseService $purchases, GooglePlayBillingProvider $google): void
    {
        $payload = $event->payload;
        $reference = $payload['reference'] ?? null;
        if (! $reference) {
            return;
        }
        if ($event->user_id !== null) {
            $user = User::query()->findOrFail($event->user_id);
        } elseif ($event->provider === 'apple') {
            $user = User::query()->where('billing_account_uuid', $payload['account_binding'] ?? '')->first();
        } else {
            $evidence = $google->verify($reference, null);
            $user = User::query()->where('billing_account_hash', $evidence->accountBinding)->first();
        }
        if (! $user) {
            throw new SubscriptionAccessException('PURCHASE_ACCOUNT_NOT_FOUND', 422);
        }
        $agreement = $purchases->verify($user, $event->provider, $reference);
        $event->update(['user_id' => $user->getKey(), 'billing_agreement_id' => $agreement->getKey(), 'environment' => $agreement->environment]);
    }
}
