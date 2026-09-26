<?php

namespace App\Filament\Resources\ItemImageSubmissions\Pages;

use App\Filament\Resources\ItemImageSubmissions\ItemImageSubmissionResource;
use App\Models\ItemImageSubmission;
use Filament\Resources\Pages\ListRecords;

class ListItemImageSubmissions extends ListRecords
{
    protected static string $resource = ItemImageSubmissionResource::class;

    public function getSubheading(): ?string
    {
        $count = ItemImageSubmission::query()
            ->where('status', ItemImageSubmission::STATUS_PENDING)
            ->count();

        return __('admin.ui.image_submissions.subheading', ['count' => $count]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
