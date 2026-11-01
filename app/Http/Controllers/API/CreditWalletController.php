<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CreditGrant;
use App\Models\CreditLedgerEntry;
use App\Services\Credits\CreditWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditWalletController extends Controller
{
    public function show(Request $request, CreditWalletService $wallet): JsonResponse
    {
        return response()->json(['data' => [
            'balance' => $wallet->balance($request->user()),
            'grants' => CreditGrant::query()->where('user_id', $request->user()->getKey())
                ->where('environment', config('stripe_billing.environment'))
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
