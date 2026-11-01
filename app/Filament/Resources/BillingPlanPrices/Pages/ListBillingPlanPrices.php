<?php

namespace App\Filament\Resources\BillingPlanPrices\Pages;

use App\Filament\Resources\BillingPlanPrices\BillingPlanPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBillingPlanPrices extends ListRecords
{
    protected static string $resource = BillingPlanPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
