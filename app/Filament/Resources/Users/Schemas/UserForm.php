<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.ui.admins.account'))
                    ->description(__('admin.ui.admins.description'))
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->components([
                        TextInput::make('name')
                            ->label(__('admin.ui.admins.name'))
                            ->placeholder(__('admin.ui.admins.name_placeholder'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('admin.ui.admins.email'))
                            ->placeholder('admin@example.com')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('password')
                            ->label(__('admin.ui.admins.password'))
                            ->placeholder(__('admin.ui.admins.password_placeholder'))
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label(__('admin.ui.admins.active'))
                            ->default(true),
                    ]),
            ]);
    }
}
