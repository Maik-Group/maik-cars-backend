<?php

namespace App\Filament\Resources\AppUsers\Pages;

use App\Filament\Resources\AppUsers\AppUserResource;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Credits\ComplimentaryCreditService;
use App\Services\Subscriptions\AdminSubscriptionService;
use App\Services\Subscriptions\GrantTerms;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;

class EditAppUser extends EditRecord
{
    protected static string $resource = AppUserResource::class;

    protected function afterSave(): void
    {
        /** @var User $record */
        $record = $this->getRecord();

        if (! $record->hasRole('app_user')) {
            $record->assignRole('app_user');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grantCredits')->label('Grant free credits')
                ->visible(fn () => auth()->user()->can('grant_free_credits') || auth()->user()->can('grant_complimentary_credits'))
                ->schema([
                    TextInput::make('credits')->numeric()->integer()->minValue(1)->maxValue(1000000)->required(),
                    Select::make('mode')->options(['free' => 'Free credits', 'once' => 'Complimentary once', 'per_period' => 'Complimentary per entitlement period'])->default('free')->required()->live(),
                    Select::make('months')->options([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'])->visible(fn (Get $get) => $get('mode') !== 'free')->required(fn (Get $get) => $get('mode') !== 'free'),
                    Select::make('credit_subscription_plan_id')->label('Local subscription plan')->options(fn () => SubscriptionPlan::query()->pluck('name', 'id'))->searchable()->visible(fn (Get $get) => $get('mode') !== 'free')->required(fn (Get $get) => $get('mode') !== 'free'),
                    DateTimePicker::make('credit_starts_at')->label('Complimentary starts at (UTC)')->timezone('UTC')->default(now())->required(fn (Get $get) => $get('mode') !== 'free')->visible(fn (Get $get) => $get('mode') !== 'free'),
                    DateTimePicker::make('credit_ends_at')->label('Complimentary ends at (UTC)')->timezone('UTC')->after('credit_starts_at')->required(fn (Get $get) => $get('mode') !== 'free')->visible(fn (Get $get) => $get('mode') !== 'free'),
                    DateTimePicker::make('expires_at')->label('Free credit expiration (optional, UTC)')->visible(fn (Get $get) => $get('mode') === 'free'),
                ])->action(function (array $data): void {
                    $service = app(ComplimentaryCreditService::class);
                    abort_unless(auth()->user()->can($data['mode'] === 'free' ? 'grant_free_credits' : 'grant_complimentary_credits'), 403);
                    if ($data['mode'] === 'free') {
                        $service->grantFree($this->getRecord(), (int) $data['credits'], filled($data['expires_at'] ?? null) ? CarbonImmutable::parse($data['expires_at'], 'UTC') : null);
                    } else {
                        $service->enroll(
                            $this->getRecord(), (int) $data['credits'], (int) $data['months'],
                            CarbonImmutable::parse($data['credit_starts_at'], 'UTC'),
                            CarbonImmutable::parse($data['credit_ends_at'], 'UTC'), $data['mode'], SubscriptionPlan::findOrFail($data['credit_subscription_plan_id']),
                        );
                        $service->allocateDue();
                    }
                }),
            Action::make('grantSubscription')->label('Grant subscription access')
                ->visible(fn () => auth()->user()->can('grant_subscriptions'))
                ->schema([
                    Select::make('plan_id')->options(fn () => SubscriptionPlan::query()->pluck('name', 'id'))->required()->searchable(),
                    DateTimePicker::make('starts_at')->label('Starts at (UTC)')->timezone('UTC')->default(now())->required(),
                    Select::make('validity_mode')->options(['plan' => 'Use plan calendar duration', 'custom' => 'Custom UTC end date'])->default('plan')->required()->live(),
                    DateTimePicker::make('ends_at')->label('Ends at (UTC)')->timezone('UTC')->default(now()->addMonthNoOverflow())->required()->after('starts_at')->visible(fn (Get $get): bool => $get('validity_mode') === 'custom'),
                    Textarea::make('reason')->required()->maxLength(255),
                ])->action(function (array $data): void {
                    $plan = SubscriptionPlan::query()->findOrFail($data['plan_id']);
                    $start = CarbonImmutable::parse($data['starts_at'], 'UTC');
                    $terms = $data['validity_mode'] === 'custom'
                        ? new GrantTerms($start, CarbonImmutable::parse($data['ends_at'], 'UTC'), $data['reason'])
                        : GrantTerms::forPlan($plan, $start, $data['reason']);
                    app(AdminSubscriptionService::class)->grant(auth()->user(), $this->getRecord(), $plan, $terms);
                }),
            DeleteAction::make(),
        ];
    }
}
