<?php

namespace App\Filament\Resources\NotificationAudiences\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class NotificationAudiencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.ui.audiences.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('description')
                    ->label(__('admin.ui.audiences.description_field'))
                    ->limit(50)
                    ->toggleable(),
                TextColumn::make('users_count')
                    ->label(__('admin.ui.audiences.users_count'))
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('admin.ui.audiences.active'))
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label(__('admin.ui.audiences.updated'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.ui.audiences.active'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
