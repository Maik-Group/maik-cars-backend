<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CreditGrant;
use App\Models\CreditLedgerEntry;
use App\Models\PaidSearchSession;
use App\Services\Credits\CreditWalletService;
use App\Services\Subscriptions\ActiveBillingPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CreditWalletController extends Controller
{
    public function show(Request $request, CreditWalletService $wallet): JsonResponse
    {
        $environment = config('stripe_billing.environment');
        // Keep legacy wallet reads working during a staged code-first rollout.
        $activeSearchUntil = Schema::hasTable('paid_search_sessions')
            ? PaidSearchSession::query()
                ->join('credit_consumptions as search_consumption', 'search_consumption.id', '=', 'paid_search_sessions.credit_consumption_id')
                ->join('credit_grants as search_grant', 'search_grant.id', '=', 'search_consumption.credit_grant_id')
                ->where('paid_search_sessions.user_id', $request->user()->getKey())
                ->where('paid_search_sessions.environment', $environment)
                ->where('paid_search_sessions.charged', true)
                ->where('paid_search_sessions.expires_at', '>', now('UTC'))
                ->whereNull('search_grant.revoked_at')->whereNull('search_grant.held_at')
                ->when(! ActiveBillingPlan::endsAt($request->user(), (string) $environment),
                    fn ($query) => $query->whereIn('search_grant.source_type', CreditGrant::FREE_SOURCES))
                ->max('paid_search_sessions.expires_at')
            : null;

        return response()->json(['data' => [
            'balance' => $wallet->balance($request->user()),
            'activeSearchUntil' => $activeSearchUntil
                ? CarbonImmutable::parse($activeSearchUntil, 'UTC')->toIso8601String()
                : null,
            'grants' => CreditGrant::query()->where('user_id', $request->user()->getKey())
                ->where('environment', $environment)
                ->latest('id')->limit(100)->get(['id', 'source_type', 'credits', 'remaining', 'expires_at', 'revoked_at', 'held_at']),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function transactions(Request $request): JsonResponse
    {
        return response()->json(CreditLedgerEntry::query()->where('user_id', $request->user()->getKey())
            ->whereHas('grant', fn ($query) => $query->where('environment', config('stripe_billing.environment')))
            ->orderByDesc('id')->paginate(30))->header('Cache-Control', 'private, no-store');
    }
}
