<?php

namespace App\Services\Subscriptions;

use App\Models\StripeWebhookEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StripeEventProcessor
{
    public function __construct(
        private readonly StripeBillingGateway $stripe,
        private readonly StripeFulfillmentService $fulfillment,
    ) {}

    public function process(int $eventId): void
    {
        $processable = DB::transaction(function () use ($eventId): bool {
            $event = StripeWebhookEvent::query()->lockForUpdate()->findOrFail($eventId);
            if ($event->status === 'processed') {
                return false;
            }
            if ($event->status === 'processing' && $event->updated_at->greaterThan(now()->subMinutes(10))) {
                return false;
            }
            $event->forceFill([
                'status' => 'processing', 'attempts' => $event->attempts + 1,
                'next_attempt_at' => null, 'last_error' => null,
            ])->save();

            return true;
        });
        if (! $processable) {
            return;
        }

        try {
            $event = StripeWebhookEvent::query()->findOrFail($eventId);
            $payload = json_decode($event->encrypted_payload, true, flags: JSON_THROW_ON_ERROR);
            if (($payload['id'] ?? null) !== $event->stripe_event_id || ($payload['type'] ?? null) !== $event->type) {
                throw new RuntimeException('Stored Stripe event identity mismatch.');
            }
            $object = data_get($payload, 'data.object');
            if (! is_array($object)) {
                throw new RuntimeException('Stripe event object missing.');
            }
            $id = $object['id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw new RuntimeException('Stripe event object ID missing.');
            }
            $environment = $event->environment;
            switch ($event->type) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    $this->fulfillment->fulfillCheckout($this->stripe->checkoutSession($id), $environment);
                    break;
                case 'checkout.session.expired':
                case 'checkout.session.async_payment_failed':
                    $this->fulfillment->expireCheckout($id, $environment, 'expired');
                    break;
                case 'invoice.paid':
                case 'invoice.payment_succeeded':
                    $this->fulfillment->fulfillInvoice($this->stripe->invoice($id), $environment);
                    break;
                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    $this->fulfillment->subscriptionStatus($this->stripe->subscription($id), $environment);
                    break;
                case 'refund.created':
                case 'refund.updated':
                case 'refund.failed':
                case 'charge.refund.updated':
                    $this->fulfillment->refund($this->stripe->refund($id), $environment);
                    break;
                case 'charge.refunded':
                    $charge = $this->stripe->charge($id);
                    foreach (data_get($charge, 'refunds.data', []) as $refund) {
                        $refundId = $refund['id'] ?? null;
                        if (is_string($refundId)) {
                            $this->fulfillment->refund($this->stripe->refund($refundId), $environment);
                        }
                    }
                    if (data_get($charge, 'refunds.has_more', false)) {
                        throw new RuntimeException('Refund pagination requires reconciliation.');
                    }
                    break;
                case 'charge.dispute.created':
                case 'charge.dispute.updated':
                case 'charge.dispute.closed':
                case 'charge.dispute.funds_reinstated':
                case 'charge.dispute.funds_withdrawn':
                    $this->fulfillment->dispute($this->stripe->dispute($id), $environment);
                    break;
                case 'radar.early_fraud_warning.created':
                case 'radar.early_fraud_warning.updated':
                    $this->fulfillment->dispute($object + ['status' => 'needs_response'], $environment);
                    break;
                default:
                    // Unsubscribed/unrelated events are safely acknowledged.
                    break;
            }
            StripeWebhookEvent::query()->whereKey($eventId)->update([
                'status' => 'processed', 'processed_at' => now('UTC'), 'next_attempt_at' => null,
            ]);
        } catch (Throwable $error) {
            $attempts = (int) StripeWebhookEvent::query()->whereKey($eventId)->value('attempts');
            StripeWebhookEvent::query()->whereKey($eventId)->update([
                'status' => 'failed',
                'next_attempt_at' => now('UTC')->addSeconds(min(3600, 15 * (2 ** min(8, $attempts)))),
                // Never persist raw provider payloads or exception text to logs.
                'last_error' => class_basename($error),
            ]);
            report($error);
            throw $error;
        }
    }
}
