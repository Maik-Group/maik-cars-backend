<?php
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$env = parse_ini_file(__DIR__.'/../.env', false, INI_SCANNER_RAW);
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$db = $env['DB_DATABASE'] ?? '';
$user = $env['DB_USERNAME'] ?? '';
$pass = $env['DB_PASSWORD'] ?? '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "DATABASE\n";
foreach ([
    'total_items' => "SELECT COUNT(*) FROM items",
    'excel_items' => "SELECT COUNT(*) FROM items WHERE source='excel_import'",
    'bad_serial_any' => "SELECT COUNT(*) FROM items WHERE serial_code LIKE '%?%'",
    'bad_model_any' => "SELECT COUNT(*) FROM items WHERE model LIKE '%?%'",
    'bad_excel_rows' => "SELECT COUNT(*) FROM items WHERE source='excel_import' AND (serial_code LIKE '%?%' OR model LIKE '%?%')",
    'all_question_serial' => "SELECT COUNT(*) FROM items WHERE source='excel_import' AND serial_code REGEXP '^\\?+$'",
    'all_question_model' => "SELECT COUNT(*) FROM items WHERE source='excel_import' AND model REGEXP '^\\?+$'",
] as $label => $sql) {
    echo $label.'='.$pdo->query($sql)->fetchColumn().PHP_EOL;
}

echo "CONNECTION\n";
foreach ($pdo->query("SHOW VARIABLES WHERE Variable_name IN ('character_set_client','character_set_connection','character_set_database','character_set_results','collation_connection','collation_database')") as $row) {
    echo $row['Variable_name'].'='.$row['Value'].PHP_EOL;
}

echo "ITEM_COLUMNS\n";
foreach ($pdo->query("SHOW FULL COLUMNS FROM items WHERE Field IN ('serial_code','model','details')") as $row) {
    echo $row['Field'].'|'.$row['Type'].'|'.($row['Collation'] ?? '').PHP_EOL;
}

echo "BAD_SAMPLE\n";
$sql = "SELECT i.id, c.name AS car_group, i.serial_code, i.model, i.weight_kg, i.pt_ppm, i.pd_ppm, i.rh_ppm,
               HEX(i.serial_code) serial_hex, HEX(i.model) model_hex, i.source
        FROM items i
        JOIN car_groups c ON c.id=i.car_group_id
        WHERE i.source='excel_import' AND (i.serial_code LIKE '%?%' OR i.model LIKE '%?%')
        ORDER BY i.created_at DESC
        LIMIT 30";
foreach ($pdo->query($sql) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}

$path = __DIR__.'/../storage/app/imports/maik_2_101857.xlsx';
echo "EXCEL_FILE=".(is_file($path) ? 'yes' : 'no').PHP_EOL;
if (is_file($path)) {
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(false);
    $spreadsheet = $reader->load($path);
    $questionCells = 0;
    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        if (strtolower(trim($sheet->getTitle())) === 'kitko') continue;
        for ($r=4; $r <= $sheet->getHighestDataRow(); $r++) {
            $model = (string) $sheet->getCellByColumnAndRow(1,$r)->getValue();
            $serial = (string) $sheet->getCellByColumnAndRow(2,$r)->getValue();
            if (str_contains($model,'?') || str_contains($serial,'?')) {
                $questionCells++;
                if ($questionCells <= 30) {
                    echo 'EXCEL_BAD|'.$sheet->getTitle().'|'.$r.'|'.json_encode([$model,$serial], JSON_UNESCAPED_UNICODE).PHP_EOL;
                }
            }
        }
    }
    echo 'excel_rows_containing_question_mark='.$questionCells.PHP_EOL;
    $spreadsheet->disconnectWorksheets();
}
