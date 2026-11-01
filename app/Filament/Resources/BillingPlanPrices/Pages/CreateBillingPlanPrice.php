<?php

namespace App\Filament\Resources\BillingPlanPrices\Pages;

use App\Filament\Resources\BillingPlanPrices\BillingPlanPriceResource;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBillingPlanPrice extends CreateRecord
{
    protected static string $resource = BillingPlanPriceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(StripeOfferCatalogService::class)->createDraft(auth()->user(), $data, $data['reason']);
    }
}
