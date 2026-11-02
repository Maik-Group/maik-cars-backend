<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\ValuationRequest;
use App\Models\Item;
use App\Services\Credits\CreditWalletService;
use App\Services\Mobile\ItemApiResponseService;
use App\Services\Mobile\ItemPriceService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreditValuationController extends Controller
{
    public function __invoke(ValuationRequest $request, Item $item, CreditWalletService $wallet): JsonResponse
    {
        abort_unless($request->user()->is_active, 403);
        abort_unless($item->isApiVisible(), 404);
        $currency = $request->validated('currency', 'USD');
        $responseService = app(ItemApiResponseService::class);
        if ($responseService->uniqueModeEnabled()) {
            $responseService->applyAveragePrices(collect([$item]), $currency);
        }
        $basePrice = $item->getAttribute('api_average_price') ?? app(ItemPriceService::class)->priceFor($item, $currency);
        abort_unless(is_finite($basePrice) && $basePrice > 0, 503, 'VALUATION_UNAVAILABLE');
        $now = now('UTC');
        $viewDate = $now->toDateString();
        $revalidateDate = $request->validated('revalidate_date');
        if ($revalidateDate !== null && $revalidateDate !== $viewDate) {
            throw new HttpException(409, 'REVEAL_REQUIRED');
        }
        $receipt = $wallet->spend($request->user(), $item, null, $revalidateDate !== null,
            $request->header('Idempotency-Key'));
        $lease = $now->copy()->addSeconds(90)->min($now->copy()->addDay()->startOfDay())->toIso8601String();

        return response()->json([
            'data' => [
                'item_id' => $item->getKey(), 'price' => $request->user()->applyDiscountMargin($basePrice),
                'base_price' => round($basePrice, 2), 'currency' => $currency,
                'weight_kg' => $item->weight_kg, 'pt_ppm' => $item->pt_ppm,
                'pd_ppm' => $item->pd_ppm, 'rh_ppm' => $item->rh_ppm,
            ],
            'wallet' => $receipt,
            'access' => [
                'receiptId' => $receipt['receipt_id'], 'chargedCredits' => $receipt['counted'] ? 1 : 0,
                'viewDateUtc' => $viewDate, 'validUntil' => $lease,
            ],
            'valid_until' => $lease,
        ])->header('Cache-Control', 'private, no-store');
    }
}
