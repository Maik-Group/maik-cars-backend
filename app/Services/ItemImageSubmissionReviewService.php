<?php

namespace App\Services;

use App\Models\ItemImageSubmission;
use Illuminate\Support\Str;
use RuntimeException;

class ItemImageSubmissionReviewService
{
    public function approve(ItemImageSubmission $submission, int $reviewedBy): void
    {
        $this->ensurePending($submission);

        $submission->loadMissing(['item', 'media']);

        $item = $submission->item;
        $media = $submission->getFirstMedia('submitted_image');

        if ($item === null || $media === null) {
            throw new RuntimeException('The submitted image is no longer available.');
        }

        if ($item->getFirstMedia('images') !== null) {
            throw new RuntimeException('This item already has an approved image.');
        }

        $path = $media->getPath();
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException('The submitted image file could not be read.');
        }

        $extension = strtolower((string) (pathinfo($media->file_name, PATHINFO_EXTENSION) ?: 'jpg'));

        $item->addMediaFromString($contents)
            ->usingFileName(Str::uuid().'.'.$extension)
            ->toMediaCollection('images');

        $submission->forceFill([
            'status' => ItemImageSubmission::STATUS_APPROVED,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function reject(
        ItemImageSubmission $submission,
        int $reviewedBy,
        ?string $reason = null,
    ): void {
        $this->ensurePending($submission);

        $submission->forceFill([
            'status' => ItemImageSubmission::STATUS_REJECTED,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
            'rejection_reason' => filled($reason) ? trim((string) $reason) : null,
        ])->save();
    }

    private function ensurePending(ItemImageSubmission $submission): void
    {
        if ($submission->status !== ItemImageSubmission::STATUS_PENDING) {
            throw new RuntimeException('This image submission has already been reviewed.');
        }
    }
}
