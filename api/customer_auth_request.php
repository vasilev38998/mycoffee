<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_phone.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $ipLimit=kapouch_rate_limit_hit('customer_auth_request_ip',kapouch_client_ip(),30,3600);
    if(!$ipLimit['allowed']){header('Retry-After: '.(int)$ipLimit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много запросов кода. Попробуйте позже.']);}
    $data=customer_api_json();$rawPhone=(string)($data['phone']??'');$phone=customer_phone_canonical_ru($rawPhone);
    $phoneLimit=kapouch_rate_limit_hit('customer_auth_request_phone',$phone,10,3600);
    if(!$phoneLimit['allowed']){header('Retry-After: '.(int)$phoneLimit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много запросов кода для этого номера. Попробуйте позже.']);}
    // A MySQL GET_LOCK used to stay attached to the request while SMS.ru was
    // contacted. Under concurrent sign-ups that consumed scarce DB connections.
    // A host-local file lock provides the same per-phone duplicate protection
    // without reserving a MySQL connection during the external HTTP call.
    $lock=kapouch_local_lock('customer_sms_code:'.$phone);
    if(!$lock){header('Retry-After: 2');customer_api_reply(429,['ok'=>false,'error'=>'Код для этого номера уже отправляется. Повторите через несколько секунд.']);}
    try{$auth=customer_auth_request_code($phone);}finally{kapouch_local_unlock($lock);}
    customer_api_reply(200,['ok'=>true,'auth'=>$auth]);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);}
catch(RuntimeException $e){customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){
    if(function_exists('db_capacity_error')&&db_capacity_error($e)){header('Retry-After: 20');customer_api_reply(503,['ok'=>false,'error'=>'Сервис временно перегружен. Повторите через несколько секунд.']);}
    customer_api_reply(500,['ok'=>false,'error'=>'Не удалось отправить код. Попробуйте позже.']);
}
