<?php
declare(strict_types=1);

require_once __DIR__.'/evotor.php';

function customer_payment_methods(): array
{
    $cash=app_setting('customer_payment_cash_enabled','1')==='1';
    $sbp=app_setting('customer_payment_sbp_enabled','0')==='1';
    $connection=customer_payment_connection('yookassa_sbp');
    $sbpReady=$sbp&&$connection&&!empty($connection['enabled'])&&!empty($connection['merchant_login'])&&!empty($connection['secret_ciphertext']);
    return [
        'cash'=>['id'=>'cash','label'=>'Наличными при самовывозе','enabled'=>$cash,'online'=>false],
        'sbp'=>['id'=>'sbp','label'=>'Оплата по СБП','enabled'=>$sbpReady,'online'=>true],
    ];
}

function customer_payment_enabled_methods(): array
{
    return array_values(array_filter(customer_payment_methods(),static fn(array $m): bool=>(bool)$m['enabled']));
}

function customer_payment_connection(string $provider): ?array
{
    try{$stmt=db()->prepare('SELECT * FROM customer_payment_connections WHERE provider=? LIMIT 1');$stmt->execute([$provider]);return $stmt->fetch()?:null;}catch(Throwable $e){return null;}
}

function customer_payment_save_yookassa(array $data): void
{
    $enabled=!empty($data['enabled'])?1:0;
    $test=!empty($data['test_mode'])?1:0;
    $shopId=trim((string)($data['shop_id']??''));
    $secret=trim((string)($data['secret_key']??''));
    if($shopId!==''&&!preg_match('/^[A-Za-z0-9_-]{3,190}$/',$shopId))throw new RuntimeException('Проверьте shopId ЮKassa.');
    $current=customer_payment_connection('yookassa_sbp');
    $cipher=$current['secret_ciphertext']??null;$iv=$current['secret_iv']??null;$tag=$current['secret_tag']??null;
    if($secret!=='')[$cipher,$iv,$tag]=evotor_encrypt_token($secret);
    if($enabled&&($shopId===''||!$cipher))throw new RuntimeException('Для включения СБП укажите shopId и секретный ключ ЮKassa.');
    $stmt=db()->prepare("INSERT INTO customer_payment_connections(provider,enabled,test_mode,merchant_login,secret_ciphertext,secret_iv,secret_tag,api_base_url) VALUES('yookassa_sbp',?,?,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),test_mode=VALUES(test_mode),merchant_login=VALUES(merchant_login),secret_ciphertext=VALUES(secret_ciphertext),secret_iv=VALUES(secret_iv),secret_tag=VALUES(secret_tag),api_base_url=NULL");
    $stmt->execute([$enabled,$test,$shopId!==''?$shopId:null,$cipher,$iv,$tag]);
}

function customer_payment_yookassa_secret(array $connection): string
{
    return evotor_decrypt_token(['token_ciphertext'=>$connection['secret_ciphertext'],'token_iv'=>$connection['secret_iv'],'token_tag'=>$connection['secret_tag']]);
}

