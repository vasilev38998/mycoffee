<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_push.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $customer=customer_auth_require();$data=customer_api_json();$subscription=$data['subscription']??null;if(!is_array($subscription))throw new RuntimeException('Некорректная push-подписка.');
    $endpoint=trim((string)($subscription['endpoint']??''));$host=(string)(parse_url($endpoint,PHP_URL_HOST)??'');if($host==='')throw new RuntimeException('Некорректный push endpoint.');
    $ip=filter_var($host,FILTER_VALIDATE_IP)?$host:gethostbyname($host);if($ip===$host&&!filter_var($host,FILTER_VALIDATE_IP))throw new RuntimeException('Не удалось проверить push endpoint.');
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Недопустимый push endpoint.');
    customer_push_subscribe((int)$customer['id'],$subscription);customer_api_reply(200,['ok'=>true]);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);}
catch(RuntimeException $e){if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Сначала войдите в профиль.']);customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось сохранить push-подписку.']);}
