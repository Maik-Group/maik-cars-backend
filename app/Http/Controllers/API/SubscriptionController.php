<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\BillingAgreement;
use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\BillingAccountService;
use App\Services\Subscriptions\DailyUsageService;
use App\Services\Subscriptions\EntitlementResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function plans(Request $request): JsonResponse
    {
        $validated = $request->validate(['platform' => ['required', 'in:ios,android']]);
        $environment = $request->user('sanctum')
            ? app(EntitlementResolver::class)->environment($request->user('sanctum')) : config('subscriptions.environment');
        $plans = SubscriptionPlan::query()->where('is_active', true)->where('is_visible', true)
            ->with(['storeProducts' => fn ($query) => $query->where('platform', $validated['platform'])->where('environment', $environment)->where('validation_state', 'verified')])
            ->orderBy('sort_order')->get();

        return response()->json(['data' => $plans->filter(fn ($plan) => $plan->storeProducts->isNotEmpty())->map(fn ($plan) => [
            'id' => $plan->getKey(), 'name' => $plan->name, 'tier_key' => $plan->tier_key, 'duration_months' => $plan->duration_months,
            'daily_allowance' => $plan->daily_allowance, 'features' => $plan->features, 'descriptions' => $plan->descriptions,
            'products' => $plan->storeProducts->where('plan_revision', $plan->revision)->map(fn ($product) => $product->only(['product_id', 'base_plan_id']))->values(),
        ])->filter(fn ($plan) => $plan['products']->isNotEmpty())->values()]);
    }

    public function status(Request $request, EntitlementResolver $entitlements, DailyUsageService $usage, BillingAccountService $accounts): JsonResponse
    {
        $user = $request->user();
        $access = $entitlements->resolve($user);

        return response()->json(['data' => [
            'active' => $access['active'], 'enforcement_enabled' => $entitlements->enforcementEnabled(),
            'purchases_enabled' => config('subscriptions.purchases_enabled'), 'environment' => $entitlements->environment($user),
            'terms_url' => config('subscriptions.terms_url'),
            'sources' => $access['sources']->map(fn ($source) => [
                ...$source->only(['id', 'source', 'starts_at', 'ends_at', 'daily_allowance']),
                'provider' => $source->agreement?->provider, 'billing_state' => $source->agreement?->provider_state,
                'auto_renews' => $source->agreement?->auto_renews,
            ]),
            'usage' => $usage->status($user, $access), 'account_binding' => $accounts->identifiers($user),
            'history' => SubscriptionEntitlement::query()->where('user_id', $user->getKey())->where('environment', $entitlements->environment($user))
                ->latest('id')->limit(50)->get(['id', 'source', 'starts_at', 'ends_at', 'revoked_at', 'daily_allowance']),
            'verifications' => SubscriptionEvent::query()->where('user_id', $user->getKey())->latest('id')->limit(20)->get(['correlation_id', 'state', 'error_code', 'billing_agreement_id']),
            'agreements' => BillingAgreement::query()->where('user_id', $user->getKey())->where('environment', $entitlements->environment($user))
                ->latest('id')->limit(50)->get(['id', 'provider', 'provider_state', 'auto_renews', 'acknowledgement_pending', 'suppressed_at']),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function manage(): JsonResponse
    {
        return response()->json(['data' => [
            'apple' => 'https://apps.apple.com/account/subscriptions',
            'google' => 'https://play.google.com/store/account/subscriptions',
            'support' => config('subscriptions.support_url'),
        ]]);
    }
}
