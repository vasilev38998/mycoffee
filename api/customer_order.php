<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_legal.php';
require_once dirname(__DIR__).'/inc/customer_operations.php';
require_once dirname(__DIR__).'/inc/customer_phone.php';
require_once dirname(__DIR__).'/inc/evotor_order_notifications.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $ipLimit=kapouch_rate_limit_hit('customer_order_ip',kapouch_client_ip(),25,600);
    if(!$ipLimit['allowed']){header('Retry-After: '.(int)$ipLimit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много попыток оформления. Подождите немного и повторите.']);}
    $data=customer_api_json();
    if(array_key_exists('phone',$data))$data['phone']=customer_phone_canonical_ru((string)$data['phone']);
    $clientOrderId=trim((string)($data['client_order_id']??''));
    $externalId=$clientOrderId!==''?'customer-web-'.$clientOrderId:'';
    $existingId=0;
    if($externalId!==''){$existing=db()->prepare('SELECT id FROM online_orders WHERE external_id=? LIMIT 1');$existing->execute([$externalId]);$existingId=(int)($existing->fetchColumn()?:0);}
    $acceptance=$existingId>0?null:customer_legal_checkout_acceptance($data);
    $phoneKey='';try{$phoneKey=customer_order_normalize_phone((string)($data['phone']??''));}catch(Throwable $e){}
    if($phoneKey!==''){$phoneLimit=kapouch_rate_limit_hit('customer_order_phone',$phoneKey,10,600);if(!$phoneLimit['allowed']){header('Retry-After: '.(int)$phoneLimit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много заказов для этого номера. Подождите немного и повторите.']);}}
    $comment=(string)($data['comment']??'');$delay=array_key_exists('pickup_delay_minutes',$data)?(int)$data['pickup_delay_minutes']:0;
    if(!array_key_exists('pickup_delay_minutes',$data)&&preg_match('/\[\[pickup:(\d{1,3})\]\]/',$comment,$m))$delay=(int)$m[1];
    if(preg_match('/\[\[pickup:\d{1,3}\]\]/',$comment))$data['comment']=trim(preg_replace('/\s*\[\[pickup:\d{1,3}\]\]\s*/',' ',$comment)??$comment);
    if($delay<0||$delay>720)throw new RuntimeException('Выберите доступное время получения.');
    $customer=customer_auth_current();

    if($existingId>0){
        $order=customer_order_create($data,$customer);
    }else{
        $requested=trim((string)($data['pickup_at']??''));
        if($requested==='')$requested=customer_operations_legacy_slot($delay);
        if($requested==='')throw new RuntimeException('Сейчас нет доступного времени для получения заказа.');
        $lockPurpose='customer_pickup_slot:'.hash('sha256',$requested);
        if(!kapouch_advisory_lock($lockPurpose,3))throw new RuntimeException('Этот временной интервал сейчас выбирает другой покупатель. Попробуйте ещё раз.');
        try{
            if($externalId!==''){$existing=db()->prepare('SELECT id FROM online_orders WHERE external_id=? LIMIT 1');$existing->execute([$externalId]);$existingId=(int)($existing->fetchColumn()?:0);}
            if($existingId<=0)customer_operations_validate_slot($requested);
            $order=customer_order_create($data,$customer);
            customer_legal_record_acceptance((int)($order['order_id']??0),$acceptance);
            if(!empty($order['order_id'])){
                $stmt=db()->prepare("UPDATE online_orders SET promised_at=? WHERE id=? AND promised_at IS NULL AND status IN ('new','awaiting_payment')");$stmt->execute([$requested,(int)$order['order_id']]);
            }
        }finally{kapouch_advisory_unlock($lockPurpose);}
    }

    if(!empty($order['order_id'])){
        $read=db()->prepare('SELECT promised_at FROM online_orders WHERE id=?');$read->execute([(int)$order['order_id']]);$saved=trim((string)($read->fetchColumn()?:''));
        if($saved!==''){$order['promised_at']=$saved;$order['promised_display']=date('H:i',strtotime($saved));}
        try{evotor_order_notify_new((int)$order['order_id']);}catch(Throwable $pushError){error_log('[Kapouch Evotor push enqueue] '.$pushError->getMessage());}
    }
    customer_api_reply(201,['ok'=>true,'order'=>$order]);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);}
catch(RuntimeException $e){customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){
    error_log('[Kapouch customer order] '.$e->getMessage());
    customer_api_reply(500,['ok'=>false,'error'=>'Не удалось оформить заказ. Попробуйте ещё раз.']);
}
