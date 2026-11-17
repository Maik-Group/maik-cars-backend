<?php

namespace App\Filament\Resources\AppUsers\Tables;

use App\Enums\PreferredLanguage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AppUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                IconColumn::make('is_active')
                    ->label(__('admin.ui.app_users.active'))
                    ->boolean(),
                TextColumn::make('preferred_language')
                    ->label(__('admin.ui.app_users.language'))
                    ->badge()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('unlimited_devices')
                    ->label(__('admin.ui.app_users.unlimited_devices'))
                    ->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_margin_percent')
                    ->label(__('admin.ui.app_users.discount_margin'))
                    ->formatStateUsing(static fn (mixed $state): string => number_format((float) $state, 1).'%')
                    ->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('admin.ui.app_users.created'))
                    ->since()
                    ->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.ui.app_users.active'))
                    ->boolean(),
                SelectFilter::make('preferred_language')
                    ->label(__('admin.ui.app_users.language'))
                    ->options([
                        PreferredLanguage::EN->value => 'English',
                        PreferredLanguage::AR->value => 'العربية',
                        PreferredLanguage::BG->value => 'Български',
                        PreferredLanguage::DE->value => 'Deutsch',
                        PreferredLanguage::HR->value => 'Hrvatski',
                        PreferredLanguage::HU->value => 'Hungarian (legacy)',
                    ]),
                TernaryFilter::make('unlimited_devices')
                    ->label(__('admin.ui.app_users.unlimited_devices'))
                    ->boolean(),
                TernaryFilter::make('authorized_device')
                    ->label(__('admin.ui.app_users.authorized_device'))
                    ->queries(
                        true: fn ($query) => $query->where(function ($query): void {
                            $query->whereNotNull('authorized_device_id')
                                ->orWhereNotNull('authorized_device_token');
                        }),
                        false: fn ($query) => $query
                            ->whereNull('authorized_device_id')
                            ->whereNull('authorized_device_token'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                Action::make('resetAuthorizedDevice')
                    ->label(__('admin.ui.app_users.reset_device'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (User $record): bool => $record->hasAuthorizedDevice())
                    ->disabled(fn (User $record): bool => $record->unlimited_devices)
                    ->tooltip(fn (User $record): ?string => $record->unlimited_devices
                        ? __('admin.ui.app_users.unlimited_tooltip')
                        : null)
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.ui.app_users.reset_device_heading'))
                    ->modalDescription(__('admin.ui.app_users.reset_device_description'))
                    ->modalSubmitActionLabel(__('admin.ui.app_users.reset_device_submit'))
                    ->action(function (User $record): void {
                        $record->resetAuthorizedDevice();

                        Notification::make()
                            ->title(__('admin.ui.app_users.reset_device_done'))
                            ->body(__('admin.ui.app_users.reset_device_done_body'))
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
