<?php

namespace App\Filament\Resources\SubscriptionPlans;

use App\Filament\Resources\SubscriptionPlans\Pages\CreateSubscriptionPlan;
use App\Filament\Resources\SubscriptionPlans\Pages\EditSubscriptionPlan;
use App\Filament\Resources\SubscriptionPlans\Pages\ListSubscriptionPlans;
use App\Filament\Resources\SubscriptionPlans\Schemas\SubscriptionPlanForm;
use App\Models\SubscriptionPlan;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Subscriptions';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('billingOffers');
    }

    public static function form(Schema $schema): Schema
    {
        return SubscriptionPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('duration_months')->label('Duration')->suffix(' months'),
            TextColumn::make('credits')->label('Credits')
                ->state(fn (SubscriptionPlan $record): string => $record->billingOffers
                    ->whereNull('retired_at')->pluck('credits')->unique()->sort()->implode(', ') ?: '—'),
            IconColumn::make('is_active')->label('Active')->boolean(),
        ])->defaultSort('sort_order')->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptionPlans::route('/'), 'create' => CreateSubscriptionPlan::route('/create'), 'edit' => EditSubscriptionPlan::route('/{record}/edit')];
    }
}
