<?php

namespace App\Filament\Resources\CarGroups\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CarGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.ui.car_groups.section'))
                    ->description(__('admin.ui.car_groups.description'))
                    ->components([
                        TextInput::make('name')
                            ->label(__('admin.ui.car_groups.name'))
                            ->placeholder(__('admin.ui.car_groups.name_placeholder'))
                            ->required()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
