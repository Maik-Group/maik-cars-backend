<?php

namespace App\Filament\Resources\AppUsers\Pages;

use App\Filament\Resources\AppUsers\AppUserResource;
use App\Models\AdminGrantBatch;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Credits\AdminGrantBatchService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

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
            Action::make('bulkGrantCredits')
                ->label('Grant credits to app users')
                ->visible(fn () => auth()->user()->can('grant_bulk_credits'))
                ->schema([
                    Select::make('audience')->options(['all' => 'All app users', 'selected' => 'Selected user IDs'])->required()->default('selected'),
                    TextInput::make('user_ids')->label('User IDs, comma separated (for selected)'),
                    TextInput::make('credits')->numeric()->integer()->minValue(1)->maxValue(1000000)->required(),
                    DateTimePicker::make('expires_at')->label('Expiration (optional, UTC)'),
                ])
                ->action(function (array $data): void {
                    $ids = array_filter(array_map('trim', explode(',', (string) ($data['user_ids'] ?? ''))), fn ($id) => ctype_digit($id) && (int) $id > 0);
                    $expiry = filled($data['expires_at'] ?? null) ? CarbonImmutable::parse($data['expires_at'], 'UTC') : null;
                    $batch = app(AdminGrantBatchService::class)->preview(auth()->user(), $data['audience'], $ids, (int) $data['credits'], $expiry);
                    Notification::make()->title("Batch #{$batch->id}: {$batch->recipient_count} recipients frozen.")
                        ->body("Confirmation snapshot hash: {$batch->snapshot_hash}")
                        ->success()->persistent()->send();
                }),
            Action::make('bulkGrantComplimentary')->label('Assign complimentary subscriptions to app users')
                ->visible(fn () => auth()->user()->can('grant_bulk_credits') && auth()->user()->can('grant_complimentary_credits'))
                ->schema([
                    Select::make('subscription_plan_id')->label('Local subscription plan')->options(fn () => SubscriptionPlan::query()->pluck('name', 'id'))->searchable()->required(),
                    Select::make('audience')->options(['all' => 'All current app users', 'selected' => 'Selected user IDs'])->required()->default('selected'),
                    TextInput::make('user_ids')->label('User IDs, comma separated'),
                    TextInput::make('credits')->integer()->numeric()->minValue(1)->required(),
                    Select::make('months')->options([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'])->required(),
                    Select::make('issuance_mode')->options(['once' => 'Once', 'per_period' => 'Each entitlement period'])->required(),
                    DateTimePicker::make('starts_at')->label('Starts at UTC')->timezone('UTC')->required(),
                    DateTimePicker::make('ends_at')->label('Ends at UTC')->timezone('UTC')->required()->after('starts_at'),
                ])
                ->action(function (array $data): void {
                    $ids = array_filter(array_map('trim', explode(',', (string) ($data['user_ids'] ?? ''))), fn ($id) => ctype_digit($id) && (int) $id > 0);
                    $batch = app(AdminGrantBatchService::class)->previewComplimentary(
                        auth()->user(), $data['audience'], $ids, (int) $data['credits'], (int) $data['months'],
                        CarbonImmutable::parse($data['starts_at'], 'UTC'), CarbonImmutable::parse($data['ends_at'], 'UTC'), $data['issuance_mode'], (int) $data['subscription_plan_id'],
                    );
                    Notification::make()->title("Complimentary batch #{$batch->id}: {$batch->recipient_count} frozen recipients")
                        ->body("Confirm with snapshot hash: {$batch->snapshot_hash}")->success()->persistent()->send();
                }),
            Action::make('creditBatchStatus')->label('Credit batch status / preview')
                ->visible(fn () => auth()->user()->can('grant_bulk_credits'))
                ->schema([TextInput::make('batch_id')->integer()->required()])
                ->action(function (array $data): void {
                    $batch = AdminGrantBatch::findOrFail($data['batch_id']);
                    abort_unless($batch->actor_id === auth()->id(), 403);
                    $counts = $batch->recipients()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
                    Notification::make()->title("Batch #{$batch->id} · {$batch->status}")
                        ->body("Recipients: {$batch->recipient_count}. Progress: ".json_encode($counts)." Snapshot hash: {$batch->snapshot_hash}")
                        ->persistent()->send();
                }),
            Action::make('confirmCreditBatch')->label('Confirm batch preview')
                ->visible(fn () => auth()->user()->can('grant_bulk_credits'))
                ->schema([TextInput::make('batch_id')->integer()->required(), TextInput::make('snapshot_hash')->label('Snapshot hash from preview')->required()])
                ->action(function (array $data): void {
                    app(AdminGrantBatchService::class)->confirm(auth()->user(), AdminGrantBatch::findOrFail($data['batch_id']), $data['snapshot_hash']);
                    Notification::make()->title('Batch queued')->success()->send();
                }),
            Action::make('resumeCreditBatch')->label('Resume credit batch')
                ->visible(fn () => auth()->user()->can('grant_bulk_credits'))
                ->schema([TextInput::make('batch_id')->integer()->required()])
                ->action(fn (array $data) => app(AdminGrantBatchService::class)->resume(auth()->user(), AdminGrantBatch::findOrFail($data['batch_id']))),
            Action::make('cancelCreditBatch')->label('Cancel credit batch')
                ->visible(fn () => auth()->user()->can('grant_bulk_credits'))
                ->requiresConfirmation()
                ->schema([TextInput::make('batch_id')->integer()->required()])
                ->action(fn (array $data) => app(AdminGrantBatchService::class)->cancel(auth()->user(), AdminGrantBatch::findOrFail($data['batch_id']))),
            Action::make('resetAllAuthorizedDevices')
                ->label(__('admin.ui.app_users.reset_all'))
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('admin.ui.app_users.reset_all_heading'))
                ->modalDescription(__('admin.ui.app_users.reset_all_description'))
                ->modalSubmitActionLabel(__('admin.ui.app_users.reset_all'))
                ->action(function (): void {
                    $count = 0;

                    AppUserResource::getEloquentQuery()
                        ->select('users.*')
                        ->chunkById(100, function ($users) use (&$count): void {
                            /** @var User $user */
                            foreach ($users as $user) {
                                $user->resetAuthorizedDevice();
                                $count++;
                            }
                        });

                    Notification::make()
                        ->title(__('admin.ui.app_users.reset_all_done', ['count' => $count]))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
