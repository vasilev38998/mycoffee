<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_payments.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
    http_response_code(405);echo json_encode(['ok'=>false]);exit;
}

try{
    $raw=file_get_contents('php://input');
    if(!is_string($raw)||strlen($raw)>262144)throw new RuntimeException('invalid body');
    $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('invalid json');
    $event=trim((string)($data['event']??''));
    $objectId=trim((string)($data['object']['id']??''));
    if(!preg_match('/^[A-Za-z0-9_-]{10,190}$/',$objectId))throw new RuntimeException('invalid object id');

    if(str_starts_with($event,'refund.')){
        $known=db()->prepare("SELECT order_id FROM customer_payments WHERE provider='yookassa_sbp' AND provider_refund_id=? LIMIT 1");
        $known->execute([$objectId]);
        if(!$known->fetchColumn())throw new RuntimeException('refund not found');
        $result=customer_payment_yookassa_sync_refund($objectId);
        if(!$result)throw new RuntimeException('refund not found');
        if((string)($result['status']??'')==='succeeded')customer_loyalty_reverse_order((int)($result['order_id']??0));
    }elseif(str_starts_with($event,'payment.')){
        $result=customer_payment_yookassa_sync_by_provider_id($objectId);
        if(!$result)throw new RuntimeException('payment not found');
    }else{
        echo json_encode(['ok'=>true,'ignored'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }

    echo json_encode(['ok'=>true,'event'=>$event],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(JsonException $e){
    http_response_code(400);echo json_encode(['ok'=>false]);
}catch(Throwable $e){
    error_log('[Kapouch YooKassa webhook] '.$e->getMessage());
    http_response_code(400);echo json_encode(['ok'=>false]);
}
