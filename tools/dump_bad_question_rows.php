<?php
$env=parse_ini_file(__DIR__.'/../.env',false,INI_SCANNER_RAW);
$pdo=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'').';charset=utf8mb4',$env['DB_USERNAME']??'',$env['DB_PASSWORD']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$sql="SELECT i.id,c.name car_group,i.weight_kg,i.pt_ppm,i.pd_ppm,i.rh_ppm,i.serial_code,i.model
FROM items i JOIN car_groups c ON c.id=i.car_group_id
WHERE i.source='excel_import' AND i.serial_code<>'' AND REPLACE(REPLACE(i.serial_code,'?',''),' ','')=''
ORDER BY c.name,i.weight_kg,i.pt_ppm,i.pd_ppm,i.rh_ppm";
$rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__.'/bad_question_rows.json',json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo 'rows='.count($rows).PHP_EOL;
