<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\PricingReviewService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.ui.item_form.warning'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn (?Item $record): bool => $record?->filterMapping?->status === ItemFilterMapping::STATUS_NEEDS_REVIEW)
                    ->schema([
                        Placeholder::make('review_warning')
                            ->label('')
                            ->content(function (?Item $record): HtmlString {
                                $mapping = $record?->filterMapping;

                                if (! $mapping instanceof ItemFilterMapping) {
                                    return new HtmlString('');
                                }

                                $issue = app(PricingReviewService::class)->issue($mapping);
                                $reason = e($issue['label']);
                                $note = e($issue['instruction']);

                                return new HtmlString(
                                    '<div class="text-sm">'
                                    .'<div class="font-semibold text-warning-600 dark:text-warning-400">'.__('admin.ui.item_form.needs_review').'</div>'
                                    .'<div class="mt-2"><strong>'.__('admin.ui.item_form.reason').':</strong> '.$reason.'</div>'
                                    .'<div class="mt-1"><strong>'.__('admin.ui.item_form.review_note').':</strong> '.$note.'</div>'
                                    .'</div>',
                                );
                            }),
                    ])
                    ->columnSpanFull(),
                Section::make('Filter identification')
                    ->description('Keep the DPF classification and filter reference separate from the catalog serial and pricing filter mappings.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('is_dpf')
                            ->label('DPF (Diesel Particulate Filter)')
                            ->helperText('Enable only when this item has been verified as a DPF. Combined assemblies require individual verification.')
                            ->default(false),
                        TextInput::make('filter_reference_code')
                            ->label('Filter reference code')
                            ->maxLength(150)
                            ->placeholder('OEM or aftermarket part number')
                            ->helperText('Optional: enter the reference actually identified for this filter. Do not use the item serial unless it is the verified reference.'),
                    ]),
                Section::make(__('admin.ui.item_form.section'))
                    ->description(__('admin.ui.item_form.description'))
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->columnSpanFull()
                    ->components([
                        Select::make('car_group_id')
                            ->label(__('admin.ui.item_form.car_group'))
                            ->relationship('carGroup', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('model')
                            ->label(__('admin.ui.item_form.model'))
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('serial_code')
                            ->label(__('admin.ui.item_form.serial_code'))
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('weight_kg')
                            ->label(__('admin.ui.item_form.weight'))
                            ->numeric()
                            ->step(0.001)
                            ->minValue(0),
                        TextInput::make('pt_ppm')
                            ->label('PT PPM')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0),
                        TextInput::make('pd_ppm')
                            ->label('PD PPM')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0),
                        TextInput::make('rh_ppm')
                            ->label('RH PPM')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0),
                        Placeholder::make('current_price')
                            ->label(__('admin.ui.item_form.current_price'))
                            ->visible(fn (?Item $record): bool => $record instanceof Item)
                            ->content(fn (?Item $record): string => $record instanceof Item
                                ? '$'.number_format(app(ItemPriceService::class)->priceFor($record, 'USD'), 2)
                                : '—'),
                        Textarea::make('details')
                            ->label(__('admin.ui.item_form.details'))
                            ->rows(3)
                            ->columnSpanFull(),
                        FileUpload::make('item_image')
                            ->label(__('admin.ui.item_form.image'))
                            ->image()
                            ->imageEditor()
                            ->downloadable()
                            ->openable()
                            ->disk('public')
                            ->directory('filament/items')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                            ->maxSize(4096)
                            ->afterStateHydrated(function (FileUpload $component, mixed $state, ?Item $record): void {
                                if (filled($state) || ! $record instanceof Item) {
                                    return;
                                }

                                $media = $record->getFirstMedia('images');

                                if ($media !== null) {
                                    $component->state($media->getPathRelativeToRoot());
                                }
                            })
                            ->helperText(__('admin.ui.item_form.image_help'))
                            ->columnSpanFull(),
                        Repeater::make('extraCodes')
                            ->relationship()
                            ->label(__('admin.ui.item_form.extra_codes'))
                            ->schema([
                                TextInput::make('code')
                                    ->label(__('admin.ui.item_form.code'))
                                    ->required()
                                    ->maxLength(100),
                            ])
                            ->defaultItems(0)
                            ->columns(1)
                            ->columnSpanFull()
                            ->addActionLabel(__('admin.ui.item_form.add_extra_code'))
                            ->reorderable(false)
                            ->collapsible(),
                    ]),
            ]);
    }
}
