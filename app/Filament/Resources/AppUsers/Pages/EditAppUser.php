<?php

namespace App\Filament\Resources\AppUsers\Pages;

use App\Filament\Resources\AppUsers\AppUserResource;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Credits\AdminUserGrantService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

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
            Action::make('grantCredits')->label('Grant credits')
                ->visible(fn () => auth()->user()?->can('grant_free_credits') === true)
                ->schema([
                    TextInput::make('credits')->label('Credits to add')
                        ->numeric()->integer()->minValue(1)->maxValue(1000000)->required(),
                ])->requiresConfirmation()
                ->action(function (array $data): void {
                    $result = app(AdminUserGrantService::class)->credits(
                        auth()->user(), 'selected', [$this->getRecord()->id], (int) $data['credits']
                    );
                    Notification::make()->title('Credits granted to '.$result['granted'].' user')->success()->send();
                }),
            Action::make('grantSubscription')->label('Grant subscription')
                ->visible(fn () => auth()->user()?->can('grant_subscriptions') === true)
                ->schema([
                    Select::make('plan_id')->label('Subscription plan')
                        ->options(fn (): array => SubscriptionPlan::query()
                            ->where('is_active', true)->where('credits', '>', 0)
                            ->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->required()->searchable(),
                ])->requiresConfirmation()
                ->modalDescription('Only users without an active subscription will receive the selected plan and its credits.')
                ->action(function (array $data): void {
                    $result = app(AdminUserGrantService::class)->subscriptions(
                        auth()->user(), 'selected', [$this->getRecord()->id], SubscriptionPlan::findOrFail($data['plan_id'])
                    );
                    Notification::make()
                        ->title($result['granted'] ? 'Subscription granted' : 'User already has an active subscription')
                        ->success()->send();
                }),
        ];
    }
}
