<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/online_orders.php';
require_once dirname(__DIR__).'/inc/evotor_order_notifications.php';

function action_ok(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException('ASSERT FAILED: '.$message);
    echo "OK: {$message}\n";
}

function action_throws(callable $fn,string $message): void
{
    try{$fn();}catch(Throwable $e){echo "OK: {$message}\n";return;}
    throw new RuntimeException('ASSERT FAILED: expected exception: '.$message);
}

$pdo=db();
$pdo->exec('DELETE FROM evotor_order_push_log; DELETE FROM evotor_connections; DELETE FROM online_order_items; DELETE FROM online_orders;');

[$userCipher,$userIv,$userTag]=evotor_encrypt_token('runtime-user-token');
[$pushCipher,$pushIv,$pushTag]=evotor_encrypt_token('runtime-publisher-token');
$stmt=$pdo->prepare("INSERT INTO evotor_connections(store_id,store_name,token_ciphertext,token_iv,token_tag,enabled,push_enabled,push_application_id,push_device_uuid,push_token_ciphertext,push_token_iv,push_token_tag) VALUES(?,?,?,?,?,1,1,?,?,?,?,?)");
$stmt->execute([
    'runtime-store-actions','Runtime Actions',$userCipher,$userIv,$userTag,
    '11111111-1111-4111-8111-111111111111','22222222-2222-4222-8222-222222222222',$pushCipher,$pushIv,$pushTag,
]);
$connectionId=(int)$pdo->lastInsertId();
action_ok($connectionId>0,'Evotor connection is seeded');

$makeOrder=static function(string $number) use ($pdo): int {
    $external='actions-'.strtolower($number).'-'.bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO online_orders(external_id,order_number,source,status,fulfillment_type,payment_status,payment_method,total_amount) VALUES(?,?,'customer-web','new','pickup','unpaid','cash',210)")->execute([$external,$number]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO online_order_items(order_id,product_name,quantity,unit_price,line_total,sort_order) VALUES(?,'Капучино',1,210,210,1)")->execute([$id]);
    return $id;
};

$orderId=$makeOrder('ACT-1');
$token=evotor_order_action_token($connectionId,$orderId,time()+300);
$claims=evotor_order_action_claims($token);
action_ok(is_array($claims)&&(int)$claims['connection_id']===$connectionId&&(int)$claims['order_id']===$orderId,'signed action token binds connection and order');
action_ok(evotor_order_action_claims($token.'x')===null,'tampered action token is rejected');
action_ok(evotor_order_action_claims(evotor_order_action_token($connectionId,$orderId,time()-120))===null,'expired action token is rejected');

$accepted=evotor_order_action_apply($orderId,'accept');
action_ok($accepted['status']==='preparing','accept moves new order to preparing');
$acceptedAgain=evotor_order_action_apply($orderId,'accept');
action_ok($acceptedAgain['status']==='preparing','accept action is idempotent');
$ready=evotor_order_action_apply($orderId,'ready');
action_ok($ready['status']==='ready','ready action moves preparing order to ready');
$readyAgain=evotor_order_action_apply($orderId,'ready');
action_ok($readyAgain['status']==='ready','ready action is idempotent');

$newOrder=$makeOrder('ACT-2');
action_throws(fn()=>evotor_order_action_apply($newOrder,'ready'),'new order cannot skip accept step');

$_SERVER['HTTP_HOST']='kapouch.test';
action_ok(evotor_order_action_public_url()==='https://kapouch.store/api/evotor_order_action.php','action URL is canonical HTTPS endpoint accepted by Evotor client');

$pushOrder=$makeOrder('ACT-3');
$transportCalls=0;
$GLOBALS['kapouch_evotor_push_transport']=static function(string $url,string $publisherToken,array $request) use (&$transportCalls,$connectionId,$pushOrder): array {
    $transportCalls++;
    action_ok($publisherToken==='runtime-publisher-token','publisher token stays in server transport');
    $payload=$request['payload']??[];
    action_ok(($payload['type']??'')==='new_order','new order payload type is preserved');
    action_ok(($payload['action_url']??'')==='https://kapouch.store/api/evotor_order_action.php','push includes canonical HTTPS action URL');
    $claims=evotor_order_action_claims((string)($payload['action_token']??''));
    action_ok(is_array($claims)&&(int)$claims['connection_id']===$connectionId&&(int)$claims['order_id']===$pushOrder,'push action token is scoped to terminal connection and order');
    action_ok(!str_contains(json_encode($payload,JSON_UNESCAPED_SLASHES),'runtime-publisher-token'),'publisher secret is never placed in push payload');
    return ['id'=>'33333333-3333-4333-8333-333333333333','status'=>'ACCEPTED'];
};
$result=evotor_order_notify_new($pushOrder);
$pushLogId=(int)($result['log_ids'][0]??0);
action_ok($result['queued']===1&&$result['sent']===0&&$pushLogId>0&&$transportCalls===0,'actionable Evotor push is queued without synchronous network I/O');
action_ok(evotor_order_push_dispatch_log($pushLogId)===true&&$transportCalls===1,'queued actionable Evotor push is dispatched by worker');
action_ok(evotor_order_push_dispatch_log($pushLogId)===false&&$transportCalls===1,'sent actionable push is not dispatched twice');
unset($GLOBALS['kapouch_evotor_push_transport']);

echo "EVOTOR ORDER ACTIONS PASSED\n";
