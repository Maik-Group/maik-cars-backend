<?php

namespace App\Filament\Resources\AppUsers\Pages;

use App\Filament\Resources\AppUsers\AppUserResource;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateAppUser extends CreateRecord
{
    protected static string $resource = AppUserResource::class;

    protected function handleRecordCreation(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            /** @var User $record */
            $record = parent::handleRecordCreation($data);
            $record->assignRole('app_user');
            app(CreditWalletService::class)->grantWelcomeCredits($record);

            return $record;
        });
    }
}
