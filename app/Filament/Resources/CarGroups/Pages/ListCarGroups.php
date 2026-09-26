<?php

namespace App\Filament\Resources\CarGroups\Pages;

use App\Filament\Resources\CarGroups\CarGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarGroups extends ListRecords
{
    protected static string $resource = CarGroupResource::class;

    public function getSubheading(): ?string
    {
        return __('admin.ui.subheadings.car_groups');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
