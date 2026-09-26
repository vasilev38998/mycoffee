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

// A stale, cheaper duplicate must not become the automatic sixth-drink cap
// when the current Evotor-mapped cappuccino is more expensive.
$pdo->prepare("INSERT INTO products(name,category,sale_price,active) VALUES('Капучино 0,2 stale runtime','Кофе',170,1)")->execute();$staleCapId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products(name,category,sale_price,active) VALUES('Капучино 0,2 current runtime','Кофе',180,1)")->execute();$currentCapId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO evotor_products(connection_id,evotor_product_id,local_product_id,name,price,cost_price,raw_json) VALUES(?,?,?,?,180,0,'{}')")->execute([$connectionId,'runtime-current-cap',$currentCapId,'Капучино 0,2 current runtime']);
set_app_setting('customer_sixth_drink_enabled','1');
set_app_setting('customer_sixth_drink_paid_count','5');
set_app_setting('customer_sixth_drink_products_mode','auto');
set_app_setting('customer_sixth_drink_product_ids','');
set_app_setting('customer_sixth_drink_reference_product_id','0');
set_app_setting('customer_sixth_drink_started_at','2020-01-01 00:00:00');
customer_drink_loyalty_runtime_cache_reset();
$reference=customer_drink_loyalty_reference_product();
evloy_ok((int)($reference['id']??0)===$currentCapId&&abs((float)($reference['price']??0)-180.0)<0.001,'auto gift cap prefers current Evotor-mapped 180-ruble cappuccino over stale 170-ruble duplicate');

// Full Evotor sale -> sixth-drink gift -> PAYBACK must restore both points and
// exact drink-loyalty state without a second client scan.
set_app_setting('customer_loyalty_percent','5');
set_app_setting('customer_sixth_drink_products_mode','selected');
set_app_setting('customer_sixth_drink_product_ids',(string)$currentCapId);
set_app_setting('customer_sixth_drink_reference_product_id',(string)$currentCapId);
customer_drink_loyalty_runtime_cache_reset();
$phoneC='+7955'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT);
$pdo->prepare('INSERT INTO customer_accounts(phone,name) VALUES(?,?)')->execute([$phoneC,'Runtime Refund Customer']);$customerC=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO customer_drink_loyalty_ledger(customer_id,operation_key,source_type,source_id,source_line_id,product_id,stamp_delta,reward_delta,reward_value,note) VALUES(?,?,?,?,?,?,5,0,0,'runtime seed')")->execute([$customerC,'runtime-refund-seed-'.bin2hex(random_bytes(4)),'runtime','seed','seed',$currentCapId]);
$seedSummary=customer_drink_loyalty_summary($customerC);evloy_ok((int)$seedSummary['available_rewards']===1,'refund fixture starts with one available sixth-drink gift');
$scanC=evotor_customer_loyalty_register_scan($connectionId,$customerC,'runtime-refund-device');

$pdo->prepare("INSERT INTO sales(sold_at,total_amount,payment_method,note) VALUES(NOW(),180,'card','evotor gift sale runtime')")->execute();$giftSaleId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO sale_items(sale_id,product_id,quantity,unit_price,unit_cost) VALUES(?,?,?,?,0)')->execute([$giftSaleId,$currentCapId,2,180]);
$sellDocId='runtime-gift-sell-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO customer_evotor_reward_pending(connection_id,receipt_uuid,customer_id,product_id,reward_value,status,quoted_at,applied_at,expires_at) VALUES(?,?,?,?,180,'applied',NOW(),NOW(),DATE_ADD(NOW(),INTERVAL 2 HOUR))")->execute([$connectionId,$sellDocId,$customerC,$currentCapId]);
$sellDocument=['type'=>'SELL','id'=>$sellDocId,'number'=>201,'close_date'=>date('c'),'body'=>['result_sum'=>180]];
$pdo->beginTransaction();$giftAttached=evotor_customer_loyalty_attach_sale($pdo,$connection,$sellDocument,$giftSaleId);$pdo->commit();
evloy_ok(is_array($giftAttached)&&abs((float)($giftAttached['drink_gift']['discount']??0)-180.0)<0.001,'Evotor sale finalizes full 180-ruble sixth-drink discount');
$afterSale=customer_drink_loyalty_summary($customerC);
evloy_ok((int)$afterSale['paid_stamps']===6&&(int)$afterSale['available_rewards']===0&&(int)$afterSale['progress']===1,'gift sale consumes reward and counts only the paid drink');
$balanceAfterSale=(float)$pdo->query('SELECT loyalty_balance FROM customer_accounts WHERE id='.(int)$customerC)->fetchColumn();
evloy_ok(abs($balanceAfterSale-9.0)<0.001,'Evotor sale accrues points from final paid amount');

$pdo->prepare("INSERT INTO sales(sold_at,total_amount,payment_method,note) VALUES(NOW(),-180,'card','evotor payback runtime')")->execute();$refundSaleId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO sale_items(sale_id,product_id,quantity,unit_price,unit_cost) VALUES(?,?,?,?,0)')->execute([$refundSaleId,$currentCapId,-2,180]);
$paybackId='runtime-payback-'.bin2hex(random_bytes(4));
$payback=['type'=>'PAYBACK','id'=>$paybackId,'number'=>301,'close_date'=>date('c'),'body'=>['result_sum'=>180,'base_document_id'=>$sellDocId]];
$pdo->beginTransaction();$refund=evotor_customer_loyalty_attach_payback($pdo,$connection,$payback,$refundSaleId);$pdo->commit();
evloy_ok(is_array($refund)&&!empty($refund['full_refund']),'PAYBACK is linked to original customer sale as a full refund');
evloy_ok(abs((float)$refund['loyalty_reversed']-9.0)<0.001,'full PAYBACK reverses points earned on original sale');
evloy_ok((int)$refund['drink_stamps_reversed']===2&&!empty($refund['gift_restored']),'full PAYBACK reverses returned drink stamps and restores consumed gift');
$afterRefund=customer_drink_loyalty_summary($customerC);
evloy_ok((int)$afterRefund['paid_stamps']===5&&(int)$afterRefund['available_rewards']===1&&(int)$afterRefund['progress']===0,'full PAYBACK restores exact pre-sale sixth-drink state');
$balanceAfterRefund=(float)$pdo->query('SELECT loyalty_balance FROM customer_accounts WHERE id='.(int)$customerC)->fetchColumn();
evloy_ok(abs($balanceAfterRefund)<0.001,'full PAYBACK restores pre-sale points balance');
$pdo->beginTransaction();$refundAgain=evotor_customer_loyalty_attach_payback($pdo,$connection,$payback,$refundSaleId);$pdo->commit();
evloy_ok($refundAgain===null,'PAYBACK loyalty reconciliation is idempotent');

// This test is chained into the wider runtime suite. Remove its fake enabled
// Evotor connection so later cron smoke tests never try to decrypt dummy tokens.
$pdo->prepare('DELETE FROM evotor_connections WHERE id=?')->execute([$connectionId]);
evloy_ok((int)$pdo->query("SELECT COUNT(*) FROM evotor_connections WHERE id={$connectionId}")->fetchColumn()===0,'runtime Evotor fixture is cleaned before later cron checks');

echo "EVOTOR CUSTOMER LOYALTY RUNTIME PASSED\n";
