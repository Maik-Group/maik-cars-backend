<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Item;
use App\Services\Mobile\ItemPriceService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filter identification')
                ->description('DPF classification and the independently verified filter reference.')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextEntry::make('is_dpf')
                        ->label('Component classification')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'DPF' : 'Not marked as DPF')
                        ->badge()
                        ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                    TextEntry::make('filter_reference_code')
                        ->label('Filter reference code')
                        ->placeholder('Not specified')
                        ->copyable(),
                ]),
            Section::make(__('admin.ui.item_form.section'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextEntry::make('carGroup.name')->label(__('admin.ui.item_form.car_group')),
                    TextEntry::make('model')->label(__('admin.ui.item_form.model')),
                    TextEntry::make('serial_code')->label(__('admin.ui.item_form.serial_code'))->copyable(),
                    TextEntry::make('weight_kg')->label(__('admin.ui.item_form.weight'))->suffix(' kg'),
                    TextEntry::make('pt_ppm')->label('PT PPM'),
                    TextEntry::make('pd_ppm')->label('PD PPM'),
                    TextEntry::make('rh_ppm')->label('RH PPM'),
                    TextEntry::make('current_price')
                        ->label(__('admin.ui.item_form.current_price'))
                        ->getStateUsing(fn (Item $record): string => '$'.number_format(app(ItemPriceService::class)->priceFor($record, 'USD'), 2)),
                    TextEntry::make('details')
                        ->label(__('admin.ui.item_form.details'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('extraCodes.code')
                        ->label(__('admin.ui.item_form.extra_codes'))
                        ->listWithLineBreaks()
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
