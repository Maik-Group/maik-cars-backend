<?php

namespace App\Filament\Resources\AppUsers\Pages;

use App\Filament\Resources\AppUsers\AppUserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
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
