<?php
$env = parse_ini_file(__DIR__.'/../.env', false, INI_SCANNER_RAW);
$pdo = new PDO(
    'mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'').';charset=utf8mb4',
    $env['DB_USERNAME']??'',
    $env['DB_PASSWORD']??'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
$queries = [
 'excel_serial_only_questions' => "SELECT COUNT(*) FROM items WHERE source='excel_import' AND serial_code<>'' AND REPLACE(REPLACE(serial_code,'?',''),' ','')=''",
 'excel_model_only_questions' => "SELECT COUNT(*) FROM items WHERE source='excel_import' AND model<>'' AND REPLACE(REPLACE(model,'?',''),' ','')=''",
 'excel_either_only_questions' => "SELECT COUNT(*) FROM items WHERE source='excel_import' AND ((serial_code<>'' AND REPLACE(REPLACE(serial_code,'?',''),' ','')='') OR (model<>'' AND REPLACE(REPLACE(model,'?',''),' ','')=''))",
 'excel_contains_questions' => "SELECT COUNT(*) FROM items WHERE source='excel_import' AND (serial_code LIKE '%?%' OR model LIKE '%?%')",
];
foreach($queries as $k=>$q) echo $k.'='.$pdo->query($q)->fetchColumn().PHP_EOL;
