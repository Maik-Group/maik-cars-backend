<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemImageSubmission;
use App\Models\User;
use App\Services\ItemImageSubmissionReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\post;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('image submission endpoint requires authentication', function (): void {
    $item = Item::factory()->create([
        'car_group_id' => CarGroup::factory()->create()->id,
    ]);

    post("/api/items/{$item->id}/image-submissions", [
        'image' => UploadedFile::fake()->image('candidate.jpg', 640, 480),
    ], ['Accept' => 'application/json'])
        ->assertUnauthorized();
});

test('authenticated user can submit an image without changing the product image', function (): void {
    $user = User::factory()->create();
    $item = Item::factory()->create([
        'car_group_id' => CarGroup::factory()->create()->id,
    ]);

    Sanctum::actingAs($user);

    $response = post("/api/items/{$item->id}/image-submissions", [
        'image' => UploadedFile::fake()->image('candidate.jpg', 640, 480),
    ], ['Accept' => 'application/json']);

    $response
        ->assertCreated()
        ->assertJsonPath('data.itemId', $item->id)
        ->assertJsonPath('data.status', ItemImageSubmission::STATUS_PENDING);

    $submission = ItemImageSubmission::query()->firstOrFail();

    expect($submission->user_id)->toBe($user->id);
    expect($submission->item_id)->toBe($item->id);
    expect($submission->getFirstMedia('submitted_image'))->not->toBeNull();
    expect($item->fresh()->getFirstMedia('images'))->toBeNull();
});

test('same user cannot create duplicate pending submissions for one item', function (): void {
    $user = User::factory()->create();
    $item = Item::factory()->create([
        'car_group_id' => CarGroup::factory()->create()->id,
    ]);

    Sanctum::actingAs($user);

    post("/api/items/{$item->id}/image-submissions", [
        'image' => UploadedFile::fake()->image('first.jpg', 640, 480),
    ], ['Accept' => 'application/json'])
        ->assertCreated();

    post("/api/items/{$item->id}/image-submissions", [
        'image' => UploadedFile::fake()->image('second.jpg', 640, 480),
    ], ['Accept' => 'application/json'])
        ->assertConflict();

    expect(ItemImageSubmission::query()->count())->toBe(1);
});

test('approving a pending submission applies the image to the product', function (): void {
    $submitter = User::factory()->create();
    $reviewer = User::factory()->create();
    $item = Item::factory()->create([
        'car_group_id' => CarGroup::factory()->create()->id,
    ]);

    $submission = ItemImageSubmission::query()->create([
        'item_id' => $item->id,
        'user_id' => $submitter->id,
        'status' => ItemImageSubmission::STATUS_PENDING,
    ]);

    $submission
        ->addMedia(UploadedFile::fake()->image('candidate.jpg', 640, 480))
        ->toMediaCollection('submitted_image');

    app(ItemImageSubmissionReviewService::class)->approve($submission, $reviewer->id);

    $submission->refresh();
    $item->refresh();

    expect($submission->status)->toBe(ItemImageSubmission::STATUS_APPROVED);
    expect($submission->reviewed_by)->toBe($reviewer->id);
    expect($submission->reviewed_at)->not->toBeNull();
    expect($item->getFirstMedia('images'))->not->toBeNull();
});

test('rejecting a pending submission leaves the product image unchanged', function (): void {
    $submitter = User::factory()->create();
    $reviewer = User::factory()->create();
    $item = Item::factory()->create([
        'car_group_id' => CarGroup::factory()->create()->id,
    ]);

    $submission = ItemImageSubmission::query()->create([
        'item_id' => $item->id,
        'user_id' => $submitter->id,
        'status' => ItemImageSubmission::STATUS_PENDING,
    ]);

    $submission
        ->addMedia(UploadedFile::fake()->image('candidate.jpg', 640, 480))
        ->toMediaCollection('submitted_image');

    app(ItemImageSubmissionReviewService::class)->reject($submission, $reviewer->id, 'Wrong product.');

    $submission->refresh();

    expect($submission->status)->toBe(ItemImageSubmission::STATUS_REJECTED);
    expect($submission->rejection_reason)->toBe('Wrong product.');
    expect($item->fresh()->getFirstMedia('images'))->toBeNull();
});
