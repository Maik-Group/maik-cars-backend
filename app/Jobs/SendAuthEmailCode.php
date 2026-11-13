<?php

namespace App\Jobs;

use App\Exceptions\AuthEmailDeliveryException;
use App\Services\AuthEmailSender;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAuthEmailCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 25;

    public function __construct(
        public readonly string $deliveryId,
        public readonly int $challengeId,
        public readonly string $purpose,
        private readonly string $code,
        private readonly int $expiresAt,
    ) {}

    public function handle(AuthEmailSender $sender): void
    {
        $started = hrtime(true);
        $challenge = $this->currentChallenge()->where('expires_at', '>', now()->addSeconds(15));
        $claimed = (clone $challenge)
            ->where(fn (Builder $query) => $query->whereNull('delivery_lease_until')->orWhere('delivery_lease_until', '<=', now()))
            ->update(['delivery_lease_until' => now()->addSeconds(30)]);

        if ($claimed === 0) {
            return;
        }

        $email = $challenge->value('email');
        if (! is_string($email)) {
            return;
        }

        try {
            $messageId = $sender->sendCode($email, $this->code, $this->purpose);
        } catch (Throwable $exception) {
            $this->handleDeliveryFailure($exception, $started);

            return;
        }

        $this->generation()->update([
            'delivered_at' => now(),
            'provider_message_id' => $messageId,
            'delivery_lease_until' => null,
        ]);
        Log::info('Mailtrap accepted authentication email.', $this->context($started) + [
            'http_status' => 200,
            'message_id' => $messageId,
        ]);
    }

    private function handleDeliveryFailure(Throwable $exception, int $started): void
    {
        $failure = $exception instanceof AuthEmailDeliveryException
            ? $exception
            : new AuthEmailDeliveryException('unexpected');
        $delay = max($this->attempts() <= 1 ? 10 : 30, $failure->retryAfter ?? 0);
        $willRetry = $failure->retryable
            && $this->attempts() < $this->tries
            && $delay < $this->expiresAt - now()->timestamp - 15;

        Log::warning('Authentication email attempt failed.', $this->context($started) + [
            'category' => $failure->category,
            'http_status' => $failure->httpStatus,
            'retry_in_seconds' => $willRetry ? $delay : null,
        ]);

        if ($willRetry) {
            $this->currentChallenge()->update(['delivery_lease_until' => null]);
            $this->release($delay);

            return;
        }

        $this->fail($failure);
    }

    public function failed(?Throwable $exception): void
    {
        $this->currentChallenge()->update(['delivery_failed_at' => now(), 'delivery_lease_until' => null]);
    }

    private function currentChallenge(): Builder
    {
        return $this->generation()
            ->where('attempts', '<', 5)
            ->whereNotNull('expires_at')
            ->whereNull('delivered_at')
            ->whereNull('delivery_failed_at');
    }

    private function generation(): Builder
    {
        return DB::table('auth_email_codes')
            ->where('id', $this->challengeId)
            ->where('delivery_id', $this->deliveryId);
    }

    private function context(int $started): array
    {
        return [
            'purpose' => $this->purpose,
            'delivery_id' => $this->deliveryId,
            'job_id' => $this->job?->getJobId(),
            'queue_uuid' => $this->job?->uuid(),
            'attempt' => $this->attempts(),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
    }
}
