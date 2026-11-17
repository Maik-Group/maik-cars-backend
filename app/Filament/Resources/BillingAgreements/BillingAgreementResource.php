<?php

namespace App\Filament\Resources\BillingAgreements;

use App\Filament\Resources\BillingAgreements\Pages\ListBillingAgreements;
use App\Filament\Resources\BillingAgreements\Pages\ViewBillingAgreement;
use App\Filament\Resources\BillingAgreements\RelationManagers\TransactionsRelationManager;
use App\Models\BillingAgreement;
use App\Services\Subscriptions\AdminSubscriptionService;
use App\Services\Subscriptions\SubscriptionOperations;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BillingAgreementResource extends Resource
{
    protected static ?string $model = BillingAgreement::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Subscriptions';

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user')->withCount('transactions');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Agreement'), TextColumn::make('user.name')->searchable(), TextColumn::make('provider'), TextColumn::make('environment'),
            TextColumn::make('provider_state')->badge(), IconColumn::make('auto_renews')->boolean(), IconColumn::make('acknowledgement_pending')->boolean(),
            TextColumn::make('verified_at')->dateTime(), TextColumn::make('suppressed_at')->dateTime(), TextColumn::make('transactions_count')->label('Verified transactions'),
        ])->filters([SelectFilter::make('provider_state')->options(array_combine(['pending', 'active', 'grace', 'hold', 'paused', 'expired', 'revoked'], ['Pending', 'Active', 'Grace', 'Hold', 'Paused', 'Expired', 'Revoked']))])
            ->recordActions([
                ViewAction::make(),
                Action::make('reconcile')->visible(fn () => auth()->user()->can('reconcile_subscriptions'))
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(fn (BillingAgreement $record, array $data) => app(SubscriptionOperations::class)->reconcile(auth()->user(), $record, $data['reason'])),
                Action::make('restoreLocalAccess')->visible(fn (BillingAgreement $record) => $record->suppressed_at && auth()->user()->can('revoke_subscriptions'))
                    ->modalDescription('This removes local suppression. Store verification and paid-through dates still determine access.')
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(fn (BillingAgreement $record, array $data) => app(AdminSubscriptionService::class)->restoreAgreement(auth()->user(), $record, $data['reason'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBillingAgreements::route('/'), 'view' => ViewBillingAgreement::route('/{record}')];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('user.name'), TextEntry::make('provider'), TextEntry::make('environment'),
            TextEntry::make('provider_state'),
            TextEntry::make('entitlement.starts_at')->dateTime(), TextEntry::make('entitlement.ends_at')->dateTime(),
            TextEntry::make('verified_at')->dateTime(), TextEntry::make('suppressed_at')->dateTime(),
            TextEntry::make('suppression_reason'),
        ]);
    }

    public static function getRelations(): array
    {
        return [TransactionsRelationManager::class];
    }
}
