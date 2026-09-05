<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/online_orders.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $customer=customer_auth_require();$number=trim((string)($_GET['order_number']??''));if($number==='')customer_api_reply(422,['ok'=>false,'error'=>'Не указан номер заказа.']);
    $stmt=db()->prepare('SELECT o.* FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.customer_id=? AND o.order_number=? LIMIT 1');$stmt->execute([(int)$customer['id'],$number]);$order=$stmt->fetch();if(!$order)customer_api_reply(404,['ok'=>false,'error'=>'Заказ не найден.']);
    $stmt=db()->prepare('SELECT product_name,variant_name,quantity,unit_price,line_total,item_comment FROM online_order_items WHERE order_id=? ORDER BY sort_order,id');$stmt->execute([(int)$order['id']]);
    $order['items']=$stmt->fetchAll();$order['status_label']=online_orders_status_label((string)$order['status']);$order['payment_label']=online_orders_payment_label($order['payment_status']??null);$order['fulfillment_display']=online_orders_fulfillment_label($order);
    customer_api_reply(200,['ok'=>true,'order'=>$order]);
}catch(RuntimeException $e){if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Требуется вход.']);customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось загрузить заказ.']);}
