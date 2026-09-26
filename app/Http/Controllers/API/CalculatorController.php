<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\CalculatorEstimateRequest;
use App\Models\MetalPrice;
use App\Services\Mobile\CalculatorService;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CalculatorController extends Controller
{
    public function __construct(
        private readonly CalculatorService $calculatorService,
        private readonly MetalsSpotService $metalsSpotService,
    ) {}

    public function estimate(CalculatorEstimateRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            $metalPrice = $this->resolveMetalPrice();
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        $estimate = $this->calculatorService->estimate(
            weight: (float) $payload['weight'],
            ptPpm: (float) $payload['ptPpm'],
            pdPpm: (float) $payload['pdPpm'],
            rhPpm: (float) $payload['rhPpm'],
            metalPrice: $metalPrice,
            weightUnit: (string) ($payload['weightUnit'] ?? 'g'),
            ptUsdPerGram: isset($payload['ptUsdPerGram']) ? (float) $payload['ptUsdPerGram'] : null,
            pdUsdPerGram: isset($payload['pdUsdPerGram']) ? (float) $payload['pdUsdPerGram'] : null,
            rhUsdPerGram: isset($payload['rhUsdPerGram']) ? (float) $payload['rhUsdPerGram'] : null,
            ptRate: isset($payload['ptRate']) ? (float) $payload['ptRate'] : null,
            pdRate: isset($payload['pdRate']) ? (float) $payload['pdRate'] : null,
            rhRate: isset($payload['rhRate']) ? (float) $payload['rhRate'] : null,
            humidityRate: (float) ($payload['humidityRate'] ?? 0),
            currency: $request->currency(),
        );

        return response()->json([
            'estimate' => $estimate,
        ]);
    }

    private function resolveMetalPrice(): MetalPrice
    {
        $storedPrice = MetalPrice::query()->latest('fetched_at')->first();

        if ($storedPrice instanceof MetalPrice) {
            return $storedPrice;
        }

        $spot = $this->metalsSpotService->all('USD');
        $rows = collect($spot['data'] ?? [])->keyBy('key');

        $ptUsdPerOz = $this->spotPricePerOunce($rows->get('platinum'));
        $pdUsdPerOz = $this->spotPricePerOunce($rows->get('palladium'));
        $rhUsdPerOz = $this->spotPricePerOunce($rows->get('rhodium'));

        if ($ptUsdPerOz <= 0 || $pdUsdPerOz <= 0 || $rhUsdPerOz <= 0) {
            throw new RuntimeException(MetalsSpotService::UPSTREAM_UNAVAILABLE_MESSAGE);
        }

        return new MetalPrice([
            'pt_usd_per_oz' => $ptUsdPerOz,
            'pd_usd_per_oz' => $pdUsdPerOz,
            'rh_usd_per_oz' => $rhUsdPerOz,
            'source' => (string) ($spot['source'] ?? 'metals-spot'),
            'fetched_at' => now(),
        ]);
    }

    private function spotPricePerOunce(mixed $row): float
    {
        if (! is_array($row)) {
            return 0.0;
        }

        if (is_numeric($row['price_oz'] ?? null)) {
            return max((float) $row['price_oz'], 0.0);
        }

        if (is_numeric($row['price_gram'] ?? null)) {
            return max((float) $row['price_gram'] * 31.1043, 0.0);
        }

        return 0.0;
    }
}
