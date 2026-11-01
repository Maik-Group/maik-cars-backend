<?php

namespace App\Http\Controllers\API;

use App\Exceptions\SubscriptionAccessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\ValuationRequest;
use App\Models\Item;
use App\Services\Mobile\ItemApiResponseService;
use App\Services\Mobile\ItemPriceService;
use App\Services\Subscriptions\DailyUsageService;
use App\Services\Subscriptions\EntitlementResolver;
use Illuminate\Http\JsonResponse;

class ItemValuationController extends Controller
{
    public function __invoke(ValuationRequest $request, Item $item, DailyUsageService $usage, EntitlementResolver $entitlements): JsonResponse
    {
        abort_unless($item->isApiVisible(), 404);
        $user = $request->user();
        if (! $user->is_active) {
            throw new SubscriptionAccessException('ACCOUNT_INACTIVE', 403);
        }
        $currency = $request->validated('currency', 'USD');
        $responses = app(ItemApiResponseService::class);
        if ($responses->uniqueModeEnabled()) {
            $responses->applyAveragePrices(collect([$item]), $currency);
        }
        $basePrice = $item->getAttribute('api_average_price') ?? app(ItemPriceService::class)->priceFor($item, $currency);
        if (! is_finite($basePrice) || $basePrice <= 0) {
            throw new SubscriptionAccessException('VALUATION_UNAVAILABLE', 503);
        }

        $authorization = $entitlements->enforcementEnabled()
            ? $usage->claim($user, $item, $request->validated('revalidate_date'))
            : ['usage' => ['limit' => null, 'used' => 0, 'remaining' => null, 'counted' => false, 'usage_date' => now('UTC')->toDateString(), 'resets_at' => now('UTC')->addDay()->startOfDay()->toIso8601String()], 'valid_until' => now('UTC')->addSeconds(60)->min(now('UTC')->addDay()->startOfDay())->toIso8601String()];

        return response()->json([
            'data' => ['item_id' => $item->getKey(), 'price' => $user->applyDiscountMargin($basePrice), 'base_price' => round($basePrice, 2), 'currency' => $currency,
                'weight_kg' => $item->weight_kg, 'pt_ppm' => $item->pt_ppm, 'pd_ppm' => $item->pd_ppm, 'rh_ppm' => $item->rh_ppm],
            ...$authorization,
        ])->header('Cache-Control', 'private, no-store');
    }
}
