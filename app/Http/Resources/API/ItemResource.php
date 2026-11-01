<?php

namespace App\Http\Resources\API;

use App\Models\Item;
use App\Services\Mobile\ItemPriceService;
use App\Services\Subscriptions\EntitlementResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = strtoupper((string) $request->query('currency', 'USD'));
        $restricted = app(EntitlementResolver::class)->enforcementEnabled();
        $averagePrice = $this->resource->getAttribute('api_average_price');
        $basePrice = $restricted ? 0.0 : (is_numeric($averagePrice)
            ? (float) $averagePrice
            : app(ItemPriceService::class)->priceFor($this->resource, $currency));

        $user = $request->user('sanctum');
        $discountMarginPercent = $user?->discountMarginPercent() ?? 0.0;
        $discountPercent = max(-$discountMarginPercent, 0.0);
        $price = $user?->applyDiscountMargin($basePrice) ?? round($basePrice, 2);

        return [
            'id' => $this->id,
            'model' => $this->model,
            'serial_code' => $this->normalized_serial,
            'weight_kg' => $restricted ? null : $this->weight_kg,
            'pt_ppm' => $restricted ? null : $this->pt_ppm,
            'pd_ppm' => $restricted ? null : $this->pd_ppm,
            'rh_ppm' => $restricted ? null : $this->rh_ppm,
            'access' => ['valuation_required' => $restricted],
            'shape_code' => $this->shape_code,
            'details' => $this->details,
            'car_group' => CarGroupResource::make($this->whenLoaded('carGroup')),
            'extra_codes' => $this->whenLoaded('extraCodes', fn () => $this->extraCodes->pluck('code')->values()),
            'image_url' => $this->image_url,
            'image_thumb_url' => $this->image_thumb_url,
            'image_detail_url' => $this->image_detail_url,
            'saved_item' => (bool) ($this->saved_item ?? false),
            'base_price' => $restricted ? null : round($basePrice, 2),
            'price' => $restricted ? null : round($price, 2),
            'discount_percent' => round($discountPercent, 1),
            'price_adjustment_percent' => round($discountMarginPercent, 1),
        ];
    }
}
