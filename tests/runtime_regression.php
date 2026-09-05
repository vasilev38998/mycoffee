<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_orders.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';
require_once dirname(__DIR__).'/inc/inventory.php';
require_once dirname(__DIR__).'/inc/cash_flow.php';
require_once dirname(__DIR__).'/inc/customer_push.php';
require_once dirname(__DIR__).'/inc/audit.php';

function ok(bool $condition,string $message): void{
    if(!$condition)throw new RuntimeException('ASSERT FAILED: '.$message);
    echo "OK: {$message}\n";
}
function throws(callable $fn,string $message): void{
    try{$fn();}catch(Throwable $e){echo "OK: {$message} (".get_class($e).")\n";return;}
    throw new RuntimeException('ASSERT FAILED: expected exception: '.$message);
}

$pdo=db();
$status=kapouch_migration_status($pdo);
ok((int)$status['available_version']>=31,'all migrations are visible');
ok(!$status['pending'],'no pending migrations after bootstrap');
ok(!$status['changed'],'no applied migration checksum drift');

$pdo->exec("DELETE FROM customer_push_queue; DELETE FROM customer_push_subscriptions; DELETE FROM customer_push_campaigns; DELETE FROM customer_loyalty_ledger; DELETE FROM customer_order_access; DELETE FROM customer_payments; DELETE FROM online_order_items; DELETE FROM online_orders; DELETE FROM customer_sessions; DELETE FROM customer_auth_codes; DELETE FROM customer_accounts; DELETE FROM customer_product_group_variants; DELETE FROM customer_product_groups; DELETE FROM customer_product_settings; DELETE FROM recipe_items; DELETE FROM inventory_movements; DELETE FROM products; DELETE FROM ingredients;");

$pdo->prepare("INSERT INTO products(name,category,sale_price,active) VALUES('Капучино тест','Кофе',250,1)")->execute();
$productId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity) VALUES('Молоко тест','ml',100,1000,1000,100)")->execute();
$ingredientId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO recipe_items(product_id,ingredient_id,quantity) VALUES(?,?,?)')->execute([$productId,$ingredientId,100]);

$clientId='runtime-test-'.bin2hex(random_bytes(6));
$payload=['client_order_id'=>$clientId,'name'=>'Тест','phone'=>'+7 900 123-45-67','comment'=>'runtime','fulfillment_type'=>'pickup','payment_method'=>'cash','items'=>[['product_id'=>$productId,'quantity'=>1,'modifiers'=>[]]]];
$order1=customer_order_create($payload,null);
ok((int)$order1['order_id']>0,'cash customer order is created');
ok(abs((float)$order1['total_amount']-250.0)<0.001,'order total uses catalog price');
ok(strlen((string)$order1['tracking_token'])===64,'tracking token is generated');

$pdo->prepare('UPDATE products SET sale_price=999 WHERE id=?')->execute([$productId]);
$orderRetry=customer_order_create($payload,null);
ok((int)$orderRetry['order_id']===(int)$order1['order_id'],'checkout retry is idempotent');
ok(abs((float)$orderRetry['total_amount']-250.0)<0.001,'idempotent retry keeps original total');
ok((string)$orderRetry['order_number']===(string)$order1['order_number'],'idempotent retry keeps original order number');

$pdo->prepare('UPDATE products SET sale_price=250 WHERE id=?')->execute([$productId]);
$account=$pdo->prepare('SELECT customer_id FROM customer_order_access WHERE order_id=?');$account->execute([(int)$order1['order_id']]);$customerId=(int)$account->fetchColumn();
ok($customerId>0,'customer account is linked to order');

online_orders_transition((int)$order1['order_id'],'ready');
online_orders_transition((int)$order1['order_id'],'completed');
$earned=customer_loyalty_balance($customerId);
ok($earned>0,'loyalty is earned on completed order');
$reversed=customer_loyalty_reverse_order((int)$order1['order_id']);
ok(abs($reversed-$earned)<0.001,'full refund reversal removes earned loyalty');
ok(abs(customer_loyalty_balance($customerId))<0.001,'loyalty balance returns to zero after reversal');
ok(abs(customer_loyalty_reverse_order((int)$order1['order_id']))<0.001,'loyalty reversal is idempotent');

$before=(float)$pdo->query('SELECT stock_quantity FROM ingredients WHERE id='.$ingredientId)->fetchColumn();
$first=apply_inventory_movement($ingredientId,'manual',-50,date('Y-m-d H:i:s'),'runtime_test',777,'runtime duplicate guard');
$second=apply_inventory_movement($ingredientId,'manual',-50,date('Y-m-d H:i:s'),'runtime_test',777,'runtime duplicate guard');
$after=(float)$pdo->query('SELECT stock_quantity FROM ingredients WHERE id='.$ingredientId)->fetchColumn();
ok($first===true&&$second===false,'duplicate inventory movement is ignored');
ok(abs(($before-50)-$after)<0.001,'duplicate inventory movement changes stock only once');

throws(fn()=>cashflow_manual_entry(0,'out','expense',10,date('Y-m-d H:i:s'),'Тест','Тест'),'cash flow rejects missing account');
throws(fn()=>cashflow_manual_entry(1,'out','expense',-1,date('Y-m-d H:i:s'),'Тест','Тест'),'cash flow rejects negative amount');
throws(fn()=>kapouch_public_https_target('https://127.0.0.1/private'),'SSRF guard rejects loopback target');
throws(fn()=>customer_push_target_url('javascript:alert(1)'),'push target rejects javascript URL');
ok(customer_push_target_url('./#profile')==='./#profile','push target accepts local PWA route');

$sanitized=audit_sanitize(['secret_key'=>'abc','smsru_api_id'=>'xyz','normal'=>'ok','nested'=>['authorization'=>'Bearer x']]);
ok(($sanitized['secret_key']??'')==='[скрыто]'&&($sanitized['smsru_api_id']??'')==='[скрыто]'&&($sanitized['normal']??'')==='ok','audit sanitizer removes secret fields');
ok(($sanitized['nested']['authorization']??'')==='[скрыто]','audit sanitizer removes nested authorization');

$pdo->prepare('UPDATE products SET sale_price=250 WHERE id=?')->execute([$productId]);
$secondClient='runtime-second-'.bin2hex(random_bytes(6));
$order2=customer_order_create(['client_order_id'=>$secondClient,'name'=>'Другой','phone'=>'+7 900 987-65-43','fulfillment_type'=>'pickup','payment_method'=>'cash','items'=>[['product_id'=>$productId,'quantity'=>2,'modifiers'=>[]]]],null);
ok(abs((float)$order2['total_amount']-500.0)<0.001,'second independent checkout works');

throws(fn()=>customer_order_create(['client_order_id'=>'bad','name'=>'X','phone'=>'+79001234567','payment_method'=>'cash','items'=>[['product_id'=>$productId,'quantity'=>1]]],null),'checkout rejects weak client order id');
$large=customer_order_create(['client_order_id'=>'valid-id-123456','name'=>'X','phone'=>'+79001234567','payment_method'=>'cash','items'=>[['product_id'=>$productId,'quantity'=>51]]],null);
ok(abs((float)$large['total_amount']-5000.0)<0.001,'checkout safely caps one line to 20 units');

echo "RUNTIME REGRESSION PASSED\n";
