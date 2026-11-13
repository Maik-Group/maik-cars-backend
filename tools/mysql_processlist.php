<?php

$env = parse_ini_file(__DIR__.'/../.env', false, INI_SCANNER_RAW);
$pdo = new PDO('mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').';dbname='.($env['DB_DATABASE'] ?? '').';charset=utf8mb4', $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ($pdo->query('SHOW FULL PROCESSLIST') as $r) {
    echo implode(' | ', [$r['Id'], $r['User'], $r['db'] ?? '', $r['Command'], $r['Time'], $r['State'] ?? '', str_replace(["\r", "\n"], ' ', (string) ($r['Info'] ?? ''))]).PHP_EOL;
}
