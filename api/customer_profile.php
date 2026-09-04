<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $customer=customer_auth_require();
    customer_loyalty_refresh_customer((int)$customer['id']);
    customer_api_reply(200,['ok'=>true,'profile'=>customer_auth_profile($customer)]);
}catch(RuntimeException $e){
    if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Требуется вход.']);
    customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось загрузить профиль.']);}
