<?php

namespace App\Filament\Resources\Items\Tables;

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\PricingReviewService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                ImageColumn::make('image')
                    ->label(__('admin.ui.items.image'))
                    ->circular()
                    ->size(48)
                    ->getStateUsing(fn (Item $record): string => $record->getFirstMediaUrl('images', 'thumb')),
                TextColumn::make('serial_code')
                    ->label(__('admin.ui.items.serial'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $normalized = Item::normalizeSerialValue($search);
                        $rawSearch = '%'.trim($search).'%';

                        return $query->where(function (Builder $searchQuery) use ($rawSearch, $normalized): void {
                            $searchQuery->where('serial_code', 'like', $rawSearch);

                            if ($normalized !== '') {
                                $searchQuery->orWhere('normalized_serial', 'like', "%{$normalized}%");
                            }
                        });
                    })
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('model')
                    ->label(__('admin.ui.items.model'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('carGroup.name')
                    ->label(__('admin.ui.items.car_group'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('weight_kg')
                    ->label(__('admin.ui.items.weight'))
                    ->formatStateUsing(fn (mixed $state): string => self::formatNumber($state, 3))
                    ->sortable(),
                TextColumn::make('pt_ppm')
                    ->label('PT')
                    ->formatStateUsing(fn (mixed $state): string => self::formatNumber($state, 4))
                    ->sortable(),
                TextColumn::make('pd_ppm')
                    ->label('PD')
                    ->formatStateUsing(fn (mixed $state): string => self::formatNumber($state, 4))
                    ->sortable(),
                TextColumn::make('rh_ppm')
                    ->label('RH')
                    ->formatStateUsing(fn (mixed $state): string => self::formatNumber($state, 4))
                    ->sortable(),
                TextColumn::make('current_price')
                    ->label(__('admin.ui.items.price_usd'))
                    ->getStateUsing(fn (Item $record): float => app(ItemPriceService::class)->priceFor($record, 'USD'))
                    ->money('USD'),
                TextColumn::make('pricing_review_status')
                    ->label(__('admin.ui.items.pricing_review'))
                    ->getStateUsing(fn (Item $record): string => $record->filterMapping?->status === ItemFilterMapping::STATUS_NEEDS_REVIEW
                        ? __('admin.ui.items.needs_review')
                        : __('admin.ui.items.clear'))
                    ->badge()
                    ->color(fn (Item $record): string => $record->filterMapping?->status === ItemFilterMapping::STATUS_NEEDS_REVIEW ? 'warning' : 'success'),
                TextColumn::make('pricing_review_reason')
                    ->label(__('admin.ui.items.reason'))
                    ->getStateUsing(function (Item $record): ?string {
                        $mapping = $record->filterMapping;

                        if (! $mapping instanceof ItemFilterMapping || $mapping->status !== ItemFilterMapping::STATUS_NEEDS_REVIEW) {
                            return null;
                        }

                        return app(PricingReviewService::class)->issue($mapping)['label'];
                    })
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->label(__('admin.ui.items.updated'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('car_group_id')
                    ->label(__('admin.ui.items.car_group'))
                    ->options(fn (): array => CarGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('pricing_review')
                    ->label(__('admin.ui.items.pricing_review'))
                    ->options([
                        'needs_review' => __('admin.ui.items.review_blocked'),
                        'clear' => __('admin.ui.items.clear'),
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            'needs_review' => $query->whereHas('filterMapping', fn ($mapping) => $mapping->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW)),
                            'clear' => $query->whereDoesntHave('filterMapping', fn ($mapping) => $mapping->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW)),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('has_image')
                    ->label(__('admin.ui.items.has_image'))
                    ->queries(
                        true: fn ($query) => $query->whereHas('media', fn ($mediaQuery) => $mediaQuery->where('collection_name', 'images')),
                        false: fn ($query) => $query->whereDoesntHave('media', fn ($mediaQuery) => $mediaQuery->where('collection_name', 'images')),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->groups([
                self::serialGroup(),
            ])
            ->groupingSettingsHidden()
            ->deferLoading()
            ->defaultSort('updated_at', 'desc');

        return $table;
    }

    private static function serialGroup(): Group
    {
        return Group::make('normalized_serial')
            ->label(__('admin.ui.items.serial'))
            ->titlePrefixedWithLabel(false)
            ->getTitleFromRecordUsing(fn (Item $record): string => self::displayValue($record->normalized_serial ?: Item::normalizeSerialValue($record->serial_code)))
            ->getDescriptionFromRecordUsing(fn (Item $record): string => self::groupDescription($record))
            ->scopeQueryByKeyUsing(
                fn (Builder $query, string $key): Builder => $query->where('normalized_serial', Item::normalizeSerialValue($key)),
            )
            ->orderQueryUsing(
                fn (Builder $query, string $direction): Builder => $query->orderBy('normalized_serial', $direction),
            )
            ->collapsible();
    }

    private static function groupDescription(Item $record): string
    {
        $serial = Item::normalizeSerialValue($record->normalized_serial ?: $record->serial_code);

        $siblings = Item::query()
            ->with(['carGroup', 'media', 'filterMapping'])
            ->where('normalized_serial', $serial)
            ->orderByDesc('created_at')
            ->get();

        $representative = $siblings
            ->first(fn (Item $item): bool => $item->isApiVisible());

        if (! $representative instanceof Item) {
            return __('admin.ui.items.not_shown', ['count' => $siblings->count()]);
        }

        $visibleSiblings = $siblings->filter(fn (Item $item): bool => $item->isApiVisible());

        $averagePrice = $visibleSiblings->isEmpty()
            ? app(ItemPriceService::class)->priceFor($representative, 'USD')
            : (float) $visibleSiblings
                ->map(fn (Item $item): float => app(ItemPriceService::class)->priceFor($item, 'USD'))
                ->avg();

        return __('admin.ui.items.group_summary', [
            'count' => $siblings->count(),
            'model' => self::displayValue($representative->model),
            'group' => self::displayValue($representative->carGroup?->name),
            'weight' => self::formatNumber($representative->weight_kg, 3),
            'pt' => self::formatNumber($representative->pt_ppm, 4),
            'pd' => self::formatNumber($representative->pd_ppm, 4),
            'rh' => self::formatNumber($representative->rh_ppm, 4),
            'price' => number_format($averagePrice, 2),
        ]);
    }

    private static function formatNumber(mixed $value, int $maxDecimals): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $formatted = number_format((float) $value, $maxDecimals, '.', ',');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function displayValue(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '—';
    }
}
