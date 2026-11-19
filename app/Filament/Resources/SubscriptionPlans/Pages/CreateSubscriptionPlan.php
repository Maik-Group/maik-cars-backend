<?php

namespace App\Filament\Resources\SubscriptionPlans\Pages;

use App\Filament\Resources\SubscriptionPlans\Schemas\SubscriptionPlanForm;
use App\Filament\Resources\SubscriptionPlans\SubscriptionPlanResource;
use App\Filament\Support\CurrencyOfferPricing;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\SubscriptionCatalogService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateSubscriptionPlan extends CreateRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): SubscriptionPlan {
            $plan = app(SubscriptionCatalogService::class)->savePlan(
                auth()->user(), null, SubscriptionPlanForm::attributesForSave($data, null), 'Created subscription plan from admin'
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
