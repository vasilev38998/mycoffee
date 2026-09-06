<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_legal.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    customer_api_reply(200,['ok'=>true,'legal'=>customer_legal_public_data()]);
}catch(Throwable $e){
    error_log('[Kapouch customer legal] '.$e->getMessage());
    customer_api_reply(500,['ok'=>false,'error'=>'Не удалось загрузить правовую информацию.']);
}