function customer_payment_public_url(string $path): string
{
    $host=preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??''));if($host==='')throw new RuntimeException('Не удалось определить адрес Kapouch.');
    $forwarded=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??'');$scheme=$forwarded!==''?$forwarded:((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http');if($scheme!=='https')$scheme='https';
    return $scheme.'://'.$host.'/'.ltrim($path,'/');
}

function customer_payment_yookassa_request(array $connection,string $httpMethod,string $path,?array $payload=null,?string $idempotenceKey=null): array
{
    $url='https://api.yookassa.ru/v3/'.ltrim($path,'/');
    $headers=['Accept: application/json','Content-Type: application/json'];
    if($idempotenceKey!==null)$headers[]='Idempotence-Key: '.$idempotenceKey;
    $ch=curl_init($url);
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>(string)$connection['merchant_login'].':'.customer_payment_yookassa_secret($connection),CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'Kapouch/1.0 YooKassa'];
    if(strtoupper($httpMethod)!=='GET'){$opts[CURLOPT_CUSTOMREQUEST]=strtoupper($httpMethod);if($payload!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    curl_setopt_array($ch,$opts);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$error!=='')throw new RuntimeException('Нет связи с ЮKassa: '.$error);
    $json=json_decode((string)$body,true);
    if($status<200||$status>=300||!is_array($json)){
        $message=is_array($json)?trim((string)($json['description']??$json['code']??'')):'';
        throw new RuntimeException('ЮKassa вернула HTTP '.$status.($message!==''?': '.$message:''));
    }
    return $json;
}

function customer_payment_receipt_vat_code(): int
{
    $code=(int)app_setting('customer_yookassa_vat_code','1');return ($code>=1&&$code<=12)?$code:1;
}
function customer_payment_receipt_subject(): string
{
    $value=(string)app_setting('customer_yookassa_payment_subject','commodity');
    return in_array($value,['commodity','service','excise','payment','another'],true)?$value:'commodity';
}
function customer_payment_receipt_mode(): string
{
    $value=(string)app_setting('customer_yookassa_payment_mode','full_payment');
    return in_array($value,['full_prepayment','full_payment'],true)?$value:'full_payment';
}

function customer_payment_yookassa_receipt(int $orderId,string $email,float $total): array
{
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Для оплаты по СБП укажите корректную электронную почту в профиле Kapouch.');
    $stmt=db()->prepare('SELECT product_name,variant_name,quantity,unit_price FROM online_order_items WHERE order_id=? ORDER BY sort_order,id');
    $stmt->execute([$orderId]);$rows=$stmt->fetchAll();if(!$rows)throw new RuntimeException('Заказ пуст — не удалось сформировать чек.');
    $items=[];$sum=0.0;$last=count($rows)-1;
    foreach($rows as $index=>$row){
        $qty=(float)$row['quantity'];if($qty<=0)throw new RuntimeException('Некорректное количество позиции в чеке.');
        $unit=round((float)$row['unit_price'],2);if($unit<0)throw new RuntimeException('Некорректная цена позиции в чеке.');
        if($index===$last){$expected=round($total-$sum,2);$candidate=round($expected/$qty,2);if(abs(($candidate*$qty)-$expected)<0.011)$unit=$candidate;}
        $sum=round($sum+$unit*$qty,2);
        $name=trim((string)$row['product_name']);$variant=trim((string)($row['variant_name']??''));if($variant!=='')$name.=' · '.$variant;
        $items[]=['description'=>mb_substr($name!==''?$name:'Позиция заказа',0,128),'quantity'=>number_format($qty,3,'.',''),'amount'=>['value'=>number_format($unit,2,'.',''),'currency'=>'RUB'],'vat_code'=>customer_payment_receipt_vat_code(),'payment_mode'=>customer_payment_receipt_mode(),'payment_subject'=>customer_payment_receipt_subject(),'measure'=>'piece'];
    }
    if(abs($sum-round($total,2))>0.011)throw new RuntimeException('Сумма позиций чека не совпадает с суммой заказа.');
    return ['customer'=>['email'=>$email],'items'=>$items,'internet'=>true];
}

function customer_payment_create_sbp(int $orderId,string $orderNumber,float $amount,string $phone,string $email): array
{
    $existing=customer_payment_status_for_order($orderId);
    if($existing&&$existing['provider']==='yookassa_sbp'&&in_array((string)$existing['status'],['pending','paid'],true)&&!empty($existing['provider_order_id'])){
        return ['payment_url'=>(string)($existing['payment_url']??''),'sbp_payload'=>'','provider_order_id'=>(string)$existing['provider_order_id'],'status'=>(string)$existing['status']];
    }
    $connection=customer_payment_connection('yookassa_sbp');if(!$connection||empty($connection['enabled']))throw new RuntimeException('Оплата по СБП сейчас недоступна.');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Для оплаты по СБП укажите электронную почту в профиле Kapouch.');
    $amount=round($amount,2);if($amount<1)throw new RuntimeException('Минимальная сумма оплаты по СБП — 1 ₽.');
    $returnUrl=customer_payment_public_url('customer/payment-return.html');
    $payload=[
        'amount'=>['value'=>number_format($amount,2,'.',''),'currency'=>'RUB'],
        'capture'=>true,
        'payment_method_data'=>['type'=>'sbp'],
        'confirmation'=>['type'=>'redirect','return_url'=>$returnUrl],
        'description'=>mb_substr('Kapouch заказ '.$orderNumber,0,128),
        'receipt'=>customer_payment_yookassa_receipt($orderId,$email,$amount),
        'metadata'=>['kapouch_order_id'=>(string)$orderId,'kapouch_order_number'=>$orderNumber],
    ];
    db()->prepare("UPDATE online_orders SET status='awaiting_payment',payment_status='pending',payment_method='sbp',payment_provider='yookassa_sbp' WHERE id=? AND status='new'")->execute([$orderId]);
    $key=substr(hash('sha256','kapouch|yookassa|'.$orderId.'|'.$orderNumber),0,64);
    $response=customer_payment_yookassa_request($connection,'POST','payments',$payload,$key);
    $paymentId=trim((string)($response['id']??''));$paymentUrl=trim((string)($response['confirmation']['confirmation_url']??''));
    if($paymentId===''||$paymentUrl==='')throw new RuntimeException('ЮKassa создала платёж без ссылки для оплаты.');
    $stmt=db()->prepare("INSERT INTO customer_payments(order_id,provider,method,status,amount,provider_order_id,provider_order_number,payment_url,sbp_payload,provider_response) VALUES(?,'yookassa_sbp','sbp','pending',?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE provider_order_id=VALUES(provider_order_id),provider_order_number=VALUES(provider_order_number),payment_url=VALUES(payment_url),sbp_payload=NULL,provider_response=VALUES(provider_response),status='pending'");
    $stmt->execute([$orderId,$amount,$paymentId,$orderNumber,$paymentUrl,json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    return ['payment_url'=>$paymentUrl,'sbp_payload'=>'','provider_order_id'=>$paymentId,'status'=>(string)($response['status']??'pending')];
}

function customer_payment_mark_cash(int $orderId): void
{
    db()->prepare("UPDATE online_orders SET payment_status='unpaid',payment_method='cash',payment_provider=NULL WHERE id=?")->execute([$orderId]);
}

function customer_payment_yookassa_sync_by_provider_id(string $providerOrderId): ?array
{
    if(!preg_match('/^[A-Za-z0-9_-]{10,190}$/',$providerOrderId))return null;
    $stmt=db()->prepare("SELECT p.*,o.total_amount,o.status order_status FROM customer_payments p JOIN online_orders o ON o.id=p.order_id WHERE p.provider='yookassa_sbp' AND p.provider_order_id=? LIMIT 1");$stmt->execute([$providerOrderId]);$payment=$stmt->fetch();if(!$payment)return null;
    $connection=customer_payment_connection('yookassa_sbp');if(!$connection)return null;
    $status=customer_payment_yookassa_request($connection,'GET','payments/'.rawurlencode($providerOrderId));
    $state=(string)($status['status']??'');$paid=!empty($status['paid'])&&$state==='succeeded';$actual=round((float)($status['amount']['value']??0),2);$expected=round((float)$payment['amount'],2);$paid=$paid&&abs($actual-$expected)<0.001;
    if($paid){
        db()->prepare("UPDATE customer_payments SET status=CASE WHEN refund_status='succeeded' THEN 'refunded' ELSE 'paid' END,paid_at=COALESCE(paid_at,NOW()),provider_response=? WHERE id=?")->execute([json_encode($status,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id']]);
        db()->prepare("UPDATE online_orders SET status=CASE WHEN status='awaiting_payment' THEN 'new' ELSE status END,payment_status=CASE WHEN payment_status='refunded' THEN 'refunded' ELSE 'paid' END,payment_provider='yookassa_sbp' WHERE id=?")->execute([(int)$payment['order_id']]);
    }elseif($state==='canceled'){
        db()->prepare("UPDATE customer_payments SET status='failed',failed_at=COALESCE(failed_at,NOW()),provider_response=? WHERE id=?")->execute([json_encode($status,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id']]);
        db()->prepare("UPDATE online_orders SET status=CASE WHEN status='awaiting_payment' THEN 'cancelled' ELSE status END,payment_status='failed' WHERE id=?")->execute([(int)$payment['order_id']]);
    }else{
        db()->prepare('UPDATE customer_payments SET provider_response=? WHERE id=?')->execute([json_encode($status,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id']]);
    }
    return ['paid'=>$paid,'order_id'=>(int)$payment['order_id'],'payment_state'=>$state];
}

function customer_payment_status_for_order(int $orderId): ?array
{
    $stmt=db()->prepare('SELECT provider,method,status,amount,payment_url,sbp_payload,provider_order_id,provider_refund_id,refund_status,refunded_amount FROM customer_payments WHERE order_id=? ORDER BY id DESC LIMIT 1');$stmt->execute([$orderId]);return $stmt->fetch()?:null;
}

function customer_payment_refundable_orders(int $limit=100): array
{
    $limit=max(1,min(300,$limit));
    try{
        return db()->query("SELECT p.id payment_id,p.order_id,p.status payment_record_status,p.amount,p.provider_order_id,p.provider_refund_id,p.refund_status,p.refunded_amount,p.paid_at,p.refunded_at,o.order_number,o.status order_status,o.payment_status,o.customer_name,o.customer_phone,o.total_amount FROM customer_payments p JOIN online_orders o ON o.id=p.order_id WHERE p.provider='yookassa_sbp' AND p.status IN ('paid','refund_pending','refunded') ORDER BY COALESCE(p.refunded_at,p.paid_at,p.created_at) DESC LIMIT {$limit}")->fetchAll();
    }catch(Throwable $e){return [];}
}

function customer_payment_yookassa_refund_full(int $orderId): array
{
    $pdo=db();
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT p.*,o.order_number,o.status order_status,o.payment_status order_payment_status,o.total_amount FROM customer_payments p JOIN online_orders o ON o.id=p.order_id WHERE p.order_id=? AND p.provider='yookassa_sbp' FOR UPDATE");
        $stmt->execute([$orderId]);$payment=$stmt->fetch();
        if(!$payment)throw new RuntimeException('Платёж ЮKassa для заказа не найден.');
        if((string)$payment['status']==='refunded'||(string)($payment['refund_status']??'')==='succeeded'){$pdo->commit();return ['status'=>'succeeded','refund_id'=>(string)($payment['provider_refund_id']??'')];}
        if((string)$payment['status']==='refund_pending')throw new RuntimeException('Возврат уже отправлен в ЮKassa и ожидает завершения.');
        if((string)$payment['status']!=='paid')throw new RuntimeException('Вернуть можно только успешно оплаченный заказ.');
        $paymentId=trim((string)$payment['provider_order_id']);if($paymentId==='')throw new RuntimeException('У платежа отсутствует идентификатор ЮKassa.');
        $amount=round((float)$payment['amount'],2);if($amount<=0)throw new RuntimeException('Некорректная сумма возврата.');
        $retrySeed=(string)($payment['refund_status']??'')==='canceled'?(string)($payment['provider_refund_id']??''):'';
        $pdo->commit();

        $connection=customer_payment_connection('yookassa_sbp');
        if(!$connection||empty($connection['merchant_login'])||empty($connection['secret_ciphertext']))throw new RuntimeException('Не настроены реквизиты ЮKassa для возврата.');
        $key=substr(hash('sha256','kapouch|refund|'.$orderId.'|'.$paymentId.'|'.number_format($amount,2,'.','').'|'.$retrySeed),0,64);
        $response=customer_payment_yookassa_request($connection,'POST','refunds',[
            'payment_id'=>$paymentId,
            'amount'=>['value'=>number_format($amount,2,'.',''),'currency'=>'RUB'],
            'description'=>mb_substr('Возврат заказа '.(string)$payment['order_number'],0,250),
        ],$key);
        $refundId=trim((string)($response['id']??''));$state=trim((string)($response['status']??''));
        if($refundId==='')throw new RuntimeException('ЮKassa не вернула идентификатор возврата.');
        if(!in_array($state,['pending','succeeded','canceled'],true))throw new RuntimeException('ЮKassa вернула неизвестный статус возврата: '.$state);
        customer_payment_apply_refund_state($refundId,$response);
        return ['status'=>$state,'refund_id'=>$refundId];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_payment_apply_refund_state(string $refundId,array $response): ?array
{
    $refundId=trim($refundId);if($refundId==='')return null;
    $paymentId=trim((string)($response['payment_id']??''));
    $stmt=db()->prepare("SELECT p.*,o.status order_status FROM customer_payments p JOIN online_orders o ON o.id=p.order_id WHERE p.provider='yookassa_sbp' AND (p.provider_refund_id=? OR (?<>'' AND p.provider_order_id=?)) LIMIT 1");
    $stmt->execute([$refundId,$paymentId,$paymentId]);$payment=$stmt->fetch();if(!$payment)return null;
    $state=(string)($response['status']??'');$amount=round((float)($response['amount']['value']??0),2);$expected=round((float)$payment['amount'],2);
    if(abs($amount-$expected)>0.001)throw new RuntimeException('Сумма возврата ЮKassa не совпадает с суммой платежа.');
    $json=json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($state==='succeeded'){
        db()->prepare("UPDATE customer_payments SET status='refunded',provider_refund_id=?,refund_status='succeeded',refunded_amount=?,refund_response=?,refunded_at=COALESCE(refunded_at,NOW()) WHERE id=?")->execute([$refundId,$amount,$json,(int)$payment['id']]);
        db()->prepare("UPDATE online_orders SET payment_status='refunded',status=CASE WHEN status IN ('new','preparing','ready') THEN 'cancelled' ELSE status END,cancelled_at=CASE WHEN status IN ('new','preparing','ready') THEN COALESCE(cancelled_at,NOW()) ELSE cancelled_at END WHERE id=?")->execute([(int)$payment['order_id']]);
        audit_write('customer_payment_refund','ЮKassa: полный возврат '.number_format($amount,2,'.','').' ₽ завершён','online_order',(string)$payment['order_id']);
    }elseif($state==='pending'){
        db()->prepare("UPDATE customer_payments SET status='refund_pending',provider_refund_id=?,refund_status='pending',refunded_amount=?,refund_response=? WHERE id=?")->execute([$refundId,$amount,$json,(int)$payment['id']]);
    }elseif($state==='canceled'){
        db()->prepare("UPDATE customer_payments SET status='paid',provider_refund_id=?,refund_status='canceled',refunded_amount=NULL,refund_response=? WHERE id=?")->execute([$refundId,$json,(int)$payment['id']]);
    }
    return ['order_id'=>(int)$payment['order_id'],'status'=>$state];
}

function customer_payment_yookassa_sync_refund(string $refundId): ?array
{
    if(!preg_match('/^[A-Za-z0-9_-]{10,190}$/',$refundId))return null;
    $connection=customer_payment_connection('yookassa_sbp');if(!$connection)return null;
    $response=customer_payment_yookassa_request($connection,'GET','refunds/'.rawurlencode($refundId));
    return customer_payment_apply_refund_state($refundId,$response);
}
