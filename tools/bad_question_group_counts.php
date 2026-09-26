<?php
$env=parse_ini_file(__DIR__.'/../.env',false,INI_SCANNER_RAW);
$pdo=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'').';charset=utf8mb4',$env['DB_USERNAME']??'',$env['DB_PASSWORD']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$sql="SELECT c.name car_group, COUNT(*) cnt
FROM items i JOIN car_groups c ON c.id=i.car_group_id
WHERE i.source='excel_import' AND i.serial_code<>'' AND REPLACE(REPLACE(i.serial_code,'?',''),' ','')=''
GROUP BY c.name ORDER BY cnt DESC";
foreach($pdo->query($sql) as $r) echo $r['car_group'].'='.$r['cnt'].PHP_EOL;
