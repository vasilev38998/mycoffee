<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_push.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    if(trim((string)app_setting('customer_push_vapid_subject',''))===''){$host=preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??''));if($host)set_app_setting('customer_push_vapid_subject','mailto:push@'.$host);}
    $keys=customer_push_vapid_keys();customer_api_reply(200,['ok'=>true,'push'=>['public_key'=>$keys['public']]]);
}catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Push-уведомления пока недоступны.']);}
