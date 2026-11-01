<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSubscriptionEvent;
use App\Services\Subscriptions\AppleBillingProvider;
use App\Services\Subscriptions\GooglePushIdentity;
use App\Services\Subscriptions\SubscriptionInbox;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BillingWebhookController extends Controller
{
    public function apple(Request $request, AppleBillingProvider $apple, SubscriptionInbox $inbox): Response
    {
        $validated = $request->validate(['signedPayload' => ['required', 'string', 'max:1000000']]);
        $verified = $apple->notification($validated['signedPayload']);
        $verified['signed_payload'] = $validated['signedPayload'];
        $event = $inbox->provider('apple', $verified['id'], $verified);
        ProcessSubscriptionEvent::dispatch($event->getKey())->afterCommit();

        return response()->noContent();
    }

    public function google(Request $request, GooglePushIdentity $identity, SubscriptionInbox $inbox): Response
    {
        $identity->verify($request->bearerToken());
        $validated = $request->validate(['message.messageId' => ['required', 'string', 'max:255'], 'message.data' => ['required', 'string', 'max:1000000']]);
        $decoded = base64_decode($validated['message']['data'], true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        abort_unless(is_array($payload) && ($payload['packageName'] ?? '') === config('subscriptions.google.package_name'), 422);
        $reference = $payload['subscriptionNotification']['purchaseToken'] ?? $payload['voidedPurchaseNotification']['purchaseToken'] ?? null;
        abort_unless(is_string($reference) || isset($payload['testNotification']), 422);
        $event = $inbox->provider('google', $validated['message']['messageId'], ['reference' => $reference, 'notification' => $payload]);
        ProcessSubscriptionEvent::dispatch($event->getKey())->afterCommit();

        return response()->noContent();
    }
}
