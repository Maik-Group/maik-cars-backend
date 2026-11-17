<?php

namespace App\Filament\Resources\AppUsers\Pages;

use App\Filament\Resources\AppUsers\AppUserResource;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Credits\AdminUserGrantService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListAppUsers extends ListRecords
{
    protected static string $resource = AppUserResource::class;

    public function getSubheading(): ?string
    {
        return __('admin.ui.subheadings.app_users');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grantCredits')
                ->label('Grant credits')
                ->visible(fn () => auth()->user()?->can('grant_free_credits') || auth()->user()?->can('grant_bulk_credits'))
                ->schema([
                    Select::make('scope')->label('Recipients')
                        ->options(['selected' => 'One or more users', 'all' => 'All active app users'])
                        ->default('selected')->live()->required(),
                    Select::make('user_ids')->label('Users')->multiple()->searchable()->preload()
                        ->options(fn (): array => User::query()->appUsers()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(
                            fn (User $user): array => [$user->id => $user->name.' ('.$user->email.')']
                        )->all())
                        ->visible(fn (Get $get): bool => $get('scope') === 'selected')
                        ->required(fn (Get $get): bool => $get('scope') === 'selected'),
                    TextInput::make('credits')->label('Credits per user')
                        ->numeric()->integer()->minValue(1)->maxValue(1000000)->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Credits will be added to each selected user. All users means every currently active app user.')
                ->action(function (array $data): void {
                    $result = app(AdminUserGrantService::class)->credits(
                        auth()->user(), $data['scope'], $data['user_ids'] ?? [], (int) $data['credits']
                    );
                    Notification::make()->title('Credits granted to '.$result['granted'].' user(s)')->success()->send();
                }),
            Action::make('grantSubscriptions')
                ->label('Grant subscription')
                ->visible(fn () => auth()->user()?->can('grant_subscriptions') === true)
                ->schema([
                    Select::make('scope')->label('Recipients')
                        ->options(['selected' => 'One or more users', 'all' => 'All active app users'])
                        ->default('selected')->live()->required(),
                    Select::make('user_ids')->label('Users')->multiple()->searchable()->preload()
                        ->options(fn (): array => User::query()->appUsers()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(
                            fn (User $user): array => [$user->id => $user->name.' ('.$user->email.')']
                        )->all())
                        ->visible(fn (Get $get): bool => $get('scope') === 'selected')
                        ->required(fn (Get $get): bool => $get('scope') === 'selected'),
                    Select::make('plan_id')->label('Subscription plan')
                        ->options(fn (): array => SubscriptionPlan::query()->where('is_active', true)->where('credits', '>', 0)->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->required()->searchable(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Grant the selected plan and its credits only to users without an active subscription.')
                ->action(function (array $data): void {
                    $result = app(AdminUserGrantService::class)->subscriptions(
                        auth()->user(), $data['scope'], $data['user_ids'] ?? [], SubscriptionPlan::findOrFail($data['plan_id'])
                    );
                    Notification::make()->title('Subscriptions granted to '.$result['granted'].' user(s)')
                        ->body($result['skipped'].' already subscribed user(s) skipped')->success()->send();
                }),
            CreateAction::make(),
        ];
    }
}
