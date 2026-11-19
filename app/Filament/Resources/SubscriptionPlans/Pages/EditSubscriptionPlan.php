<?php

namespace App\Filament\Resources\SubscriptionPlans\Pages;

use App\Filament\Resources\SubscriptionPlans\Schemas\SubscriptionPlanForm;
use App\Filament\Resources\SubscriptionPlans\SubscriptionPlanResource;
use App\Filament\Support\CurrencyOfferPricing;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\SubscriptionCatalogService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditSubscriptionPlan extends EditRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...CurrencyOfferPricing::fieldValues($this->getRecord()->key)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): SubscriptionPlan {
            $plan = app(SubscriptionCatalogService::class)->savePlan(
                auth()->user(), $record, SubscriptionPlanForm::attributesForSave($data, $record instanceof SubscriptionPlan ? $record : null),
                'Updated subscription plan from admin'
            );
            if (SubscriptionPlanResource::canManagePrices()) {
                CurrencyOfferPricing::save(
                    auth()->user(), $plan->key, 'subscription', $plan->name,
                    (int) $plan->credits, (int) $plan->duration_months, $plan, $data
                );
            }

            return $plan;
        });
    }
}
