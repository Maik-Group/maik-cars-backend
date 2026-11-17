<?php

namespace App\Filament\Resources\BillingPlanPrices\Pages;

use App\Filament\Resources\BillingPlanPrices\BillingPlanPriceResource;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class CreateBillingPlanPrice extends CreateRecord
{
    protected static string $resource = BillingPlanPriceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        Validator::make($data, [
            'price' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999'],
        ])->validate();

        $attributes = [
            'offer_key' => $data['offer_key'],
            'name' => $data['name'],
            'revision' => 1,
            'environment' => $data['environment'],
            'kind' => 'topup',
            'subscription_plan_id' => null,
            'duration_months' => null,
            'credits' => $data['credits'],
            'currency' => $data['currency'],
            'amount_minor' => (int) round((float) $data['price'] * 100),
            'terms_version' => 'v1',
            'stripe_product_id' => $data['stripe_product_id'],
            'stripe_price_id' => $data['stripe_price_id'],
        ];

        return app(StripeOfferCatalogService::class)->createDraft(
            auth()->user(), $attributes, 'Created credit package from admin'
        );
    }
}
