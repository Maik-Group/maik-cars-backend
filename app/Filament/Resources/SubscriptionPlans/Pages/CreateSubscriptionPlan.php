<?php

namespace App\Filament\Resources\SubscriptionPlans\Pages;

use App\Filament\Resources\SubscriptionPlans\Schemas\SubscriptionPlanForm;
use App\Filament\Resources\SubscriptionPlans\SubscriptionPlanResource;
use App\Services\Subscriptions\SubscriptionCatalogService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSubscriptionPlan extends CreateRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SubscriptionCatalogService::class)->savePlan(
            auth()->user(), null, SubscriptionPlanForm::attributesForSave($data, null), 'Created subscription plan from admin'
        );
    }
}
