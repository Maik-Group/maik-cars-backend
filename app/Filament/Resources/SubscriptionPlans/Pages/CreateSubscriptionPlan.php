<?php

namespace App\Filament\Resources\SubscriptionPlans\Pages;

use App\Filament\Resources\SubscriptionPlans\SubscriptionPlanResource;
use App\Services\Subscriptions\SubscriptionCatalogService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSubscriptionPlan extends CreateRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SubscriptionCatalogService::class)->savePlan(auth()->user(), null, $data, $data['reason']);
    }
}
