<?php

$env = parse_ini_file(__DIR__.'/../.env', false, INI_SCANNER_RAW);
$pdo = new PDO('mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').';dbname='.($env['DB_DATABASE'] ?? '').';charset=utf8mb4', $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = "SELECT i.id,c.name car_group,i.model,i.serial_code,i.normalized_serial,i.weight_kg,i.pt_ppm,i.pd_ppm,i.rh_ppm,i.source,i.created_at,i.updated_at
FROM items i JOIN car_groups c ON c.id=i.car_group_id
WHERE i.source='excel_import' AND i.serial_code<>'' AND REPLACE(REPLACE(i.serial_code,'?',''),' ','')=''";
foreach ($pdo->query($sql) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}
echo 'total='.$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn().PHP_EOL;
