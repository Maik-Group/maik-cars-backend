<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemImageSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

function makeSubmittedMedia(): Media
{
    $submission = ItemImageSubmission::query()->create([
        'item_id' => Item::factory()->create(['car_group_id' => CarGroup::factory()->create()->id])->id,
        'user_id' => User::factory()->create()->id,
        'status' => ItemImageSubmission::STATUS_PENDING,
    ]);

    return $submission
        ->addMedia(UploadedFile::fake()->image('submission.jpg', 640, 480))
        ->toMediaCollection('submitted_image');
}

function moveSubmissionBackToLegacyFolder(Media $media): void
{
    $newPrefix = 'image-submissions/'.$media->id.'/';
    $oldPrefix = $media->id.'/';

    foreach (Storage::disk('public')->allFiles($newPrefix) as $file) {
        Storage::disk('public')->move($file, $oldPrefix.substr($file, strlen($newPrefix)));
    }
}

test('submitted images have their own Spatie media path and product media paths are unchanged', function (): void {
    $media = makeSubmittedMedia();
    $path = 'image-submissions/'.$media->id.'/'.$media->file_name;

    Storage::disk('public')->assertExists($path);
    expect($media->getUrl())->toContain('/storage/image-submissions/'.$media->id.'/');

    $product = Item::factory()->create(['car_group_id' => CarGroup::factory()->create()->id]);
    $productMedia = $product->addMedia(UploadedFile::fake()->image('product.jpg'))->toMediaCollection('images');

    Storage::disk('public')->assertExists($productMedia->id.'/'.$productMedia->file_name);
    expect(PathGeneratorFactory::create($productMedia))
        ->toBeInstanceOf(DefaultPathGenerator::class);
});

test('migration reads media rows and moves the original and derived files without changing the database', function (): void {
    $media = makeSubmittedMedia();
    moveSubmissionBackToLegacyFolder($media);

    $legacyOriginal = $media->id.'/'.$media->file_name;
    $newOriginal = 'image-submissions/'.$media->id.'/'.$media->file_name;
    $oldThumbnail = $media->id.'/conversions/extra-thumb.webp';
    $newThumbnail = 'image-submissions/'.$media->id.'/conversions/extra-thumb.webp';
    Storage::disk('public')->put($oldThumbnail, 'converted-image');

    artisan('image-submissions:migrate-storage', ['--media-id' => $media->id])
        ->assertSuccessful();

    Storage::disk('public')->assertExists([$legacyOriginal, $oldThumbnail]);
    Storage::disk('public')->assertMissing([$newOriginal, $newThumbnail]);

    artisan('image-submissions:migrate-storage', [
        '--media-id' => $media->id,
        '--execute' => true,
    ])->assertSuccessful();

    Storage::disk('public')->assertExists([$newOriginal, $newThumbnail]);
    Storage::disk('public')->assertMissing([$legacyOriginal, $oldThumbnail]);
    expect($media->fresh()->file_name)->toBe($media->file_name);

    artisan('image-submissions:migrate-storage', [
        '--media-id' => $media->id,
        '--execute' => true,
    ])->assertSuccessful();
});

test('migration refuses to overwrite an existing destination', function (): void {
    $media = makeSubmittedMedia();
    $newOriginal = 'image-submissions/'.$media->id.'/'.$media->file_name;
    $legacyOriginal = $media->id.'/'.$media->file_name;

    Storage::disk('public')->put($legacyOriginal, 'old-image');

    artisan('image-submissions:migrate-storage', [
        '--media-id' => $media->id,
        '--execute' => true,
    ])->assertFailed();

    Storage::disk('public')->assertExists([$legacyOriginal, $newOriginal]);
});

test('migration keeps conversions on a separate conversions disk', function (): void {
    $media = makeSubmittedMedia();
    moveSubmissionBackToLegacyFolder($media);

    Storage::fake('submission-conversions');
    $media->forceFill(['conversions_disk' => 'submission-conversions'])->save();

    $legacyConversion = $media->id.'/conversions/extra-thumb.webp';
    $newConversion = 'image-submissions/'.$media->id.'/conversions/extra-thumb.webp';
    Storage::disk('submission-conversions')->put($legacyConversion, 'converted-image');

    artisan('image-submissions:migrate-storage', [
        '--media-id' => $media->id,
        '--execute' => true,
    ])->assertSuccessful();

    Storage::disk('submission-conversions')->assertExists($newConversion);
    Storage::disk('submission-conversions')->assertMissing($legacyConversion);
});
