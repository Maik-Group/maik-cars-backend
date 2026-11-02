<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\BillingPlanPrice;
use App\Models\BillingOrder;
use App\Models\BillingSubscription;
use App\Models\StripeCustomer;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeCheckoutService;
use App\Services\Subscriptions\StripeOrderReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class StripeBillingController extends Controller
{
    public function plans(StripeCheckoutService $billing): JsonResponse
    {
        return response()->json(['data' => $billing->catalog()])->header('Cache-Control', 'private, no-store');
    }

    public function checkout(Request $request, StripeCheckoutService $billing): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['subscription', 'topup'])],
            'priceVersionId' => ['required', 'integer', 'min:1'],
            'purchaseContextId' => ['required', 'string', 'max:100'],
            'credits' => ['prohibited'],
            'amountMinor' => ['prohibited'],
            'currency' => ['prohibited'],
            'userId' => ['prohibited'],
        ]);
        $order = $billing->begin(
            $request->user(), $data['kind'], (int) $data['priceVersionId'],
            $data['purchaseContextId'], (string) $request->header('Idempotency-Key', ''),
        );

        return response()->json(['data' => $billing->orderResponse($order, true)], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function checkoutStatus(
        Request $request, string $orderId,
        StripeCheckoutService $billing, StripeOrderReconciliationService $reconciliation,
    ): JsonResponse
    {
        $order = $billing->orderFor($request->user(), $orderId);
        if ($order->fulfillment_status !== 'fulfilled'
            && ! in_array($order->status, ['expired', 'cancelled'], true)
            && $order->stripe_checkout_session_id) {
            try {
                // Only Stripe's authenticated API can settle this order.
                // Checkout return parameters never grant wallet credits.
                $reconciliation->reconcile($order);
                $order->refresh();
            } catch (Throwable $error) {
                report($error); // Signed webhook recovery remains authoritative.
            }
        }
        return response()->json(['data' => $billing->orderResponse($order)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function subscription(Request $request, StripeCheckoutService $billing, StripeOrderReconciliationService $reconciliation): JsonResponse
    {
        $user = $request->user();
        $environment = $billing->environment();
        // Also recover successful purchases from older web handoffs that
        // could not persist their order IDs in the native app. This is a
        // bounded, owner-scoped recovery of Stripe evidence, never a grant
        // based on the client's return URL.
        $recent = BillingOrder::query()->where('user_id', $user->getKey())
            ->where('environment', $environment)
            ->whereIn('status', ['checkout_created', 'paid'])
            ->whereNotNull('stripe_checkout_session_id')
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('created_at')->limit(2)->get();
        foreach ($recent as $order) {
            try {
                $reconciliation->reconcile($order);
            } catch (Throwable $error) {
                report($error);
            }
        }
        $subscription = BillingSubscription::query()->where('user_id', $user->getKey())
            ->where('environment', $environment)->latest('id')->first();
        $planName = $subscription ? BillingPlanPrice::query()->whereKey($subscription->billing_plan_price_id)
            ->where('environment', $environment)->first() : null;
        $comps = Schema::hasTable('complimentary_allocations')
            ? DB::table('complimentary_allocations')->where('user_id', $user->getKey())->where('environment', $environment)->orderByDesc('id')
                ->get()->map(fn (object $entitlement): array => [
                    'id' => $entitlement->id, 'startsAt' => $entitlement->starts_at,
                    'endsAt' => $entitlement->ends_at, 'revokedAt' => $entitlement->revoked_at,
                    'creditsPerAllocation' => (int) $entitlement->credits,
                    'durationMonths' => (int) $entitlement->months,
                    'issuanceMode' => $entitlement->issuance_mode,
                ])->all()
            : [];

        return response()->json(['data' => [
            'paidSubscription' => $subscription ? [
                'status' => $subscription->status,
                'priceVersionId' => $subscription->billing_plan_price_id,
                'planName' => $planName?->name,
                'durationMonths' => $planName?->duration_months,
                'periodStart' => $subscription->period_start?->toIso8601String(),
                'periodEnd' => $subscription->period_end?->toIso8601String(),
                'cancelAtPeriodEnd' => $subscription->cancel_at_period_end,
                'pendingPriceVersionId' => $subscription->pending_price_id,
            ] : null,
            'complimentaryEntitlements' => $comps,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function portal(Request $request, StripeCheckoutService $billing, StripeBillingGateway $stripe): JsonResponse
    {
        $customer = StripeCustomer::query()->where('user_id', $request->user()->getKey())
            ->where('environment', $billing->environment())->first();
        if (! $customer || ! $customer->stripe_customer_id) {
            throw new HttpException(409, 'STRIPE_CUSTOMER_NOT_FOUND');
        }
        $managed = BillingSubscription::query()->where('user_id', $request->user()->getKey())
            ->where('environment', $billing->environment())->whereNotNull('stripe_schedule_id')->exists();
        $config = config($managed ? 'stripe_billing.restricted_portal_configuration' : 'stripe_billing.portal_configuration');
        if (! is_string($config) || ! str_starts_with($config, 'bpc_')) {
            throw new HttpException(503, 'PORTAL_NOT_CONFIGURED');
        }
        $session = $stripe->portal($customer->stripe_customer_id, $config);

        return response()->json(['data' => ['url' => $session['url'] ?? null]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function planChange(Request $request, StripeCheckoutService $billing, StripeBillingGateway $stripe): JsonResponse
    {
        $data = $request->validate(['priceVersionId' => ['required', 'integer', 'min:1']]);
        $environment = $billing->environment();
        $userId = $request->user()->getKey();
        $subscription = BillingSubscription::query()->where('user_id', $userId)
            ->where('environment', $environment)->where('status', 'active')->latest('id')->first();
        if (! $subscription || ! $subscription->period_start || ! $subscription->period_end || $subscription->cancel_at_period_end) {
            throw new HttpException(409, 'NO_ELIGIBLE_SUBSCRIPTION');
        }
        $new = BillingPlanPrice::query()->whereKey($data['priceVersionId'])->where('environment', $environment)
            ->where('kind', 'subscription')->first();
        $old = BillingPlanPrice::query()->findOrFail($subscription->billing_plan_price_id);
        if (! $new || ! $new->checkoutEligible() || $old->getKey() === $new->getKey()) {
            throw new HttpException(409, 'OFFER_CHANGED');
        }
        if ($subscription->pending_price_id && $subscription->pending_price_id !== $new->getKey()) {
            throw new HttpException(409, 'PLAN_CHANGE_ALREADY_SCHEDULED');
        }
        if ($subscription->pending_price_id === $new->getKey()) {
            return response()->json(['data' => ['pendingPriceVersionId' => $new->getKey()]]);
        }
        try {
            $scheduleId = $subscription->stripe_schedule_id;
            if (! $scheduleId) {
                $created = $stripe->scheduleFromSubscription($subscription->stripe_subscription_id);
                $scheduleId = (string) ($created['id'] ?? '');
            }
            if (! str_starts_with($scheduleId, 'sub_sched_')) {
                throw new HttpException(503, 'SCHEDULE_CREATION_FAILED');
            }
            $stripe->updateSchedule($scheduleId, [
                ['items' => [['price' => $old->stripe_price_id, 'quantity' => 1]],
                    'start_date' => $subscription->period_start->timestamp, 'end_date' => $subscription->period_end->timestamp],
                ['items' => [['price' => $new->stripe_price_id, 'quantity' => 1]],
                    'start_date' => $subscription->period_end->timestamp, 'iterations' => 1],
            ]);
            $subscription->forceFill(['stripe_schedule_id' => $scheduleId, 'pending_price_id' => $new->getKey()])->save();
        } catch (Throwable $error) {
            report($error);
            throw new HttpException(503, 'SCHEDULE_RECONCILIATION_REQUIRED');
        }

        return response()->json(['data' => [
            'pendingPriceVersionId' => $new->getKey(),
            'effectiveAt' => $subscription->period_end->toIso8601String(),
        ]]);
    }

    public function removePlanChange(Request $request, StripeCheckoutService $billing, StripeBillingGateway $stripe): JsonResponse
    {
        $subscription = BillingSubscription::query()->where('user_id', $request->user()->getKey())
            ->where('environment', $billing->environment())->whereNotNull('pending_price_id')->latest('id')->first();
        if (! $subscription) {
            throw new HttpException(409, 'NO_SCHEDULED_PLAN_CHANGE');
        }
        try {
            if ($subscription->stripe_schedule_id) {
                $stripe->releaseSchedule($subscription->stripe_schedule_id);
            }
            $subscription->forceFill(['stripe_schedule_id' => null, 'pending_price_id' => null])->save();
        } catch (Throwable $error) {
            report($error);
            throw new HttpException(503, 'SCHEDULE_RECONCILIATION_REQUIRED');
        }

        return response()->json(['data' => ['pendingPriceVersionId' => null]]);
    }

    public function cancel(Request $request, StripeCheckoutService $billing, StripeBillingGateway $stripe): JsonResponse
    {
        $subscription = BillingSubscription::query()->where('user_id', $request->user()->getKey())
            ->where('environment', $billing->environment())->whereIn('status', ['active', 'past_due'])->latest('id')->first();
        if (! $subscription) {
            throw new HttpException(409, 'NO_CANCELLABLE_SUBSCRIPTION');
        }
        if ($subscription->cancel_at_period_end) {
            return response()->json(['data' => ['cancelAtPeriodEnd' => true]]);
        }
        try {
            if ($subscription->stripe_schedule_id) {
                $stripe->releaseSchedule($subscription->stripe_schedule_id);
            }
            $updated = $stripe->cancelAtPeriodEnd($subscription->stripe_subscription_id);
            if (($updated['cancel_at_period_end'] ?? false) !== true) {
                throw new HttpException(503, 'CANCELLATION_UNCONFIRMED');
            }
            $subscription->forceFill(['stripe_schedule_id' => null, 'pending_price_id' => null, 'cancel_at_period_end' => true])->save();
        } catch (Throwable $error) {
            report($error);
            throw new HttpException(503, 'SUBSCRIPTION_RECONCILIATION_REQUIRED');
        }

        return response()->json(['data' => [
            'cancelAtPeriodEnd' => true, 'paidThrough' => $subscription->period_end?->toIso8601String(),
        ]]);
    }
}
