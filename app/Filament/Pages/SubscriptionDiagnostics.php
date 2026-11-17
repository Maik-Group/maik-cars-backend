<?php

namespace App\Filament\Pages;

use App\Models\BillingAgreement;
use App\Models\PlanStoreProduct;
use App\Models\SubscriptionEvent;
use App\Services\Subscriptions\SubscriptionOperations;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class SubscriptionDiagnostics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Subscriptions';

    protected string $view = 'filament.pages.subscription-diagnostics';

    public static function canAccess(): bool
    {
        return false;
    }

    public function summary(): array
    {
        return [
            'Inbox pending over 15 minutes' => SubscriptionEvent::query()->whereIn('state', ['pending', 'processing'])->where('created_at', '<', now()->subMinutes(15))->count(),
            'Failed verification events' => SubscriptionEvent::query()->where('state', 'failed')->count(),
            'Duplicate notification deliveries' => (int) SubscriptionEvent::query()->sum('duplicate_deliveries'),
            'Account ownership conflicts' => SubscriptionEvent::query()->where('error_code', 'PURCHASE_ACCOUNT_CONFLICT')->count(),
            'Acknowledgement pending over one hour' => BillingAgreement::query()->where('acknowledgement_pending', true)->where('created_at', '<', now()->subHour())->count(),
            'Agreements not checked in 15 minutes' => BillingAgreement::query()->whereIn('provider_state', ['active', 'grace', 'pending'])->where('verified_at', '<', now()->subMinutes(15))->count(),
            'Unverified or stale store mappings' => PlanStoreProduct::query()->where(fn ($query) => $query->where('validation_state', '!=', 'verified')
                ->orWhereHas('plan', fn ($plan) => $plan->whereColumn('subscription_plans.revision', '!=', 'plan_store_products.plan_revision')))->count(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query(SubscriptionEvent::query())->columns([
            TextColumn::make('correlation_id')->label('Reference'), TextColumn::make('provider'), TextColumn::make('environment'),
            TextColumn::make('state')->badge(), TextColumn::make('attempts'), TextColumn::make('error_code'), TextColumn::make('created_at')->dateTime(), TextColumn::make('processed_at')->dateTime(),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('replay')->visible(fn () => auth()->user()->can('reconcile_subscriptions'))
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (SubscriptionEvent $record, array $data) => app(SubscriptionOperations::class)->replay(auth()->user(), $record, $data['reason'])),
        ]);
    }
}
