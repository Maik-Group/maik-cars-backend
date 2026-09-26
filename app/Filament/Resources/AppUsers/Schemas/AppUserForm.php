<?php

namespace App\Filament\Resources\AppUsers\Schemas;

use App\Enums\PreferredLanguage;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;

class AppUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.ui.app_users.account'))
                    ->description(__('admin.ui.app_users.account_description'))
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->components([
                        TextInput::make('name')
                            ->placeholder(__('admin.ui.app_users.name_placeholder'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->placeholder('user@example.com')
                            ->required()
                            ->email()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('password')
                            ->placeholder(__('admin.ui.app_users.password_placeholder'))
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label(__('admin.ui.app_users.active'))
                            ->default(true),
                        Toggle::make('unlimited_devices')
                            ->label(__('admin.ui.app_users.unlimited_devices'))
                            ->default(false),
                        TextInput::make('discount_margin_percent')
                            ->label(__('admin.ui.app_users.discount_margin'))
                            ->numeric()
                            ->minValue(-100)
                            ->maxValue(4)
                            ->step(1)
                            ->default(0)
                            ->suffix('%')
                            ->helperText(__('admin.ui.app_users.discount_help')),
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
                            ])
                            ->default(PreferredLanguage::EN->value)
                            ->required(),
                    ]),
                Section::make(__('admin.ui.app_users.authorized_device_section'))
                    ->description(__('admin.ui.app_users.authorized_device_description'))
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->columns(2)
                    ->components([
                        Placeholder::make('device_binding_status')
                            ->label(__('admin.ui.app_users.device_status'))
                            ->content(fn (?User $record): HtmlString => new HtmlString(match (true) {
                                $record?->unlimited_devices === true => '<strong style="color:#15803d">'.__('admin.ui.app_users.unlimited_devices').'</strong>',
                                $record?->hasAuthorizedDeviceId() === true => '<strong style="color:#15803d">'.__('admin.ui.app_users.bound_device_id').'</strong>',
                                $record?->hasAuthorizedDevice() === true => '<strong style="color:#b45309">'.__('admin.ui.app_users.bound_fcm').'</strong>',
                                default => '<strong style="color:#b45309">'.__('admin.ui.app_users.not_bound_yet').'</strong>',
                            })),
                        Placeholder::make('device_bound_at_display')
                            ->label(__('admin.ui.app_users.bound_since'))
                            ->content(fn (?User $record): string => $record?->device_bound_at?->format('Y-m-d H:i:s') ?? '—'),
                        Placeholder::make('device_binding_help')
                            ->label(__('admin.ui.app_users.how_it_works'))
                            ->content(__('admin.ui.app_users.device_help'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
