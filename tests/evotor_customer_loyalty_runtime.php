<?php
declare(strict_types=1);

if(!function_exists('db'))require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/evotor_customer_loyalty.php';

function evloy_ok(bool $condition,string $message): void{if(!$condition)throw new RuntimeException('ASSERT FAILED: '.$message);echo "OK: {$message}\n";}

$pdo=db();
$store='runtime-store-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO evotor_connections(store_id,store_name,token_ciphertext,token_iv,token_tag,enabled) VALUES(?,?,'runtime','runtime','runtime',1)")->execute([$store,'Runtime Evotor']);
$connectionId=(int)$pdo->lastInsertId();
$connection=['id'=>$connectionId];

$phoneA='+7977'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT);
$phoneB='+7966'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT);
$pdo->prepare('INSERT INTO customer_accounts(phone,name) VALUES(?,?)')->execute([$phoneA,'Runtime Customer A']);$customerA=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO customer_accounts(phone,name) VALUES(?,?)')->execute([$phoneB,'Runtime Customer B']);$customerB=(int)$pdo->lastInsertId();

$scanA=evotor_customer_loyalty_register_scan($connectionId,$customerA,'runtime-device');
evloy_ok((int)$scanA['scan_id']>0,'first customer scan is registered');
$scanB=evotor_customer_loyalty_register_scan($connectionId,$customerB,'runtime-device');
evloy_ok((int)$scanB['scan_id']>(int)$scanA['scan_id'],'second customer scan is registered');

$stmt=$pdo->prepare('SELECT id,status,expires_at_unix FROM evotor_customer_scans WHERE id IN (?,?) ORDER BY id');$stmt->execute([(int)$scanA['scan_id'],(int)$scanB['scan_id']]);$rows=$stmt->fetchAll();
evloy_ok(count($rows)===2&&(string)$rows[0]['status']==='cancelled','new scan supersedes previous pending customer on same terminal');
evloy_ok((string)$rows[1]['status']==='pending','latest scan remains pending');
evloy_ok((int)$rows[1]['expires_at_unix']<=time()+1801,'scan validity is limited to thirty minutes');

$pdo->prepare("INSERT INTO sales(sold_at,total_amount,payment_method,note) VALUES(NOW(),100,'card','evotor loyalty runtime')")->execute();$sale1=(int)$pdo->lastInsertId();
$document1=['type'=>'SELL','id'=>'runtime-doc-b-'.bin2hex(random_bytes(4)),'number'=>101,'close_date'=>date('c'),'body'=>['result_sum'=>100]];
$pdo->beginTransaction();$attached=evotor_customer_loyalty_attach_sale($pdo,$connection,$document1,$sale1);$pdo->commit();
evloy_ok(is_array($attached)&&(int)$attached['customer_id']===$customerB,'sale attaches only to latest scanned customer');

$pdo->prepare("INSERT INTO sales(sold_at,total_amount,payment_method,note) VALUES(NOW(),120,'card','evotor loyalty runtime no scan')")->execute();$sale2=(int)$pdo->lastInsertId();
$document2=['type'=>'SELL','id'=>'runtime-doc-none-'.bin2hex(random_bytes(4)),'number'=>102,'close_date'=>date('c'),'body'=>['result_sum'=>120]];
$pdo->beginTransaction();$unlinked=evotor_customer_loyalty_attach_sale($pdo,$connection,$document2,$sale2);$pdo->commit();
evloy_ok($unlinked===null,'a consumed/cancelled scan cannot leak into the next sale');

$pending=(int)$pdo->query("SELECT COUNT(*) FROM evotor_customer_scans WHERE connection_id={$connectionId} AND status='pending'")->fetchColumn();
evloy_ok($pending===0,'no stale pending customer remains after sale consumption');

echo "EVOTOR CUSTOMER LOYALTY RUNTIME PASSED\n";
