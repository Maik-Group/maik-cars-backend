<?php

namespace App\Filament\Resources\ItemFilterMappings\Schemas;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\FilterPriceCorrectionService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ItemFilterMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.ui.pricing_review.manual_mapping'))
                ->description(__('admin.ui.pricing_review.manual_mapping_desc'))
                ->columns(2)
                ->components([
                    Select::make('item_id')
                        ->relationship('item', 'serial_code')
                        ->searchable()
                        ->preload(false)
                        ->required()
                        ->live()
                        ->label(__('admin.ui.pricing_review.catalyst_product'))
                        ->helperText(__('admin.ui.pricing_review.catalyst_help')),
                    Select::make('filter_item_id')
                        ->relationship('filterItem', 'serial_code')
                        ->searchable()
                        ->preload(false)
                        ->required()
                        ->live()
                        ->label(__('admin.ui.pricing_review.matching_filter'))
                        ->helperText(__('admin.ui.pricing_review.matching_filter_help')),
                    TextInput::make('filter_weight_override')
                        ->label(__('admin.ui.pricing_review.corrected_filter_weight'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.001)
                        ->suffix('kg')
                        ->nullable()
                        ->live(debounce: 350)
                        ->helperText(__('admin.ui.pricing_review.corrected_filter_help')),
                ]),

            Section::make(__('admin.ui.pricing_review.pricing_preview'))
                ->description(__('admin.ui.pricing_review.pricing_preview_desc'))
                ->components([
                    Text::make(fn (Get $get): string => self::pricingPreview($get)),
                ]),
        ]);
    }

    private static function pricingPreview(Get $get): string
    {
        $itemId = $get('item_id');

        if (blank($itemId)) {
            return __('admin.ui.pricing_review.select_product_first');
        }

        $item = Item::query()->find($itemId);
        if (! $item instanceof Item) {
            return __('admin.ui.pricing_review.product_load_failed');
        }

        $filterItemId = $get('filter_item_id');
        $weightOverride = $get('filter_weight_override');

        if (blank($filterItemId)) {
            return __('admin.ui.pricing_review.select_filter');
        }

        $mapping = new ItemFilterMapping([
            'item_id' => $item->getKey(),
            'filter_item_id' => (string) $filterItemId,
            'filter_weight_override' => is_numeric($weightOverride) ? (float) $weightOverride : null,
            'detection_method' => 'manual',
            'confidence' => 'manual',
            'status' => ItemFilterMapping::STATUS_APPROVED,
        ]);

        $correctionService = app(FilterPriceCorrectionService::class);
        $assay = $correctionService->effectiveAssayForMapping(
            $item,
            $mapping,
            FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
        );

        $priceService = app(ItemPriceService::class);
        $currentPrice = $priceService->priceForMappingPreview(
            $item,
            $mapping,
            FilterPriceCorrectionService::MODE_DISABLED,
            'USD',
        );

        if (! ($assay['applied'] ?? false)) {
            return __('admin.ui.pricing_review.preview_not_available', [
                'current' => number_format($currentPrice, 2),
                'reason' => self::previewReason((string) ($assay['reason'] ?? 'missing_reference')),
            ]);
        }

        $previewPrice = $priceService->priceForMappingPreview(
            $item,
            $mapping,
            FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
            'USD',
        );

        $delta = $previewPrice - $currentPrice;
        $deltaPercent = $currentPrice > 0
            ? ($delta / $currentPrice) * 100
            : 0.0;

        return __('admin.ui.pricing_review.preview_line', [
            'current' => number_format($currentPrice, 2),
            'preview' => number_format($previewPrice, 2),
            'delta' => sprintf('%+.2f', $deltaPercent),
            'weight' => number_format((float) $assay['weight_kg'], 3),
        ]);
    }

    private static function previewReason(string $reason): string
    {
        return match ($reason) {
            'missing_filter_weight' => __('admin.ui.pricing_review.reason_missing_filter_weight'),
            'invalid_net_weight' => __('admin.ui.pricing_review.reason_invalid_net_weight'),
            'implausible_net_weight' => __('admin.ui.pricing_review.reason_implausible_net_weight'),
            default => __('admin.ui.pricing_review.reason_more_data'),
        };
    }
}
