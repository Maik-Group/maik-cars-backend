<?php

use App\Models\ExtraCode;
use App\Models\ImportBatch;
use App\Models\Item;
use App\Models\MetalPrice;
use Database\Seeders\MobileFakeDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('mobile fake data seeding generates sample catalog records and is idempotent', function (): void {
    Storage::fake(config('media-library.disk_name', 'public'));

    $this->seed(MobileFakeDataSeeder::class);

    $batch = ImportBatch::query()->where('file_name', 'mobile-fake-data.xlsx')->firstOrFail();

    expect($batch->rows_inserted)->toBe(80)
        ->and(Item::query()->where('serial_code', 'like', 'FX-%')->count())->toBe(80)
        ->and(ExtraCode::query()->where('source', 'fake_seed')->count())->toBeGreaterThanOrEqual(80)
        ->and(MetalPrice::query()->where('source', 'fake_seed')->count())->toBe(18);

    $this->seed(MobileFakeDataSeeder::class);

    expect(ImportBatch::query()->where('file_name', 'mobile-fake-data.xlsx')->count())->toBe(1)
        ->and(Item::query()->where('serial_code', 'like', 'FX-%')->count())->toBe(80);
});
