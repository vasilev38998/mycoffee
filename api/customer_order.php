<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_orders.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS')exit;
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $order=customer_order_create(customer_api_json());
    customer_api_reply(201,['ok'=>true,'order'=>$order]);
}catch(JsonException $e){
    customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);
}catch(Throwable $e){
    customer_api_reply(400,['ok'=>false,'error'=>$e->getMessage()]);
}
