<?php

namespace App\Filament\Resources\SubscriptionPlans;

use App\Filament\Resources\SubscriptionPlans\Pages\CreateSubscriptionPlan;
use App\Filament\Resources\SubscriptionPlans\Pages\EditSubscriptionPlan;
use App\Filament\Resources\SubscriptionPlans\Pages\ListSubscriptionPlans;
use App\Filament\Resources\SubscriptionPlans\Schemas\SubscriptionPlanForm;
use App\Filament\Support\CurrencyOfferPricing;
use App\Models\SubscriptionPlan;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Subscription';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    public static function form(Schema $schema): Schema
    {
        return SubscriptionPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('duration_months')->label('Duration')->suffix(' months'),
            TextColumn::make('credits')->label('Credits')->numeric()->placeholder('—'),
            TextColumn::make('price_eur')->label('EUR price')
                ->state(fn (SubscriptionPlan $record): string => CurrencyOfferPricing::display($record->key, 'EUR')),
            TextColumn::make('price_usd')->label('USD price')
                ->state(fn (SubscriptionPlan $record): string => CurrencyOfferPricing::display($record->key, 'USD')),
            IconColumn::make('is_active')->label('Active')->boolean(),
        ])->defaultSort('sort_order')->recordActions([
            EditAction::make(),
            Action::make('publishEur')->label('Publish EUR')->icon('heroicon-o-check-badge')
                ->visible(fn (SubscriptionPlan $record): bool => static::canManagePrices()
                    && CurrencyOfferPricing::pending($record->key, 'EUR')?->stripe_price_id !== null)
                ->requiresConfirmation()
                ->action(fn (SubscriptionPlan $record) => CurrencyOfferPricing::publish(auth()->user(), $record->key, 'EUR')),
            Action::make('publishUsd')->label('Publish USD')->icon('heroicon-o-check-badge')
                ->visible(fn (SubscriptionPlan $record): bool => static::canManagePrices()
                    && CurrencyOfferPricing::pending($record->key, 'USD')?->stripe_price_id !== null)
                ->requiresConfirmation()
                ->action(fn (SubscriptionPlan $record) => CurrencyOfferPricing::publish(auth()->user(), $record->key, 'USD')),
        ]);
    }

    public static function canManagePrices(): bool
    {
        return auth()->user()?->can('manage_subscription_products') === true;
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptionPlans::route('/'), 'create' => CreateSubscriptionPlan::route('/create'), 'edit' => EditSubscriptionPlan::route('/{record}/edit')];
    }
}
