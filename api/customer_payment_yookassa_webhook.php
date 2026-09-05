<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_payments.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
    http_response_code(405);echo json_encode(['ok'=>false]);exit;
}

try{
    $raw=file_get_contents('php://input');
    $data=json_decode(is_string($raw)?$raw:'',true);
    if(!is_array($data))throw new RuntimeException('invalid json');
    $event=(string)($data['event']??'');
    $objectId=trim((string)($data['object']['id']??''));
    if($objectId==='')throw new RuntimeException('missing object id');

    if(str_starts_with($event,'refund.')){
        $result=customer_payment_yookassa_sync_refund($objectId);
        if(!$result)throw new RuntimeException('refund not found');
    }else{
        $result=customer_payment_yookassa_sync_by_provider_id($objectId);
        if(!$result)throw new RuntimeException('payment not found');
    }

    echo json_encode(['ok'=>true,'event'=>$event],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    error_log('[Kapouch YooKassa webhook] '.$e->getMessage());
    http_response_code(400);echo json_encode(['ok'=>false]);
}
