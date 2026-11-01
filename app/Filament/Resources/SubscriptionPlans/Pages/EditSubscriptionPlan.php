<?php

namespace App\Filament\Resources\SubscriptionPlans\Pages;

use App\Filament\Resources\SubscriptionPlans\SubscriptionPlanResource;
use App\Services\Subscriptions\SubscriptionCatalogService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSubscriptionPlan extends EditRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SubscriptionCatalogService::class)->savePlan(auth()->user(), $record, $data, $data['reason']);
    }
}
