<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_orders.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS')exit;
customer_api_guard_origin();
try{
    customer_api_reply(200,['ok'=>true,'shop'=>['name'=>(string)app_setting('coffee_name','Kapouch'),'currency'=>app_currency()],'products'=>customer_order_catalog()]);
}catch(Throwable $e){
    customer_api_reply(500,['ok'=>false,'error'=>'Не удалось загрузить каталог.']);
}
