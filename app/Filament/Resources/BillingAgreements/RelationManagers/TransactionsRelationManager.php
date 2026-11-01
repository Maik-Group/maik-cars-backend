<?php

namespace App\Filament\Resources\BillingAgreements\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Ledger entry'), TextColumn::make('product_id'),
            TextColumn::make('starts_at')->dateTime(), TextColumn::make('ends_at')->dateTime(),
            TextColumn::make('amount')->placeholder('Not supplied by store'), TextColumn::make('currency'),
            TextColumn::make('revoked_at')->dateTime(), TextColumn::make('created_at')->dateTime(),
        ])->defaultSort('created_at', 'desc');
    }
}
