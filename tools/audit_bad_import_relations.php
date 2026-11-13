<?php

$env = parse_ini_file(__DIR__.'/../.env', false, INI_SCANNER_RAW);
$pdo = new PDO('mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').';dbname='.($env['DB_DATABASE'] ?? '').';charset=utf8mb4', $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ids = $pdo->query("SELECT id FROM items WHERE source='excel_import' AND serial_code<>'' AND REPLACE(REPLACE(serial_code,'?',''),' ','')=''")->fetchAll(PDO::FETCH_COLUMN);
echo 'bad_ids='.count($ids).PHP_EOL;
if (! $ids) {
    exit;
}
$ph = implode(',', array_fill(0, count($ids), '?'));
$checks = [
    'media' => ["SELECT COUNT(*) FROM media WHERE model_type='App\\Models\\Item' AND model_id IN ($ph)", $ids],
    'extra_codes' => ["SELECT COUNT(*) FROM extra_codes WHERE item_id IN ($ph)", $ids],
    'price_calculations' => ["SELECT COUNT(*) FROM price_calculations WHERE item_id IN ($ph)", $ids],
    'saved_items' => ["SELECT COUNT(*) FROM saved_items WHERE item_id IN ($ph)", $ids],
    'duplicate_reviews' => ["SELECT COUNT(*) FROM duplicate_reviews WHERE existing_item_id IN ($ph)", $ids],
    'mapping_item' => ["SELECT COUNT(*) FROM item_filter_mappings WHERE item_id IN ($ph)", $ids],
    'mapping_filter' => ["SELECT COUNT(*) FROM item_filter_mappings WHERE filter_item_id IN ($ph)", $ids],
];
foreach ($checks as $name => [$sql,$params]) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    echo $name.'='.$st->fetchColumn().PHP_EOL;
}
