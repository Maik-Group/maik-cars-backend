<?php

namespace App\Filament\Resources\Items\Widgets;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemApiResponseService;
use App\Services\Mobile\ItemApiSettingsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ItemCatalogStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $uniqueSerialMode = app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled();

        $visibleItems = Item::query()
            ->apiVisible()
            ->get(['id', 'normalized_serial', 'serial_code']);

        $shownInApp = $uniqueSerialMode
            ? app(ItemApiResponseService::class)->representativeIds($visibleItems)->count()
            : $visibleItems->count();

        $needsReview = Item::query()
            ->whereHas(
                'filterMapping',
                fn ($query) => $query->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW),
            )
            ->count();

        $withoutImage = Item::query()
            ->apiVisible()
            ->whereDoesntHave(
                'media',
                fn ($query) => $query->where('collection_name', 'images'),
            )
            ->count();

        return [
            Stat::make(__('admin.ui.stats.shown_in_app'), number_format($shownInApp))
                ->description(__('admin.ui.stats.shown_in_app_description'))
                ->icon('heroicon-o-eye')
                ->color('success'),
            Stat::make(__('admin.ui.stats.needs_review'), number_format($needsReview))
                ->description(__('admin.ui.stats.needs_review_description'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning'),
            Stat::make(__('admin.ui.stats.no_image'), number_format($withoutImage))
                ->description(__('admin.ui.stats.no_image_description'))
                ->icon('heroicon-o-photo')
                ->color('danger'),
        ];
    }
}
