<?php

namespace App\Filament\Resources\ItemFilterMappings\Tables;

use App\Filament\Resources\Items\ItemResource;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\FilterPriceCorrectionService;
use App\Services\Pricing\PricingReviewService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use RuntimeException;

class ItemFilterMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.carGroup.name')
                    ->label(__('admin.ui.pricing_review.group'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('item.serial_code')
                    ->label(__('admin.ui.pricing_review.serial'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('issue')
                    ->label(__('admin.ui.pricing_review.why_review'))
                    ->getStateUsing(fn (ItemFilterMapping $record): string => app(PricingReviewService::class)->issue($record)['label'])
                    ->badge()
                    ->color(fn (ItemFilterMapping $record): string => $record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW ? 'warning' : 'gray')
                    ->wrap(),
                TextColumn::make('item.weight_kg')
                    ->label(__('admin.ui.pricing_review.product_weight'))
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3).' kg'),
                TextColumn::make('item.filter_reference_code')
                    ->label(__('admin.ui.pricing_review.filter_reference'))
                    ->searchable()
                    ->placeholder(__('admin.ui.pricing_review.filter_reference_missing')),
                TextColumn::make('filter_weight')
                    ->label(__('admin.ui.pricing_review.filter_weight'))
                    ->getStateUsing(fn (ItemFilterMapping $record): ?string => self::formattedWeight(self::filterWeight($record))),
                TextColumn::make('net_weight')
                    ->label(__('admin.ui.pricing_review.corrected_weight'))
                    ->getStateUsing(fn (ItemFilterMapping $record): ?string => self::formattedWeight(self::netWeight($record))),
                TextColumn::make('correction_status')
                    ->label(__('admin.ui.pricing_review.review_status'))
                    ->getStateUsing(fn (ItemFilterMapping $record): string => self::correctionStatus($record))
                    ->badge(),
                TextColumn::make('current_price')
                    ->label(__('admin.ui.pricing_review.current_price'))
                    ->getStateUsing(fn (ItemFilterMapping $record): float => self::price($record, FilterPriceCorrectionService::MODE_DISABLED))
                    ->money('USD'),
                TextColumn::make('weight_only_price')
                    ->label(__('admin.ui.pricing_review.price_after_correction'))
                    ->getStateUsing(fn (ItemFilterMapping $record): float => self::price($record, FilterPriceCorrectionService::MODE_WEIGHT_ONLY))
                    ->money('USD'),
                TextColumn::make('api_status')
                    ->label(__('admin.ui.pricing_review.customer_visibility'))
                    ->getStateUsing(fn (ItemFilterMapping $record): string => $record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW
                        ? __('admin.ui.pricing_review.hidden_until_reviewed')
                        : __('admin.ui.pricing_review.visible_to_customers'))
                    ->badge()
                    ->color(fn (ItemFilterMapping $record): string => $record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW ? 'danger' : 'success'),
                TextColumn::make('confidence')
                    ->label(__('admin.ui.pricing_review.confidence'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'high' => __('admin.ui.pricing_review.high'),
                        'medium' => __('admin.ui.pricing_review.medium'),
                        'low' => __('admin.ui.pricing_review.low'),
                        'manual' => __('admin.ui.pricing_review.manual'),
                        default => $state,
                    })
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.ui.pricing_review.status'))
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        ItemFilterMapping::STATUS_NEEDS_REVIEW => __('admin.ui.pricing_review.awaiting_review'),
                        ItemFilterMapping::STATUS_APPROVED => __('admin.ui.pricing_review.approved'),
                        ItemFilterMapping::STATUS_IGNORED => __('admin.ui.pricing_review.kept_current'),
                        ItemFilterMapping::STATUS_DETECTED => __('admin.ui.pricing_review.detected'),
                        default => $state,
                    })
                    ->color(fn (ItemFilterMapping $record): string => match ($record->status) {
                        ItemFilterMapping::STATUS_NEEDS_REVIEW => 'warning',
                        ItemFilterMapping::STATUS_APPROVED => 'success',
                        ItemFilterMapping::STATUS_IGNORED => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('item.source_url')
                    ->label(__('admin.ui.pricing_review.source'))
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? __('admin.ui.pricing_review.open') : '-')
                    ->url(fn (ItemFilterMapping $record): ?string => $record->item?->source_url)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.ui.pricing_review.review_status'))
                    ->default(ItemFilterMapping::STATUS_NEEDS_REVIEW)
                    ->options([
                        ItemFilterMapping::STATUS_NEEDS_REVIEW => __('admin.ui.pricing_review.awaiting_hidden_filter'),
                        ItemFilterMapping::STATUS_APPROVED => __('admin.ui.pricing_review.approved'),
                        ItemFilterMapping::STATUS_IGNORED => __('admin.ui.pricing_review.kept_current'),
                        ItemFilterMapping::STATUS_DETECTED => __('admin.ui.pricing_review.detected'),
                    ]),
                SelectFilter::make('confidence')
                    ->label(__('admin.ui.pricing_review.confidence'))
                    ->options([
                        'high' => __('admin.ui.pricing_review.high'),
                        'medium' => __('admin.ui.pricing_review.medium'),
                        'low' => __('admin.ui.pricing_review.low'),
                        'manual' => __('admin.ui.pricing_review.manual'),
                    ]),
            ])
            ->recordActions([
                self::reviewWeightAction(),
                Action::make('editItemData')
                    ->label(__('admin.ui.pricing_review.edit_item_data'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('info')
                    ->visible(fn (ItemFilterMapping $record): bool => $record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW && $record->item !== null && ItemResource::canEdit($record->item))
                    ->url(fn (ItemFilterMapping $record): string => ItemResource::getUrl('edit', ['record' => $record->item]))
                    ->openUrlInNewTab(),
                self::keepCurrentPricingAction(),
                EditAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    private static function reviewWeightAction(): Action
    {
        return Action::make('reviewAndApplyWeight')
            ->label(__('admin.ui.pricing_review.review_item'))
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->modalHeading(__('admin.ui.pricing_review.review_heading'))
            ->modalSubmitActionLabel(__('admin.ui.pricing_review.review_submit'))
            ->visible(function (ItemFilterMapping $record): bool {
                if ($record->status !== ItemFilterMapping::STATUS_NEEDS_REVIEW) {
                    return false;
                }

                return app(PricingReviewService::class)->issue($record)['type'] === 'component_weight';
            })
            ->schema([
                Section::make(__('admin.ui.pricing_review.issue'))
                    ->compact()
                    ->schema([
                        Text::make(function (ItemFilterMapping $record): string {
                            $issue = app(PricingReviewService::class)->issue($record);

                            return $issue['label'].($issue['variant'] ? ' · '.$issue['variant'] : '');
                        })->weight('bold'),
                        Text::make(fn (ItemFilterMapping $record): string => app(PricingReviewService::class)->issue($record)['instruction']),
                    ]),
                TextInput::make('filter_weight_kg')
                    ->label(__('admin.ui.pricing_review.verified_weight'))
                    ->numeric()
                    ->minValue(0.001)
                    ->step(0.001)
                    ->suffix('kg')
                    ->required()
                    ->live(debounce: 350)
                    ->helperText(__('admin.ui.pricing_review.verified_weight_help')),
                Section::make(__('admin.ui.pricing_review.before_after'))
                    ->description(__('admin.ui.pricing_review.before_after_desc'))
                    ->schema([
                        Text::make(function (Get $get, ItemFilterMapping $record): HtmlString {
                            $weight = $get('filter_weight_kg');

                            if (! is_numeric($weight) || (float) $weight <= 0) {
                                return new HtmlString('<strong>'.__('admin.ui.pricing_review.enter_weight_preview').'</strong>');
                            }

                            $preview = app(PricingReviewService::class)->previewFamilyWeight($record, (float) $weight);
                            $status = $preview['all_safe']
                                ? '<span style="color:#15803d"><strong>'.__('admin.ui.pricing_review.safe_all').'</strong></span>'
                                : '<span style="color:#b91c1c"><strong>'.__('admin.ui.pricing_review.not_safe').'</strong></span>';

                            return new HtmlString($status.'<br>'.__('admin.ui.pricing_review.linked_items', ['count' => '<strong>'.$preview['count'].'</strong>']));
                        }),
                        UnorderedList::make(function (Get $get, ItemFilterMapping $record): array {
                            $weight = $get('filter_weight_kg');

                            if (! is_numeric($weight) || (float) $weight <= 0) {
                                return [__('admin.ui.pricing_review.preview_wait')];
                            }

                            $preview = app(PricingReviewService::class)->previewFamilyWeight($record, (float) $weight);

                            return collect($preview['rows'])
                                ->map(function (array $row): string {
                                    $model = filled($row['model']) ? ' · '.$row['model'] : '';
                                    $net = $row['net_weight_kg'] === null ? __('admin.ui.pricing_review.invalid') : number_format((float) $row['net_weight_kg'], 3).' kg';
                                    $delta = $row['delta_percent'] === null ? '' : sprintf(' (%+.2f%%)', (float) $row['delta_percent']);
                                    $safety = $row['safe']
                                        ? __('admin.ui.pricing_review.safe')
                                        : __('admin.ui.pricing_review.blocked', [
                                            'reason' => self::reviewReasonLabel((string) $row['reason']),
                                        ]);

                                    return __('admin.ui.pricing_review.row_summary', [
                                        'serial' => $row['serial'],
                                        'model' => $model,
                                        'weight' => number_format((float) $row['original_weight_kg'], 3),
                                        'net' => $net,
                                        'current' => number_format((float) $row['current_price_usd'], 2),
                                        'proposed' => number_format((float) $row['proposed_price_usd'], 2),
                                        'delta' => $delta,
                                        'safety' => $safety,
                                    ]);
                                })
                                ->all();
                        })->columns(1),
                    ]),
                Textarea::make('review_note')
                    ->label(__('admin.ui.pricing_review.review_note'))
                    ->rows(3)
                    ->placeholder(__('admin.ui.pricing_review.review_note_placeholder')),
            ])
            ->action(function (array $data, ItemFilterMapping $record): void {
                try {
                    $result = app(PricingReviewService::class)->applyFamilyWeight(
                        $record,
                        (float) $data['filter_weight_kg'],
                        auth()->id(),
                        filled($data['review_note'] ?? null) ? (string) $data['review_note'] : null,
                    );
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title(__('admin.ui.pricing_review.not_applied'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.ui.pricing_review.approved_title'))
                    ->body(__('admin.ui.pricing_review.approved_body', ['count' => $result['applied']]))
                    ->success()
                    ->send();
            });
    }

    private static function keepCurrentPricingAction(): Action
    {
        return Action::make('approveCurrentPricing')
            ->label(__('admin.ui.pricing_review.keep_current_price'))
            ->icon('heroicon-o-check-badge')
            ->color('gray')
            ->modalHeading(__('admin.ui.pricing_review.keep_heading'))
            ->modalDescription(__('admin.ui.pricing_review.keep_desc'))
            ->modalSubmitActionLabel(__('admin.ui.pricing_review.keep_submit'))
            ->visible(fn (ItemFilterMapping $record): bool => $record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW)
            ->schema([
                Text::make(function (ItemFilterMapping $record): string {
                    $issue = app(PricingReviewService::class)->issue($record);
                    $count = app(PricingReviewService::class)->relatedReviewMappings($record)->count();

                    return $issue['label'].' · '.__('admin.ui.pricing_review.linked_needs_review', ['count' => $count]);
                })->weight('bold'),
                Text::make(fn (ItemFilterMapping $record): string => app(PricingReviewService::class)->issue($record)['instruction']),
                Textarea::make('review_note')
                    ->label(__('admin.ui.pricing_review.keep_note_label'))
                    ->required()
                    ->rows(4)
                    ->helperText(__('admin.ui.pricing_review.keep_note_help')),
            ])
            ->action(function (array $data, ItemFilterMapping $record): void {
                try {
                    $count = app(PricingReviewService::class)->approveCurrentPricing(
                        $record,
                        auth()->id(),
                        (string) $data['review_note'],
                    );
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title(__('admin.ui.pricing_review.review_failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.ui.pricing_review.current_approved'))
                    ->body(__('admin.ui.pricing_review.current_approved_body', ['count' => $count]))
                    ->success()
                    ->send();
            });
    }

    private static function correctionStatus(ItemFilterMapping $record): string
    {
        if ($record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW) {
            return __('admin.ui.pricing_review.awaiting_review');
        }

        $assay = self::weightOnlyAssay($record);

        return ($assay['applied'] ?? false)
            ? __('admin.ui.pricing_review.ready')
            : self::reviewReasonLabel((string) ($assay['reason'] ?? ''));
    }

    private static function reviewReasonLabel(string $reason): string
    {
        return match ($reason) {
            'missing_filter_weight' => __('admin.ui.pricing_review.reason_missing_filter_weight'),
            'invalid_net_weight' => __('admin.ui.pricing_review.reason_invalid_net_weight'),
            'implausible_net_weight' => __('admin.ui.pricing_review.reason_implausible_net_weight'),
            '', 'disabled', 'no_approved_mapping' => __('admin.ui.pricing_review.no_correction'),
            default => $reason,
        };
    }

    /** @return array<string, mixed> */
    private static function weightOnlyAssay(ItemFilterMapping $record): array
    {
        if ($record->item === null) {
            return ['applied' => false, 'reason' => 'missing_item'];
        }

        return app(FilterPriceCorrectionService::class)
            ->effectiveAssayForMapping($record->item, $record, FilterPriceCorrectionService::MODE_WEIGHT_ONLY);
    }

    private static function filterWeight(ItemFilterMapping $record): ?float
    {
        if ($record->filter_weight_override !== null) {
            return (float) $record->filter_weight_override;
        }

        if ($record->item === null) {
            return null;
        }

        return app(FilterPriceCorrectionService::class)->filterProfile($record->item, $record)['weight_kg'];
    }

    private static function netWeight(ItemFilterMapping $record): ?float
    {
        if ($record->item === null) {
            return null;
        }

        $assay = app(FilterPriceCorrectionService::class)
            ->effectiveAssayForMapping($record->item, $record, FilterPriceCorrectionService::MODE_WEIGHT_ONLY);

        return $assay['applied'] ? (float) $assay['weight_kg'] : null;
    }

    private static function price(ItemFilterMapping $record, string $mode): float
    {
        if ($record->item === null) {
            return 0.0;
        }

        return app(ItemPriceService::class)->priceForMappingPreview($record->item, $record, $mode, 'USD');
    }

    private static function formattedWeight(?float $weight): ?string
    {
        return $weight === null ? null : number_format($weight, 3).' kg';
    }
}
