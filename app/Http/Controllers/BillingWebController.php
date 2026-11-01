<?php

namespace App\Http\Controllers;

use App\Models\BillingPlanPrice;
use App\Models\User;
use App\Services\Subscriptions\StripeCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingWebController extends Controller
{
    public function issue(Request $request, StripeCheckoutService $billing): JsonResponse
    {
        abort_unless($request->user()?->is_active, 403);
        $environment = $billing->environment();
        abort_unless($environment === 'sandbox', 403, 'WEB_HANDOFF_NOT_APPROVED');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['subscription', 'topup'])],
            'priceVersionId' => ['required', 'integer', 'min:1'],
        ]);
        $offer = BillingPlanPrice::query()->whereKey($data['priceVersionId'])
            ->where('environment', $environment)->where('kind', $data['kind'])->first();
        abort_unless($offer?->checkoutEligible(), 409, 'OFFER_CHANGED');
        $token = bin2hex(random_bytes(32));
        DB::table('billing_web_handoffs')->insert([
            'user_id' => $request->user()->getKey(),
            'token_hash' => hash('sha256', $token),
            'environment' => $environment,
            'kind' => $data['kind'],
            'billing_plan_price_id' => $offer->getKey(),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'url' => route('billing.web.handoff', ['token' => $token]),
            'expiresAt' => now()->addMinutes(5)->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }

    public function consume(Request $request, StripeCheckoutService $billing): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 403);
        $environment = $billing->environment();
        abort_unless($environment === 'sandbox', 403, 'WEB_HANDOFF_NOT_APPROVED');
        $handoffData = DB::transaction(function () use ($token, $environment): ?array {
            $handoff = DB::table('billing_web_handoffs')
                ->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $handoff || $handoff->environment !== $environment
                || $handoff->consumed_at !== null || now()->greaterThanOrEqualTo($handoff->expires_at)) {
                return null;
            }
            $user = User::query()->whereKey($handoff->user_id)->where('is_active', true)->first();
            if (! $user) {
                return null;
            }
            DB::table('billing_web_handoffs')->where('id', $handoff->id)->update(['consumed_at' => now()]);

            return ['user_id' => (int) $user->getKey(), 'kind' => $handoff->kind,
                'priceVersionId' => (int) $handoff->billing_plan_price_id];
        });
        abort_unless($handoffData, 403);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        auth('web')->loginUsingId($handoffData['user_id']);
        $request->session()->regenerate();

        return redirect()->route('billing.web.shop', [
            'kind' => $handoffData['kind'], 'priceVersionId' => $handoffData['priceVersionId'],
        ])->header('Cache-Control', 'no-store');
    }

    public function shop(Request $request, StripeCheckoutService $billing)
    {
        abort_unless($request->user()?->is_active, 403);
        abort_unless($billing->environment() === 'sandbox', 403, 'WEB_CHECKOUT_NOT_APPROVED');
        $catalog = $billing->catalog();

        $selectedKind = (string) $request->query('kind', '');
        $selectedPrice = (int) $request->query('priceVersionId', 0);
        return response()->view('billing.shop', compact('catalog', 'selectedKind', 'selectedPrice'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function checkout(Request $request, StripeCheckoutService $billing): RedirectResponse
    {
        abort_unless($request->user()?->is_active, 403);
        abort_unless($billing->environment() === 'sandbox', 403, 'WEB_CHECKOUT_NOT_APPROVED');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['subscription', 'topup'])],
            'priceVersionId' => ['required', 'integer', 'min:1'],
        ]);
        $order = $billing->begin($request->user(), $data['kind'], (int) $data['priceVersionId'],
            'web', (string) Str::uuid());

        return redirect()->away($order->checkout_url)->header('Cache-Control', 'no-store');
    }
}
