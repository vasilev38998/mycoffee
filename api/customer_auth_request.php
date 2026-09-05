<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $ipLimit=kapouch_rate_limit_hit('customer_auth_request_ip',kapouch_client_ip(),30,3600);
    if(!$ipLimit['allowed']){header('Retry-After: '.(int)$ipLimit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много запросов кода. Попробуйте позже.']);}
    $data=customer_api_json();$rawPhone=(string)($data['phone']??'');$phoneKey=preg_replace('/\D+/','',$rawPhone)??'';
    if($phoneKey!==''){$phoneLimit=kapouch_rate_limit_hit('customer_auth_request_phone',$phoneKey,10,3600);if(!$phoneLimit['allowed']){header('Retry-After: '.(int)$phoneLimit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много запросов кода для этого номера. Попробуйте позже.']);}}
    customer_api_reply(200,['ok'=>true,'auth'=>customer_auth_request_code($rawPhone)]);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);}
catch(RuntimeException $e){customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось отправить код. Попробуйте позже.']);}
