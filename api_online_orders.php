<?php
declare(strict_types=1);
require __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/online_orders.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function online_orders_api_reply(int $status,array $data): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    if(!online_orders_api_authorized())online_orders_api_reply(401,['ok'=>false,'error'=>'Unauthorized']);
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if($method==='POST'){
        $result=online_orders_upsert_from_api(online_orders_api_payload());
        online_orders_api_reply($result['created']?201:200,['ok'=>true,'order'=>$result]);
    }
    if($method==='GET'){
        $externalId=trim((string)($_GET['external_id']??''));
        if($externalId===''||mb_strlen($externalId)>190)online_orders_api_reply(400,['ok'=>false,'error'=>'external_id is required']);
        $order=online_orders_get_by_external_id($externalId);
        if(!$order)online_orders_api_reply(404,['ok'=>false,'error'=>'Order not found']);
        online_orders_api_reply(200,['ok'=>true,'order'=>$order]);
    }
    online_orders_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
}catch(JsonException $e){
    online_orders_api_reply(400,['ok'=>false,'error'=>'Invalid JSON']);
}catch(RuntimeException $e){
    online_orders_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('[Kapouch online orders API] '.$e->getMessage());
    online_orders_api_reply(500,['ok'=>false,'error'=>'Internal server error']);
}
