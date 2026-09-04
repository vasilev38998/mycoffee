<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_orders.php';
require_once dirname(__DIR__).'/inc/customer_pwa.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $catalog=customer_pwa_catalog();
    customer_api_reply(200,['ok'=>true,'shop'=>array_merge($catalog['settings'],['currency'=>app_currency(),'loyalty_percent'=>customer_loyalty_rate()]),'categories'=>$catalog['categories'],'products'=>$catalog['products']]);
}catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось загрузить каталог.']);}
