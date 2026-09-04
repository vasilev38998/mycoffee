<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_orders.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
$token=trim((string)($_GET['token']??''));
if(preg_match('/^[a-f0-9]{64}$/',$token)){
    $stmt=db()->prepare("SELECT a.order_id FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.tracking_token=? AND o.status='completed' AND a.loyalty_earned_at IS NULL LIMIT 1");
    $stmt->execute([$token]);$orderId=(int)($stmt->fetchColumn()?:0);
    if($orderId>0)customer_loyalty_on_order_completed($orderId);
}
$order=customer_order_public_status($token);
if(!$order)customer_api_reply(404,['ok'=>false,'error'=>'Заказ не найден.']);
customer_api_reply(200,['ok'=>true,'order'=>$order]);
