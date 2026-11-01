<?php

namespace App\Filament\Resources\SubscriptionPlans\RelationManagers;

use App\Models\PlanStoreProduct;
use App\Services\Subscriptions\SubscriptionCatalogService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StoreProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'storeProducts';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('platform'), TextColumn::make('environment'), TextColumn::make('product_id'), TextColumn::make('base_plan_id'),
            TextColumn::make('validation_state')->badge(), TextColumn::make('plan_revision'), TextColumn::make('checked_at')->dateTime(), TextColumn::make('mismatch_reason'),
        ])->headerActions([
            CreateAction::make()->visible(fn () => auth()->user()->can('manage_subscription_products'))->schema([
                Select::make('platform')->options(['ios' => 'Apple', 'android' => 'Google'])->required(),
                Select::make('environment')->options(['sandbox' => 'Sandbox / license testing', 'production' => 'Production'])->required(),
                TextInput::make('product_id')->required()->maxLength(180), TextInput::make('base_plan_id')->maxLength(80)->helperText('Required for Google. Leave empty for Apple.'),
                Textarea::make('reason')->required()->maxLength(255),
            ])->using(fn (array $data) => app(SubscriptionCatalogService::class)->createMapping(auth()->user(), $this->getOwnerRecord(), $data, $data['reason'])),
        ])->recordActions([
            Action::make('verifyMapping')->label('Verify with store')->visible(fn () => auth()->user()->can('manage_subscription_products'))
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(function (PlanStoreProduct $record, array $data): void {
                    app(SubscriptionCatalogService::class)->verifyMapping(auth()->user(), $record, $data['reason']);
                    Notification::make()->title('Mapping status: '.$record->fresh()->validation_state)->send();
                }),
        ]);
    }
}
