<?php

namespace App\Http\Controllers\API;

use App\Exceptions\SubscriptionAccessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\StorePurchaseRequest;
use App\Jobs\ProcessSubscriptionEvent;
use App\Jobs\ReconcileSubscription;
use App\Models\BillingAgreement;
use App\Services\Subscriptions\StorePurchaseService;
use App\Services\Subscriptions\SubscriptionInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorePurchaseController extends Controller
{
    public function purchase(StorePurchaseRequest $request, string $provider, StorePurchaseService $purchases): JsonResponse
    {
        $this->activeAccount($request);
        if (! config('subscriptions.purchases_enabled')) {
            throw new SubscriptionAccessException('PURCHASES_UNAVAILABLE', 503);
        }
        $agreement = $purchases->verify($request->user(), $provider, $request->validated('reference'));

        return response()->json(['data' => ['verified' => true, 'agreement_id' => $agreement->getKey(), 'state' => $agreement->provider_state, 'acknowledgement_pending' => $agreement->acknowledgement_pending]], $agreement->provider_state === 'pending' ? 202 : 200)
            ->header('Cache-Control', 'private, no-store');
    }

    public function restore(Request $request, SubscriptionInbox $inbox): JsonResponse
    {
        $this->activeAccount($request);
        $validated = $request->validate([
            'purchases' => ['required', 'array', 'min:1', 'max:20'],
            'purchases.*.provider' => ['required', 'in:apple,google'], 'purchases.*.reference' => ['required', 'string', 'max:4096'],
        ]);
        $events = [];
        foreach ($validated['purchases'] as $purchase) {
            $event = $inbox->restore($request->user(), $purchase['provider'], $purchase['reference']);
            ProcessSubscriptionEvent::dispatch($event->getKey())->afterCommit();
            $events[] = $event->correlation_id;
        }

        return response()->json(['code' => 'VERIFICATION_PENDING', 'data' => ['event_ids' => $events]], 202)->header('Cache-Control', 'private, no-store');
    }

    public function resync(Request $request): JsonResponse
    {
        $this->activeAccount($request);
        BillingAgreement::query()->where('user_id', $request->user()->getKey())->orderBy('id')->chunkById(100, function ($agreements): void {
            foreach ($agreements as $agreement) {
                ReconcileSubscription::dispatch($agreement->getKey())->afterCommit();
            }
        });

        return response()->json(['code' => 'VERIFICATION_PENDING'], 202);
    }

    private function activeAccount(Request $request): void
    {
        if (! $request->user()->is_active) {
            throw new SubscriptionAccessException('ACCOUNT_INACTIVE', 403);
        }
    }
}
