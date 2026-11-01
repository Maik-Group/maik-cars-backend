<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\StripeWebhookEvent;
use App\Services\Subscriptions\StripeBillingGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeBillingGateway $stripe): JsonResponse
    {
        $body = $request->getContent();
        if (strlen($body) > 1048576 || strlen($body) < 10) {
            return response()->json(['code' => 'INVALID_WEBHOOK_SIZE'], 413);
        }
        try {
            $event = $stripe->verifyEvent($body, (string) $request->header('Stripe-Signature', ''));
        } catch (Throwable) {
            return response()->json(['code' => 'INVALID_STRIPE_SIGNATURE'], 400);
        }
        $environment = (string) config('stripe_billing.environment', 'production');
        if ($environment !== (string) config('subscriptions.environment', 'production')) {
            return response()->json(['code' => 'BILLING_ENVIRONMENT_MISMATCH'], 503);
        }
        if (! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)
            || ($event['livemode'] ?? null) !== ($environment === 'production')) {
            return response()->json(['code' => 'WEBHOOK_ENVIRONMENT_MISMATCH'], 400);
        }
        try {
            $saved = StripeWebhookEvent::query()->firstOrCreate(
                ['environment' => $environment, 'stripe_event_id' => $event['id']],
                [
                    'type' => $event['type'], 'encrypted_payload' => $body,
                    'status' => 'pending', 'received_at' => now('UTC'),
                ],
            );
        } catch (Throwable $error) {
            report($error);

            return response()->json(['code' => 'WEBHOOK_PERSISTENCE_FAILED'], 503);
        }
        if ($saved->status !== 'processed') {
            try {
                ProcessStripeWebhookEvent::dispatch($saved->getKey())->afterResponse();
            } catch (Throwable $error) {
                // Durable pending rows are recovered by the scheduled command.
                report($error);
            }
        }

        return response()->json(['received' => true]);
    }
}
