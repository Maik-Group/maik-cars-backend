<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Widgets\ItemCatalogStats;
use App\Services\Mobile\ItemApiSettingsService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->syncTableGrouping();
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();

        $this->syncTableGrouping();
    }

    public function getSubheading(): ?string
    {
        if (app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled()) {
            return __('admin.ui.subheadings.items_grouped');
        }

        return __('admin.ui.subheadings.items_all');
    }

    public function getTabs(): array
    {
        if (! app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled()) {
            return [
                'all' => Tab::make(__('admin.ui.items.all_items')),
            ];
        }

        return [
            'all' => Tab::make(__('admin.ui.items.all_items'))
                ->icon('heroicon-o-list-bullet'),
            'grouped' => Tab::make(__('admin.ui.items.grouped_items'))
                ->icon('heroicon-o-rectangle-stack')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNotNull('normalized_serial')
                    ->where('normalized_serial', '!=', '')
                    ->whereIn('normalized_serial', function ($subquery): void {
                        $subquery
                            ->select('normalized_serial')
                            ->from('items')
                            ->whereNotNull('normalized_serial')
                            ->where('normalized_serial', '!=', '')
                            ->groupBy('normalized_serial')
                            ->havingRaw('COUNT(*) > 1');
                    })),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    private function syncTableGrouping(): void
    {
        $this->tableGrouping = app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled()
            && $this->activeTab === 'grouped'
            ? 'normalized_serial'
            : null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ItemCatalogStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
