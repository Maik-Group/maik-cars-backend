<?php

namespace App\Filament\Resources\BillingPlanPrices;

use App\Filament\Resources\BillingPlanPrices\Pages\CreateBillingPlanPrice;
use App\Filament\Resources\BillingPlanPrices\Pages\ListBillingPlanPrices;
use App\Models\BillingPlanPrice;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BillingPlanPriceResource extends Resource
{
    protected static ?string $model = BillingPlanPrice::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Subscriptions';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage_subscription_products') === true;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('offer_key')->required()->maxLength(100),
            TextInput::make('revision')->numeric()->integer()->minValue(1)->default(1)->required(),
            Select::make('environment')->options(['sandbox' => 'Sandbox', 'production' => 'Production'])->required(),
            Select::make('kind')->options(['subscription' => 'Subscription', 'topup' => 'Top-up'])->required()->live(),
            Select::make('subscription_plan_id')->relationship('subscriptionPlan', 'name')->searchable(),
            TextInput::make('name')->required(),
            Select::make('duration_months')->options([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'])->visible(fn ($get) => $get('kind') === 'subscription'),
            TextInput::make('credits')->numeric()->integer()->minValue(1)->required(),
            TextInput::make('currency')->default('USD')->required()->maxLength(3),
            TextInput::make('amount_minor')->numeric()->integer()->minValue(1)->required(),
            TextInput::make('terms_version')->required()->maxLength(40),
            TextInput::make('stripe_product_id'),
            TextInput::make('stripe_price_id'),
            Textarea::make('reason')->required()->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('offer_key')->searchable(), TextColumn::make('revision'),
            TextColumn::make('environment'), TextColumn::make('kind'), TextColumn::make('name')->searchable(),
            TextColumn::make('credits'), TextColumn::make('amount_minor'), TextColumn::make('currency'),
            TextColumn::make('verified_at')->dateTime(), TextColumn::make('published_at')->dateTime(),
            TextColumn::make('retired_at')->dateTime(),
        ])->recordActions([
            Action::make('publish')->label('Verify Stripe and publish')->visible(fn (BillingPlanPrice $record) => ! $record->published_at && static::canViewAny())
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (BillingPlanPrice $record, array $data) => app(StripeOfferCatalogService::class)->verifyAndPublish(auth()->user(), $record, $data['reason'], app(StripeBillingGateway::class))),
            Action::make('retire')->label('Retire')->color('danger')->requiresConfirmation()
                ->visible(fn (BillingPlanPrice $record) => $record->published_at && ! $record->retired_at && static::canViewAny())
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (BillingPlanPrice $record, array $data) => app(StripeOfferCatalogService::class)->retire(auth()->user(), $record, $data['reason'])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBillingPlanPrices::route('/'), 'create' => CreateBillingPlanPrice::route('/create')];
    }
}
