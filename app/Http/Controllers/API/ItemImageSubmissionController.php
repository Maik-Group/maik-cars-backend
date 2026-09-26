<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemImageSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ItemImageSubmissionController extends Controller
{
    public function store(Request $request, Item $item): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,avif', 'max:20480'],
        ]);

        if ($item->getFirstMedia('images') !== null) {
            return response()->json([
                'message' => 'This item already has an approved image.',
            ], 409);
        }

        $user = $request->user();

        $hasPendingSubmission = ItemImageSubmission::query()
            ->where('item_id', $item->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', ItemImageSubmission::STATUS_PENDING)
            ->exists();

        if ($hasPendingSubmission) {
            return response()->json([
                'message' => 'You already have an image awaiting review for this item.',
            ], 409);
        }

        $submission = ItemImageSubmission::query()->create([
            'item_id' => $item->getKey(),
            'user_id' => $user->getKey(),
            'status' => ItemImageSubmission::STATUS_PENDING,
        ]);

        $uploadedImage = $request->file('image');
        $extension = strtolower((string) (
            $uploadedImage?->guessExtension()
            ?: $uploadedImage?->getClientOriginalExtension()
            ?: 'jpg'
        ));

        try {
            $submission->addMedia($uploadedImage)
                ->usingFileName(Str::uuid().'.'.$extension)
                ->toMediaCollection('submitted_image');
        } catch (Throwable $exception) {
            $submission->delete();

            throw $exception;
        }

        return response()->json([
            'message' => 'Image submitted for review.',
            'data' => [
                'id' => $submission->getKey(),
                'item_id' => $item->getKey(),
                'status' => $submission->status,
                'image_url' => $submission->getFirstMediaUrl('submitted_image'),
            ],
        ], 201);
    }
}
