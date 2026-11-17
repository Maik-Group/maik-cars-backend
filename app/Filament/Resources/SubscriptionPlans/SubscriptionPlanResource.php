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

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Subscription';

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
            IconColumn::make('is_active')->label('Active')->boolean(),
        ])->defaultSort('sort_order')->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptionPlans::route('/'), 'create' => CreateSubscriptionPlan::route('/create'), 'edit' => EditSubscriptionPlan::route('/{record}/edit')];
    }
}
