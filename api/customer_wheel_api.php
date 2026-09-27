<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_wheel.php';

customer_api_headers();
header('Cache-Control: no-store');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(!in_array($method,['GET','POST'],true))customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);

try{
    $customer=customer_auth_require();$customerId=(int)$customer['id'];
    if($method==='GET')customer_api_reply(200,['ok'=>true,'wheel'=>customer_wheel_public_status($customerId)]);

    $limit=kapouch_rate_limit_hit('customer_wheel_spin','customer:'.$customerId,60,3600);
    if(!$limit['allowed']){header('Retry-After: '.(int)$limit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много попыток вращения. Попробуйте позже.']);}
    $data=customer_api_json();$action=trim((string)($data['action']??'spin'));
    if($action!=='spin')customer_api_reply(422,['ok'=>false,'error'=>'Неизвестное действие.']);
    $spin=customer_wheel_spin($customerId);
    kapouch_runtime_log('wheel','spin',['customer_id'=>$customerId,'spin_id'=>(int)$spin['spin_id'],'prize_id'=>(int)$spin['prize']['id'],'prize_type'=>(string)$spin['prize']['type'],'source'=>(string)($spin['source']??'order')]);
    customer_api_reply(200,['ok'=>true,'spin'=>$spin,'wheel'=>customer_wheel_public_status($customerId)]);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);}
catch(RuntimeException $e){
    if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Сначала войдите в профиль Kapouch.']);
    customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('[Kapouch customer wheel] '.$e->getMessage());
    kapouch_runtime_log('wheel','error',['class'=>get_class($e),'message'=>mb_substr($e->getMessage(),0,700)]);
    customer_api_reply(500,['ok'=>false,'error'=>'Колесо временно недоступно. Попробуйте позже.']);
}
