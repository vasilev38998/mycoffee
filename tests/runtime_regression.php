<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_orders.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';
require_once dirname(__DIR__).'/inc/inventory.php';
require_once dirname(__DIR__).'/inc/cash_flow.php';
require_once dirname(__DIR__).'/inc/customer_push.php';
require_once dirname(__DIR__).'/inc/customer_legal.php';
require_once dirname(__DIR__).'/inc/customer_operations.php';
require_once dirname(__DIR__).'/inc/evotor_order_notifications.php';
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
ok((int)$status['available_version']>=33,'all migrations are visible');
ok(!$status['pending'],'no pending migrations after bootstrap');
ok(!$status['changed'],'no applied migration checksum drift');

$pdo->exec("DELETE FROM customer_push_queue; DELETE FROM customer_push_subscriptions; DELETE FROM customer_push_campaigns; DELETE FROM customer_loyalty_ledger; DELETE FROM evotor_order_push_log; DELETE FROM customer_order_legal_acceptance; DELETE FROM customer_order_access; DELETE FROM customer_payments; DELETE FROM online_order_items; DELETE FROM online_orders; DELETE FROM evotor_documents; DELETE FROM evotor_products; DELETE FROM evotor_sync_log; DELETE FROM evotor_connections; DELETE FROM customer_sessions; DELETE FROM customer_auth_codes; DELETE FROM customer_accounts; DELETE FROM customer_product_group_variants; DELETE FROM customer_product_groups; DELETE FROM customer_product_settings; DELETE FROM recipe_items; DELETE FROM inventory_movements; DELETE FROM products; DELETE FROM ingredients;");

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

throws(fn()=>customer_legal_validate_input(['inn'=>'123']),'legal settings reject malformed IP tax id');
customer_legal_save([
    'enabled'=>true,
    'seller_name'=>'Индивидуальный предприниматель Тестов Тест Тестович',
    'inn'=>'123456789012','ogrnip'=>'123456789012345','legal_address'=>'г. Иркутск, ул. Тестовая, 1','trade_address'=>'г. Иркутск, ул. Кофейная, 2',
    'bank_name'=>'Тест Банк','bik'=>'123456789','settlement_account'=>'12345678901234567890','correspondent_account'=>'09876543210987654321',
    'contact_email'=>'legal@example.test','contact_phone'=>'+7 900 000-00-00','offer_title'=>'Публичная оферта Kapouch','offer_version'=>'1.0','offer_date'=>'2026-09-06','offer_text'=>'','extra_terms'=>'Тестовое дополнительное условие.',
]);
$legal=customer_legal_public_data();
ok($legal['configured']===true,'legal page becomes configured after all IP requisites are saved');
ok(str_contains((string)$legal['offer']['text'],'Индивидуальный предприниматель Тестов'),'default public offer contains seller identity');
ok(str_contains((string)$legal['offer']['text'],'Тестовое дополнительное условие'),'default public offer includes configured extra terms');

customer_operations_save([
    'accepting'=>1,'schedule_enabled'=>1,'prep_minutes'=>15,'slot_interval'=>15,'slot_capacity'=>1,'last_order_minutes'=>15,'horizon_hours'=>4,
    'day_1_enabled'=>1,'day_1_open'=>'08:00','day_1_close'=>'10:00',
    'day_2_enabled'=>1,'day_2_open'=>'08:00','day_2_close'=>'10:00',
    'day_3_enabled'=>1,'day_3_open'=>'08:00','day_3_close'=>'10:00',
    'day_4_enabled'=>1,'day_4_open'=>'08:00','day_4_close'=>'10:00',
    'day_5_enabled'=>1,'day_5_open'=>'08:00','day_5_close'=>'10:00',
    'day_6_enabled'=>1,'day_6_open'=>'08:00','day_6_close'=>'10:00',
    'day_7_enabled'=>1,'day_7_open'=>'08:00','day_7_close'=>'10:00',
]);
$monday=new DateTimeImmutable('2026-09-07 07:00:00',new DateTimeZone(app_timezone()));$state=customer_operations_slots($monday);
ok($state['accepting']===true&&($state['slots'][0]['label']??'')==='Сегодня 08:00','working hours open first real slot at opening time');
$late=customer_operations_slots(new DateTimeImmutable('2026-09-07 09:50:00',new DateTimeZone(app_timezone())));
ok($late['accepting']===false,'last-order cutoff closes same-day slots before closing');
set_app_setting('customer_order_schedule_enabled','0');set_app_setting('customer_order_prep_minutes','15');set_app_setting('customer_order_slot_interval','15');set_app_setting('customer_order_slot_capacity','1');set_app_setting('customer_order_horizon_hours','2');set_app_setting('customer_orders_accepting','1');
$live=customer_operations_slots();ok($live['accepting']===true&&!empty($live['slots']),'unscheduled mode exposes rolling pickup slots');$firstSlot=(string)$live['slots'][0]['value'];
$pdo->prepare("INSERT INTO online_orders(external_id,order_number,source,status,fulfillment_type,total_amount,promised_at) VALUES(?,?,?,'new','pickup',100,?)")->execute(['capacity-test-'.bin2hex(random_bytes(5)),'CAP-1','runtime',$firstSlot]);
$afterCapacity=customer_operations_slots();ok(!in_array($firstSlot,array_column($afterCapacity['slots'],'value'),true),'full pickup slot is removed when capacity is reached');
set_app_setting('customer_orders_accepting','0');set_app_setting('customer_orders_pause_reason','Высокая загрузка');$paused=customer_operations_slots();ok($paused['accepting']===false&&$paused['message']==='Высокая загрузка','manual pause blocks new slots with customer message');set_app_setting('customer_orders_accepting','1');set_app_setting('customer_orders_pause_reason','');

