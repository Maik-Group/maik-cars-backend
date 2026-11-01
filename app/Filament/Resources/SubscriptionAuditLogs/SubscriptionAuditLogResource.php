<?php

namespace App\Filament\Resources\SubscriptionAuditLogs;

use App\Filament\Resources\SubscriptionAuditLogs\Pages\ListSubscriptionAuditLogs;
use App\Models\SubscriptionAuditLog;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionAuditLogResource extends Resource
{
    protected static ?string $model = SubscriptionAuditLog::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Subscriptions';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime()->sortable(), TextColumn::make('actor_id')->label('Administrator ID'),
            TextColumn::make('user_id')->label('Recipient ID')->searchable(), TextColumn::make('action')->badge(),
            TextColumn::make('subject_type')->formatStateUsing(fn (string $state): string => class_basename($state)),
            TextColumn::make('subject_id'), TextColumn::make('reason')->wrap(),
            TextColumn::make('before')->formatStateUsing(fn ($state): string => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('after')->formatStateUsing(fn ($state): string => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->wrap()->toggleable(isToggledHiddenByDefault: true),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptionAuditLogs::route('/')];
    }
}
