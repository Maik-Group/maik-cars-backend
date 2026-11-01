<?php

namespace App\Filament\Resources\SubscriptionEntitlements;

use App\Filament\Resources\SubscriptionEntitlements\Pages\ListSubscriptionEntitlements;
use App\Models\SubscriptionEntitlement;
use App\Services\Subscriptions\AdminSubscriptionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionEntitlementResource extends Resource
{
    protected static ?string $model = SubscriptionEntitlement::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Subscriptions';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'agreement']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.name')->searchable(), TextColumn::make('source')->badge(), TextColumn::make('environment'),
            TextColumn::make('daily_allowance')->placeholder('Unlimited'), TextColumn::make('starts_at')->dateTime(), TextColumn::make('ends_at')->dateTime(),
            TextColumn::make('revoked_at')->dateTime()->placeholder('—'), TextColumn::make('agreement.provider_state')->label('Billing state'),
            TextColumn::make('agreement.suppressed_at')->label('Locally suppressed')->dateTime(), TextColumn::make('reason')->wrap(),
        ])->defaultSort('created_at', 'desc')->filters([
            SelectFilter::make('source')->options(['paid' => 'Store', 'grant' => 'Administrative grant', 'transition' => 'Transition']),
            SelectFilter::make('environment')->options(['production' => 'Production', 'sandbox' => 'Sandbox']),
            SelectFilter::make('user_id')->relationship('user', 'name')->searchable(),
        ])->recordActions([
            Action::make('revokeLocalAccess')->label('Revoke local access')->color('danger')
                ->visible(fn () => auth()->user()->can('revoke_subscriptions'))
                ->modalDescription('Local access stops while store billing may continue. This action does not cancel or refund a store subscription.')
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (SubscriptionEntitlement $record, array $data) => app(AdminSubscriptionService::class)->revoke(auth()->user(), $record, $data['reason'])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptionEntitlements::route('/')];
    }
}
