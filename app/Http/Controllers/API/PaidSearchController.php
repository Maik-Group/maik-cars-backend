<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\API\ItemResource;
use App\Models\Item;
use App\Services\Credits\CreditWalletService;
use App\Services\Credits\PaidSearchService;
use App\Services\Mobile\ItemApiResponseService;
use App\Services\Mobile\ItemPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaidSearchController extends Controller
{
    public function __construct(
        private readonly PaidSearchService $searches,
        private readonly CreditWalletService $wallet,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $values = $request->validate([
            'text' => ['required', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $session = $this->searches->start(
            $request->user(), $values['text'], (string) $request->header('Idempotency-Key', ''),
        );

        return $this->response(
            $session, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10),
            $request->user(),
        );
    }

    public function page(Request $request, string $searchId): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $session = $this->searches->authorized($request->user(), $searchId);

        return $this->response(
            $session, (int) ($validated['page'] ?? 1), (int) ($validated['per_page'] ?? 10),
            $request->user(),
        );
    }

    public function valuation(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'search_id' => ['required', 'uuid'],
            'currency' => ['sometimes', 'in:USD,EUR'],
        ]);
        $user = $request->user();
        $session = $this->searches->authorized($user, $data['search_id']);
        abort_unless($item->isApiVisible(), 404);
        if (! $this->searches->allowsItem($session, $item)) {
            throw new HttpException(403, 'ITEM_NOT_IN_PAID_SEARCH');
        }

        $currency = $data['currency'] ?? 'USD';
        $responses = app(ItemApiResponseService::class);
        if ($responses->uniqueModeEnabled()) {
            $responses->applyAveragePrices(collect([$item]), $currency);
        }
        $base = $item->getAttribute('api_average_price') ?? app(ItemPriceService::class)->priceFor($item, $currency);
        if (! is_finite($base) || $base <= 0) {
            throw new HttpException(503, 'VALUATION_UNAVAILABLE');
        }
        $expires = now('UTC')->addSeconds(90)->min($session->expires_at);

        return response()->json([
            'data' => [
                'itemId' => $item->getKey(), 'price' => $user->applyDiscountMargin($base),
                'basePrice' => round($base, 2), 'currency' => $currency,
                'weightKg' => $item->weight_kg, 'ptPpm' => $item->pt_ppm,
                'pdPpm' => $item->pd_ppm, 'rhPpm' => $item->rh_ppm,
            ],
            'access' => ['chargedCredits' => 0, 'searchId' => $session->getKey()],
            'validUntil' => $expires->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function response($session, int $page, int $perPage, $user): JsonResponse
    {
        /** @var LengthAwarePaginator $items */
        $items = $this->searches->page($session, $page, $perPage);

        return response()->json([
            'searchId' => $session->charged ? $session->getKey() : null,
            'searchExpiresAt' => $session->charged ? $session->expires_at->toIso8601String() : null,
            'chargedCredits' => $session->charged ? 1 : 0,
            'data' => ItemResource::collection($items->getCollection())->resolve(),
            'links' => [
                'first' => $items->url(1), 'last' => $items->url($items->lastPage()),
                'prev' => $items->previousPageUrl(), 'next' => $items->nextPageUrl(),
            ],
            'meta' => [
                'currentPage' => $items->currentPage(), 'from' => $items->firstItem(),
                'lastPage' => $items->lastPage(), 'perPage' => $items->perPage(),
                'to' => $items->lastItem(), 'total' => $items->total(),
            ],
            'wallet' => ['remaining' => $this->wallet->balance($user)],
        ])->header('Cache-Control', 'private, no-store');
    }
}
