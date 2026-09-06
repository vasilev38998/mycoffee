<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';
require_once dirname(__DIR__).'/inc/customer_loyalty_card.php';

customer_api_headers();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if($method!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);

try{
    $customer=customer_auth_require();
    $customerId=(int)$customer['id'];
    customer_loyalty_refresh_customer($customerId);
    $card=customer_loyalty_card_payload($customerId);
    $card['customer']['loyalty_balance']=customer_loyalty_balance($customerId);
    $card['loyalty_rate']=customer_loyalty_rate();
    customer_api_reply(200,['ok'=>true,'card'=>$card]);
}catch(RuntimeException $e){
    if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Требуется вход.']);
    customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось загрузить карту лояльности.']);}
