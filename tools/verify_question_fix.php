<?php
$env=parse_ini_file(__DIR__.'/../.env',false,INI_SCANNER_RAW);
$pdo=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'').';charset=utf8mb4',$env['DB_USERNAME']??'',$env['DB_PASSWORD']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach([
'total'=>"SELECT COUNT(*) FROM items",
'excel'=>"SELECT COUNT(*) FROM items WHERE source='excel_import'",
'repeated_serial'=>"SELECT COUNT(*) FROM items WHERE source='excel_import' AND serial_code LIKE '%??%'",
'repeated_model'=>"SELECT COUNT(*) FROM items WHERE source='excel_import' AND model LIKE '%??%'",
'repeated_either'=>"SELECT COUNT(*) FROM items WHERE source='excel_import' AND (serial_code LIKE '%??%' OR model LIKE '%??%')",
'nissan_dirty'=>"SELECT COUNT(*) FROM items WHERE source='excel_import' AND model='NISSAN????'",
'nissan_clean'=>"SELECT COUNT(*) FROM items WHERE source='excel_import' AND model='NISSAN' AND serial_code='REX062C'"
] as $k=>$q) echo $k.'='.$pdo->query($q)->fetchColumn().PHP_EOL;
