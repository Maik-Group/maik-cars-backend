<?php

namespace App\Filament\Resources\BillingPlanPrices;

use App\Filament\Resources\BillingPlanPrices\Pages\CreateBillingPlanPrice;
use App\Filament\Resources\BillingPlanPrices\Pages\ListBillingPlanPrices;
use App\Models\BillingPlanPrice;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BillingPlanPriceResource extends Resource
{
    protected static ?string $model = BillingPlanPrice::class;

    protected static ?string $slug = 'credit-packages';

    protected static ?string $navigationLabel = 'Credit Packages';

    protected static ?string $modelLabel = 'Credit Package';

    protected static ?string $pluralModelLabel = 'Credit Packages';

    protected static string|\UnitEnum|null $navigationGroup = 'Subscription';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', 'topup')
            ->where('environment', config('stripe_billing.environment'));
    }

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
            Section::make('Credit package')->description('Create a one-time credit purchase. The price must match the Stripe one-time price.')
                ->columns(2)->schema([
                    TextInput::make('name')->label('Package name')->required()->maxLength(255),
                    TextInput::make('offer_key')->label('Package code')->required()->alphaDash()->maxLength(100),
                    TextInput::make('credits')->label('Number of credits')->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('price')->label('Price')->numeric()->step(0.01)->minValue(0.01)->required(),
                    Select::make('currency')->options(['EUR' => 'EUR (€)', 'USD' => 'USD ($)'])->default('EUR')->required(),
                ]),
            Section::make('Stripe checkout')->description('Use the IDs of a one-time Stripe product and price. Publishing verifies the amount and currency.')
                ->columns(2)->schema([
                    TextInput::make('stripe_product_id')->label('Stripe Product ID')->required()->maxLength(255),
                    TextInput::make('stripe_price_id')->label('Stripe Price ID')->required()->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Package')->searchable(),
            TextColumn::make('credits')->label('Credits')->numeric()->sortable(),
            TextColumn::make('amount_minor')->label('Price')
                ->formatStateUsing(fn ($state, BillingPlanPrice $record): string => number_format((int) $state / 100, 2).' '.$record->currency),
            TextColumn::make('package_status')->label('Status')
                ->state(fn (BillingPlanPrice $record): string => $record->retired_at ? 'Retired' : ($record->checkoutEligible() ? 'Available' : 'Draft'))
                ->badge(),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('publish')->label('Verify Stripe and publish')
                ->visible(fn (BillingPlanPrice $record) => ! $record->published_at && static::canViewAny())
                ->requiresConfirmation()
                ->action(fn (BillingPlanPrice $record) => app(StripeOfferCatalogService::class)->verifyAndPublish(
                    auth()->user(), $record, 'Published credit package from admin', app(StripeBillingGateway::class)
                )),
            Action::make('retire')->label('Retire package')->color('danger')->requiresConfirmation()
                ->visible(fn (BillingPlanPrice $record) => $record->published_at && ! $record->retired_at && static::canViewAny())
                ->action(fn (BillingPlanPrice $record) => app(StripeOfferCatalogService::class)->retire(
                    auth()->user(), $record, 'Retired credit package from admin'
                )),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBillingPlanPrices::route('/'), 'create' => CreateBillingPlanPrice::route('/create')];
    }
}
