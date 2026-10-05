<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_auth_self_call.php';
require_once dirname(__DIR__).'/inc/customer_phone.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $ipLimit=kapouch_rate_limit_hit('customer_auth_request_ip',kapouch_client_ip(),30,3600);
    if(!$ipLimit['allowed']){header('Retry-After: '.(int)$ipLimit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много запросов подтверждения. Попробуйте позже.']);}
    $data=customer_api_json();$rawPhone=(string)($data['phone']??'');$phone=customer_phone_canonical_ru($rawPhone);
    $method=strtolower(trim((string)($data['method']??'self_call')));
    if(!in_array($method,['self_call','call'],true))customer_api_reply(422,['ok'=>false,'error'=>'Неизвестный способ подтверждения номера.']);
    $phoneLimit=kapouch_rate_limit_hit('customer_auth_request_phone',$phone,10,3600);
    if(!$phoneLimit['allowed']){header('Retry-After: '.(int)$phoneLimit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много запросов подтверждения для этого номера. Попробуйте позже.']);}
    $lock=kapouch_local_lock('customer_auth_code:'.$phone);
    if(!$lock){header('Retry-After: 2');customer_api_reply(429,['ok'=>false,'error'=>'Подтверждение для этого номера уже запрашивается. Повторите через несколько секунд.']);}
    try{$auth=$method==='self_call'?customer_auth_request_self_call($phone):customer_auth_request_code($phone,'call');}finally{kapouch_local_unlock($lock);}
    customer_api_reply(200,['ok'=>true,'auth'=>$auth]);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);}
catch(RuntimeException $e){
    $message=str_replace('или используйте SMS','или используйте входящий звонок',$e->getMessage());
    customer_api_reply(422,['ok'=>false,'error'=>$message]);
}catch(Throwable $e){
    if(function_exists('db_capacity_error')&&db_capacity_error($e)){header('Retry-After: 20');customer_api_reply(503,['ok'=>false,'error'=>'Сервис временно перегружен. Повторите через несколько секунд.']);}
    error_log('[Kapouch customer auth request] '.$e->getMessage());
    customer_api_reply(500,['ok'=>false,'error'=>'Не удалось подтвердить номер. Попробуйте позже.']);
}