$pdo->prepare('UPDATE products SET sale_price=250 WHERE id=?')->execute([$productId]);
$secondClient='runtime-second-'.bin2hex(random_bytes(6));
$order2=customer_order_create(['client_order_id'=>$secondClient,'name'=>'Другой','phone'=>'+7 900 987-65-43','fulfillment_type'=>'pickup','payment_method'=>'cash','items'=>[['product_id'=>$productId,'quantity'=>2,'modifiers'=>[]]]],null);
ok(abs((float)$order2['total_amount']-500.0)<0.001,'second independent checkout works');

[$userCipher,$userIv,$userTag]=evotor_encrypt_token('runtime-user-token');
$pdo->prepare('INSERT INTO evotor_connections(store_id,store_name,token_ciphertext,token_iv,token_tag,enabled) VALUES(?,?,?,?,?,1)')->execute(['runtime-store-'.bin2hex(random_bytes(4)),'Runtime Evotor',$userCipher,$userIv,$userTag]);
$evotorConnectionId=(int)$pdo->lastInsertId();
evotor_order_push_save($evotorConnectionId,[
    'enabled'=>true,
    'application_id'=>'11111111-1111-4111-8111-111111111111',
    'device_uuid'=>'22222222-2222-4222-8222-222222222222',
    'publisher_token'=>'runtime-publisher-token',
]);
$transportCalls=0;
$GLOBALS['kapouch_evotor_push_transport']=static function(string $url,string $token,array $request) use (&$transportCalls): array{
    $transportCalls++;
    if(!str_contains($url,'https://api.evotor.ru/api/apps/11111111-1111-4111-8111-111111111111/devices/22222222-2222-4222-8222-222222222222/push-notifications'))throw new RuntimeException('Unexpected Evotor push URL');
    if($token!=='runtime-publisher-token')throw new RuntimeException('Unexpected Evotor publisher token');
    if(($request['payload']['type']??'')!=='new_order'||empty($request['active_until']))throw new RuntimeException('Unexpected Evotor push payload');
    return ['id'=>'33333333-3333-4333-8333-333333333333','status'=>'ACCEPTED'];
};
$push1=evotor_order_notify_new((int)$order2['order_id']);
$push2=evotor_order_notify_new((int)$order2['order_id']);
ok($push1['sent']===1&&$push2['sent']===0&&$transportCalls===1,'Evotor new-order push is delivered once per order');
$pushLogCount=(int)$pdo->query('SELECT COUNT(*) FROM evotor_order_push_log WHERE order_id='.(int)$order2['order_id'])->fetchColumn();
ok($pushLogCount===1,'Evotor push delivery log is idempotent');
$pdo->prepare('UPDATE evotor_connections SET push_enabled=0 WHERE id=?')->execute([$evotorConnectionId]);
$thirdClient='runtime-third-'.bin2hex(random_bytes(6));
$order3=customer_order_create(['client_order_id'=>$thirdClient,'name'=>'Без push','phone'=>'+7 900 111-22-33','fulfillment_type'=>'pickup','payment_method'=>'cash','items'=>[['product_id'=>$productId,'quantity'=>1,'modifiers'=>[]]]],null);
$pushOff=evotor_order_notify_new((int)$order3['order_id']);
ok($pushOff['queued']===0&&$transportCalls===1,'server Evotor notification toggle stops delivery');
unset($GLOBALS['kapouch_evotor_push_transport']);
$pdo->prepare('DELETE FROM evotor_connections WHERE id=?')->execute([$evotorConnectionId]);

throws(fn()=>customer_order_create(['client_order_id'=>'bad','name'=>'X','phone'=>'+79001234567','payment_method'=>'cash','items'=>[['product_id'=>$productId,'quantity'=>1]]],null),'checkout rejects weak client order id');
$large=customer_order_create(['client_order_id'=>'valid-id-123456','name'=>'X','phone'=>'+79001234567','payment_method'=>'cash','items'=>[['product_id'=>$productId,'quantity'=>51]]],null);
ok(abs((float)$large['total_amount']-5000.0)<0.001,'checkout safely caps one line to 20 units');

echo "RUNTIME REGRESSION PASSED\n";
