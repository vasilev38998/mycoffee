<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_orders.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';
require_once dirname(__DIR__).'/inc/customer_drink_loyalty.php';
require_once dirname(__DIR__).'/inc/evotor_order_notifications.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
$token=trim((string)($_GET['token']??''));
$row=[];
if(preg_match('/^[a-f0-9]{64}$/',$token)){
    $stmt=db()->prepare('SELECT o.id,o.promised_at,o.status,o.payment_status,a.customer_id,a.loyalty_earned_at FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.tracking_token=? LIMIT 1');$stmt->execute([$token]);$row=$stmt->fetch()?:[];$orderId=(int)($row['id']??0);$status=(string)($row['status']??'');$paymentStatus=(string)($row['payment_status']??'');
    if($orderId>0&&$status==='completed'){
        if(empty($row['loyalty_earned_at']))customer_loyalty_on_order_completed($orderId);
        customer_drink_loyalty_credit_online_order($orderId,(int)($row['customer_id']??0));
    }
    if($orderId>0&&($status==='cancelled'||$paymentStatus==='refunded')){
        try{customer_drink_loyalty_restore_online_order_reward($orderId,$paymentStatus==='refunded'?'Оплата возвращена, подарок восстановлен':'Заказ отменён, подарок восстановлен');}catch(Throwable $restoreError){error_log('[Kapouch sixth drink restore] '.$restoreError->getMessage());}
    }
}
$order=customer_order_public_status($token);
if(!$order)customer_api_reply(404,['ok'=>false,'error'=>'Заказ не найден.']);
if(preg_match('/^[a-f0-9]{64}$/',$token)){
    if(!$row){$stmt=db()->prepare('SELECT o.id,o.promised_at,o.status FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.tracking_token=? LIMIT 1');$stmt->execute([$token]);$row=$stmt->fetch()?:[];}
    $promised=trim((string)($row['promised_at']??''));$order['promised_at']=$promised;$order['promised_display']=$promised!==''?date('H:i',strtotime($promised)):'';
    if((int)($row['id']??0)>0&&(string)($row['status']??'')==='new'){
        try{evotor_order_notify_new((int)$row['id']);}catch(Throwable $pushError){error_log('[Kapouch Evotor push paid poll] '.$pushError->getMessage());}
    }
}
customer_api_reply(200,['ok'=>true,'order'=>$order]);
