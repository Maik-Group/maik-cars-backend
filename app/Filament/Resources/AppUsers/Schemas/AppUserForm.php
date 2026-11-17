<?php

namespace App\Filament\Resources\AppUsers\Schemas;

use App\Enums\PreferredLanguage;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use App\Services\Subscriptions\ActiveBillingPlan;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class AppUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Subscription & credits')
                ->description('The current subscription and spendable credits, as displayed in the mobile app.')
                ->visible(fn (?User $record): bool => $record !== null && auth()->user()?->can('view_subscriptions') === true)
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Placeholder::make('current_subscription')->label('Subscription')
                        ->content(function (?User $record): string {
                            if (! $record || ! $record->is_active) {
                                return 'Inactive account';
                            }
                            $endsAt = ActiveBillingPlan::endsAt($record, (string) config('stripe_billing.environment'));

                            return $endsAt ? 'Active until '.$endsAt->utc()->format('Y-m-d H:i').' UTC' : 'No active subscription';
                        }),
                    Placeholder::make('available_credits')->label('Available credits')
                        ->content(fn (?User $record): string => $record
                            ? number_format(app(CreditWalletService::class)->balance($record)).' credits'
                            : '—'),
                ]),
            Section::make(__('admin.ui.app_users.account'))
                ->description(__('admin.ui.app_users.account_description'))
                ->columns(['default' => 1, 'md' => 2])
                ->components([
                    TextInput::make('name')
                        ->placeholder(__('admin.ui.app_users.name_placeholder'))
                        ->required()->maxLength(255),
                    TextInput::make('email')
                        ->placeholder('user@example.com')
                        ->required()->email()->unique(ignoreRecord: true)->maxLength(255),
                    TextInput::make('password')
                        ->placeholder(__('admin.ui.app_users.password_placeholder'))
                        ->password()->revealable()
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                        ->maxLength(255),
                    Toggle::make('is_active')->label(__('admin.ui.app_users.active'))->default(true),
                    Toggle::make('unlimited_devices')->label(__('admin.ui.app_users.unlimited_devices'))->default(false),
                    TextInput::make('discount_margin_percent')
                        ->label(__('admin.ui.app_users.discount_margin'))
                        ->numeric()->minValue(-100)->maxValue(4)->step(1)->default(0)
                        ->suffix('%')->helperText(__('admin.ui.app_users.discount_help')),
                    Select::make('preferred_language')
                        ->label(__('admin.ui.app_users.preferred_language'))
                        ->placeholder(__('admin.ui.app_users.select_language'))
                        ->options([
                            PreferredLanguage::EN->value => 'English',
                            PreferredLanguage::AR->value => 'Arabic',
                            PreferredLanguage::BG->value => 'Bulgarian',
                            PreferredLanguage::DE->value => 'German',
                            PreferredLanguage::HR->value => 'Croatian',
                            PreferredLanguage::HU->value => 'Hungarian (legacy)',
                        ])->default(PreferredLanguage::EN->value)->required(),
                ]),
        ]);
    }
}
