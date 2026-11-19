<?php

namespace App\Filament\Resources\BillingPlanPrices;

use App\Filament\Resources\BillingPlanPrices\Pages\CreateBillingPlanPrice;
use App\Filament\Resources\BillingPlanPrices\Pages\ListBillingPlanPrices;
use App\Filament\Support\CurrencyOfferPricing;
use App\Models\BillingPlanPrice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    public static function getEloquentQuery(): Builder
    {
        // One row per package, not one row per currency or historical revision.
        return parent::getEloquentQuery()->where('kind', 'topup')
            ->where('environment', config('stripe_billing.environment'))
            ->whereRaw('billing_plan_prices.id = (
                SELECT b2.id FROM billing_plan_prices AS b2
                WHERE b2.environment = billing_plan_prices.environment
                AND b2.offer_key = billing_plan_prices.offer_key
                AND b2.kind = billing_plan_prices.kind
                ORDER BY b2.revision DESC, b2.id DESC LIMIT 1
            )');
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
            TextColumn::make('price_eur')->label('EUR price')
                ->state(fn (BillingPlanPrice $record): string => CurrencyOfferPricing::display($record->offer_key, 'EUR')),
            TextColumn::make('price_usd')->label('USD price')
                ->state(fn (BillingPlanPrice $record): string => CurrencyOfferPricing::display($record->offer_key, 'USD')),
            TextColumn::make('package_status')->label('Status')
                ->state(fn (BillingPlanPrice $record): string => self::hasActivePublished($record->offer_key)
                    ? 'Available' : (CurrencyOfferPricing::pending($record->offer_key, 'EUR') || CurrencyOfferPricing::pending($record->offer_key, 'USD') ? 'Draft' : 'Retired'))
                ->badge(),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('editPrices')->label('Edit')->icon('heroicon-o-pencil-square')
                ->fillForm(fn (BillingPlanPrice $record): array => [
                    'name' => $record->name,
                    'credits' => $record->credits,
                    ...CurrencyOfferPricing::fieldValues($record->offer_key),
                ])->form([
                    Section::make('Package details')->columns(2)->schema([
                        TextInput::make('name')->label('Package name')->required()->maxLength(255),
                        TextInput::make('credits')->label('Credits')->required()->integer()->minValue(1),
                    ]),
                    CurrencyOfferPricing::section(),
                ])
                ->action(function (BillingPlanPrice $record, array $data): void {
                    CurrencyOfferPricing::save(
                        auth()->user(), $record->offer_key, 'topup',
                        (string) $data['name'], (int) $data['credits'], null, null, $data
                    );
                }),
            ActionGroup::make([
                Action::make('publishEur')->label('Publish EUR')->icon('heroicon-o-check-badge')
                    ->visible(fn (BillingPlanPrice $record): bool => CurrencyOfferPricing::pending($record->offer_key, 'EUR')?->stripe_price_id !== null)
                    ->requiresConfirmation()
                    ->action(fn (BillingPlanPrice $record) => CurrencyOfferPricing::publish(auth()->user(), $record->offer_key, 'EUR')),
                Action::make('publishUsd')->label('Publish USD')->icon('heroicon-o-check-badge')
                    ->visible(fn (BillingPlanPrice $record): bool => CurrencyOfferPricing::pending($record->offer_key, 'USD')?->stripe_price_id !== null)
                    ->requiresConfirmation()
                    ->action(fn (BillingPlanPrice $record) => CurrencyOfferPricing::publish(auth()->user(), $record->offer_key, 'USD')),
                Action::make('retireEur')->label('Retire EUR')->color('danger')->requiresConfirmation()
                    ->visible(fn (BillingPlanPrice $record): bool => self::activePublished($record->offer_key, 'EUR') !== null)
                    ->action(fn (BillingPlanPrice $record) => CurrencyOfferPricing::retireCurrency(
                        auth()->user(), $record->offer_key, 'EUR'
                    )),
                Action::make('retireUsd')->label('Retire USD')->color('danger')->requiresConfirmation()
                    ->visible(fn (BillingPlanPrice $record): bool => self::activePublished($record->offer_key, 'USD') !== null)
                    ->action(fn (BillingPlanPrice $record) => CurrencyOfferPricing::retireCurrency(
                        auth()->user(), $record->offer_key, 'USD'
                    )),
            ])->label('More')->icon('heroicon-o-ellipsis-horizontal'),
        ]);
    }

    private static function hasActivePublished(string $key): bool
    {
        return self::activePublished($key, 'EUR') !== null || self::activePublished($key, 'USD') !== null;
    }

    private static function activePublished(string $key, string $currency): ?BillingPlanPrice
    {
        return BillingPlanPrice::query()->where('environment', config('stripe_billing.environment'))
            ->where('kind', 'topup')->where('offer_key', $key)->where('currency', $currency)
            ->whereNotNull('published_at')->whereNull('retired_at')
            ->orderByDesc('revision')->first();
    }

    public static function getPages(): array
    {
        return ['index' => ListBillingPlanPrices::route('/'), 'create' => CreateBillingPlanPrice::route('/create')];
    }
}
