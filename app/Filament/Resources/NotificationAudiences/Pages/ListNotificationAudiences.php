<?php

namespace App\Filament\Resources\NotificationAudiences\Pages;

use App\Filament\Resources\NotificationAudiences\NotificationAudienceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNotificationAudiences extends ListRecords
{
    protected static string $resource = NotificationAudienceResource::class;

    public function getSubheading(): ?string
    {
        return __('admin.ui.subheadings.audiences');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
