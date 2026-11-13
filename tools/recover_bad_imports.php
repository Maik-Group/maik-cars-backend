<?php

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$env = parse_ini_file(__DIR__.'/../.env', false, INI_SCANNER_RAW);
$pdo = new PDO(
    'mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').';dbname='.($env['DB_DATABASE'] ?? '').';charset=utf8mb4',
    $env['DB_USERNAME'] ?? '',
    $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$brandMap = [
    'MERCEDES' => 'MERCEDES', 'BMW' => 'BMW', 'VW' => 'AUDI VW', 'FIAT' => 'FIAT', 'FORD' => 'FORD', 'OPEL' => 'OPEL',
    'PSA' => 'PSA', 'RENAULT' => 'RENAULT', 'TOYOTA' => 'JAPAN', 'SUZUKI' => 'JAPAN', 'SUBARU' => 'JAPAN', 'HONDA' => 'JAPAN',
    'NISSAN' => 'JAPAN', 'MITSUBISHI' => 'JAPAN', 'MAZDA' => 'JAPAN', 'KIA HUNDAY' => 'KOREA', 'VOLVO' => 'SWEDEN',
    'SAAB' => 'SWEDEN', 'CHRAISLER' => 'USA', 'ROVER' => 'ROVER&LAND ROVER', 'SEVEL' => 'FIAT', 'JAGUAR' => 'JAGUAR', 'РАЗНИ' => 'RAZNI',
];

$bad = [];
$sql = "SELECT i.id, c.name car_group, i.weight_kg, i.pt_ppm, i.pd_ppm, i.rh_ppm, i.serial_code, i.model
        FROM items i JOIN car_groups c ON c.id=i.car_group_id
        WHERE i.source='excel_import'
          AND i.serial_code<>'' AND REPLACE(REPLACE(i.serial_code,'?',''),' ','')=''";
foreach ($pdo->query($sql) as $row) {
    $key = sprintf('%s|%.3f|%.4f|%.4f|%.4f',
        strtoupper($row['car_group']),
        (float) $row['weight_kg'],
        (float) ($row['pt_ppm'] ?? 0),
        (float) ($row['pd_ppm'] ?? 0),
        (float) ($row['rh_ppm'] ?? 0)
    );
    $bad[$key][] = $row;
}

$reader = IOFactory::createReaderForFile(__DIR__.'/../excel/katalog.xlsx');
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load(__DIR__.'/../excel/katalog.xlsx');

$matches = [];
foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
    $title = trim($sheet->getTitle());
    if (! str_starts_with($title, 'New ')) {
        continue;
    }
    $raw = trim(substr($title, 4));
    $upper = mb_strtoupper($raw, 'UTF-8');
    $group = $brandMap[$upper] ?? $upper;

    for ($r = 3; $r <= $sheet->getHighestDataRow(); $r++) {
        $weight = $sheet->getCellByColumnAndRow(5, $r)->getCalculatedValue();
        $pt = $sheet->getCellByColumnAndRow(9, $r)->getCalculatedValue();
        $pd = $sheet->getCellByColumnAndRow(10, $r)->getCalculatedValue();
        $rh = $sheet->getCellByColumnAndRow(11, $r)->getCalculatedValue();

        if (! is_numeric($weight)) {
            continue;
        }
        $key = sprintf('%s|%.3f|%.4f|%.4f|%.4f',
            $group,
            (float) $weight,
            is_numeric($pt) ? (float) $pt : 0,
            is_numeric($pd) ? (float) $pd : 0,
            is_numeric($rh) ? (float) $rh : 0
        );
        if (! isset($bad[$key])) {
            continue;
        }

        $matches[$key][] = [
            'sheet' => $title,
            'row' => $r,
            'model' => (string) $sheet->getCellByColumnAndRow(1, $r)->getValue(),
            'serial' => (string) $sheet->getCellByColumnAndRow(2, $r)->getValue(),
        ];
    }
}
$spreadsheet->disconnectWorksheets();

$resolved = 0;
$ambiguous = 0;
$missing = 0;
$report = [];
foreach ($bad as $key => $dbRows) {
    $m = $matches[$key] ?? [];
    if (count($m) === 1) {
        $resolved += count($dbRows);
    } elseif (count($m) > 1) {
        $ambiguous += count($dbRows);
    } else {
        $missing += count($dbRows);
    }
    $report[] = ['key' => $key, 'db' => $dbRows, 'matches' => $m];
}
file_put_contents(__DIR__.'/recover_bad_imports_report.json', json_encode([
    'bad_rows' => array_sum(array_map('count', $bad)),
    'resolved' => $resolved, 'ambiguous' => $ambiguous, 'missing' => $missing, 'rows' => $report,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo 'bad_rows='.array_sum(array_map('count', $bad)).PHP_EOL;
echo "resolved=$resolved".PHP_EOL;
echo "ambiguous=$ambiguous".PHP_EOL;
echo "missing=$missing".PHP_EOL;
