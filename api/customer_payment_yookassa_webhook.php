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
    $paymentId=trim((string)($data['object']['id']??''));
    if($paymentId==='')throw new RuntimeException('missing payment id');

    // Не доверяем статусу из webhook: повторно запрашиваем платеж у ЮKassa
    // с серверными credentials и сверяем сумму в customer_payment_yookassa_sync_by_provider_id().
    $result=customer_payment_yookassa_sync_by_provider_id($paymentId);
    if(!$result)throw new RuntimeException('payment not found');

    echo json_encode(['ok'=>true,'event'=>$event],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    error_log('[Kapouch YooKassa webhook] '.$e->getMessage());
    http_response_code(400);echo json_encode(['ok'=>false]);
}
