<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function newItemsImportWorkbook(): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('JAPAN');

    $sheet->setCellValue('A1', 'Model');
    $sheet->setCellValue('B1', 'Serial Code');
    $sheet->setCellValue('C1', 'Piece kg');
    $sheet->setCellValue('D1', '=SUM(kitko!A1)');
    $sheet->setCellValue('D2', 'Pt');
    $sheet->setCellValue('F2', 'Pd');
    $sheet->setCellValue('H2', 'Rh');
    $sheet->setCellValue('D3', 'ppm');
    $sheet->setCellValue('F3', 'ppm');
    $sheet->setCellValue('H3', 'ppm');
    $sheet->setCellValue('J3', 'Price US/Piece');

    $sheet->fromArray(['TOYOTA', '???', 0.7, 0.5, null, 0.769, null, 0.208], null, 'A4');
    $sheet->fromArray(['TOYOTA', 'ABC-123', 0.8, 0.6, null, 0.8, null, 0.3], null, 'A5');
    $sheet->fromArray(['NISSAN????', 'NIS-001', 0.9, 0.7, null, 0.9, null, 0.4], null, 'A6');
    $sheet->fromArray(['TOYOTA', '?', 0.56, 1.494, null, 1.519, null, 0.385], null, 'A7');

    $kitko = $spreadsheet->createSheet();
    $kitko->setTitle('kitko');
    $kitko->setCellValue('A1', 1);

    $base = tempnam(sys_get_temp_dir(), 'new_items_import_');

    if ($base === false) {
        throw new RuntimeException('Unable to create temporary workbook.');
    }

    @unlink($base);
    $path = $base.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

test('removes previously imported question-mark placeholders and does not reimport them', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'JAPAN',
        'slug' => 'japan',
        'excel_sheet_name' => 'JAPAN',
    ]);

    $corrupt = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => '????',
        'serial_code' => '???',
        'weight_kg' => 0.7,
        'pt_ppm' => 500,
        'pd_ppm' => 769,
        'rh_ppm' => 208,
        'source' => 'excel_import',
    ]);

    $singleQuestionPlaceholder = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'TOYOTA',
        'serial_code' => '?',
        'weight_kg' => 0.56,
        'pt_ppm' => 1494,
        'pd_ppm' => 1519,
        'rh_ppm' => 385,
        'source' => 'excel_import',
    ]);

    $legitimateQuestionMark = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'TOYOTA',
        'serial_code' => '97B?5E212DE',
        'weight_kg' => 0.95,
        'pt_ppm' => 100,
        'pd_ppm' => 200,
        'rh_ppm' => 30,
        'source' => 'excel_import',
    ]);

    $corruptModel = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'NISSAN????',
        'serial_code' => 'NIS-001',
        'weight_kg' => 0.9,
        'pt_ppm' => 700,
        'pd_ppm' => 900,
        'rh_ppm' => 400,
        'source' => 'excel_import',
    ]);

    $path = newItemsImportWorkbook();

    try {
        $this->artisan('items:import-new', ['path' => $path])
            ->expectsOutputToContain('Import completed successfully.')
            ->assertExitCode(0);

        expect(Item::query()->whereKey($corrupt->id)->doesntExist())->toBeTrue()
            ->and(Item::query()->whereKey($singleQuestionPlaceholder->id)->doesntExist())->toBeTrue()
            ->and(Item::query()->whereKey($corruptModel->id)->doesntExist())->toBeTrue()
            ->and(Item::query()->whereKey($legitimateQuestionMark->id)->exists())->toBeTrue()
            ->and(Item::query()->where('serial_code', 'ABC-123')->exists())->toBeTrue()
            ->and(Item::query()->where('serial_code', 'NIS-001')->where('model', 'NISSAN')->exists())->toBeTrue()
            ->and(Item::query()->whereIn('serial_code', ['?', '???'])->doesntExist())->toBeTrue()
            ->and(Item::query()->where('model', 'like', '%??%')->doesntExist())->toBeTrue();
    } finally {
        @unlink($path);
    }
});
