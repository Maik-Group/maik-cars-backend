<?php

namespace App\Filament\Resources\ItemFilterMappings\Pages;

use App\Filament\Resources\ItemFilterMappings\ItemFilterMappingResource;
use App\Models\ItemFilterMapping;
use App\Services\Pricing\FilterCandidateDetectionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListItemFilterMappings extends ListRecords
{
    protected static string $resource = ItemFilterMappingResource::class;

    public function getSubheading(): ?string
    {
        $count = ItemFilterMapping::query()
            ->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW)
            ->count();

        return __('admin.ui.pricing_review.subheading', ['count' => $count]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanCandidates')
                ->label(__('admin.ui.pricing_review.recheck_items'))
                ->icon('heroicon-o-magnifying-glass')
                ->action(function (): void {
                    $summary = app(FilterCandidateDetectionService::class)->scan();

                    Notification::make()
                        ->title(__('admin.ui.pricing_review.scan_completed'))
                        ->body(__('admin.ui.pricing_review.scan_body', [
                            'scanned' => $summary['scanned'],
                            'candidates' => $summary['candidates'],
                            'matched' => $summary['matched'],
                            'review' => $summary['needs_review'],
                        ]))
                        ->success()
                        ->send();
                }),
            CreateAction::make()->label(__('admin.ui.pricing_review.add_filter_reference')),
        ];
    }
}
