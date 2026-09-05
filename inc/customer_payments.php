<?php
declare(strict_types=1);

require_once __DIR__.'/evotor.php';

function customer_payment_methods(): array
{
    $cash=app_setting('customer_payment_cash_enabled','1')==='1';
    $sbp=app_setting('customer_payment_sbp_enabled','0')==='1';
    $connection=customer_payment_connection('yookassa_sbp');
    $sbpReady=$sbp&&$connection&&$connection['enabled']&&!empty($connection['merchant_login'])&&!empty($connection['secret_ciphertext']);
    return [
        'cash'=>['id'=>'cash','label'=>'Наличными при самовывозе','enabled'=>$cash,'online'=>false],
        'sbp'=>['id'=>'sbp','label'=>'Онлайн по СБП','enabled'=>$sbpReady,'online'=>true],
    ];
}

function customer_payment_enabled_methods(): array{return array_values(array_filter(customer_payment_methods(),static fn(array $m): bool=>(bool)$m['enabled']));}

function customer_payment_connection(string $provider): ?array
{
    try{$stmt=db()->prepare('SELECT * FROM customer_payment_connections WHERE provider=? LIMIT 1');$stmt->execute([$provider]);return $stmt->fetch()?:null;}catch(Throwable $e){return null;}
}

function customer_payment_save_yookassa(array $data): void
{
    $enabled=!empty($data['enabled'])?1:0;$shopId=trim((string)($data['shop_id']??''));$secret=trim((string)($data['secret_key']??''));
    $current=customer_payment_connection('yookassa_sbp');$cipher=$current['secret_ciphertext']??null;$iv=$current['secret_iv']??null;$tag=$current['secret_tag']??null;
    if($secret!=='')[$cipher,$iv,$tag]=evotor_encrypt_token($secret);
    if($enabled&&($shopId===''||!$cipher))throw new RuntimeException('Для включения СБП укажите shopId и секретный ключ ЮKassa.');
    $stmt=db()->prepare("INSERT INTO customer_payment_connections(provider,enabled,test_mode,merchant_login,secret_ciphertext,secret_iv,secret_tag,api_base_url) VALUES('yookassa_sbp',?,0,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),merchant_login=VALUES(merchant_login),secret_ciphertext=VALUES(secret_ciphertext),secret_iv=VALUES(secret_iv),secret_tag=VALUES(secret_tag),test_mode=0,api_base_url=NULL");
    $stmt->execute([$enabled,$shopId!==''?$shopId:null,$cipher,$iv,$tag]);
}

function customer_payment_yookassa_secret(array $connection): string{return evotor_decrypt_token(['token_ciphertext'=>$connection['secret_ciphertext'],'token_iv'=>$connection['secret_iv'],'token_tag'=>$connection['secret_tag']]);}

function customer_payment_public_url(string $path): string
{
    $host=preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??''));if($host==='')throw new RuntimeException('Не удалось определить адрес Kapouch.');
    $forwarded=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??'');$scheme=$forwarded!==''?$forwarded:((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http');if($scheme!=='https')$scheme='https';
    return $scheme.'://'.$host.'/'.ltrim($path,'/');
}

function customer_payment_yookassa_request(array $connection,string $method,string $path,?array $payload=null,?string $idempotenceKey=null): array
{
    $ch=curl_init('https://api.yookassa.ru/v3'.$path);$headers=['Accept: application/json','Content-Type: application/json'];if($idempotenceKey)$headers[]='Idempotence-Key: '.$idempotenceKey;
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERPWD=>(string)$connection['merchant_login'].':'.customer_payment_yookassa_secret($connection),CURLOPT_USERAGENT=>'Kapouch/1.0 payments'];
    if($method!=='GET'){$opts[CURLOPT_CUSTOMREQUEST]=$method;$opts[CURLOPT_POSTFIELDS]=json_encode($payload??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    curl_setopt_array($ch,$opts);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$error!=='')throw new RuntimeException('Нет связи с ЮKassa: '.$error);$json=json_decode((string)$body,true);
    if($status<200||$status>=300||!is_array($json)){$message=is_array($json)?trim((string)($json['description']??$json['code']??'')):'';throw new RuntimeException('ЮKassa вернула HTTP '.$status.($message!==''?' · '.$message:''));}
    return $json;
}

function customer_payment_evotor_fiscalization_enabled(): bool{return app_setting('customer_payment_evotor_fiscalization_enabled','0')==='1';}

function customer_payment_yookassa_receipt(int $orderId,string $phone): array
{
    $vat=max(1,min(12,(int)app_setting('customer_payment_vat_code','1')));$subject=(string)app_setting('customer_payment_subject','commodity');$mode=(string)app_setting('customer_payment_mode','full_payment');
    $allowedSubjects=['commodity','excise','job','service','payment','another'];if(!in_array($subject,$allowedSubjects,true))$subject='commodity';
    $allowedModes=['full_prepayment','partial_prepayment','advance','full_payment','partial_payment','credit','credit_payment'];if(!in_array($mode,$allowedModes,true))$mode='full_payment';
    $stmt=db()->prepare('SELECT product_name,variant_name,quantity,line_total FROM online_order_items WHERE order_id=? ORDER BY sort_order,id');$stmt->execute([$orderId]);$rows=$stmt->fetchAll();if(!$rows)throw new RuntimeException('Не удалось сформировать состав чека: заказ пуст.');
    $items=[];foreach($rows as $row){$qty=(float)$row['quantity'];$line=(float)$row['line_total'];if($qty<=0||$line<0)throw new RuntimeException('Некорректная позиция в чеке.');$name=trim((string)$row['product_name']);$variant=trim((string)($row['variant_name']??''));if($variant!=='')$name.=' · '.$variant;$items[]=['description'=>mb_substr($name!==''?$name:'Позиция заказа',0,128),'quantity'=>number_format($qty,3,'.',''),'amount'=>['value'=>number_format($line,2,'.',''),'currency'=>'RUB'],'vat_code'=>$vat,'payment_mode'=>$mode,'payment_subject'=>$subject];}
    return ['customer'=>['phone'=>$phone],'items'=>$items];
}

function customer_payment_create_sbp(int $orderId,string $orderNumber,float $amount,string $phone): array
{
    $existing=customer_payment_status_for_order($orderId);if($existing&&$existing['provider']==='yookassa_sbp'&&in_array((string)$existing['status'],['pending','paid'],true)&&!empty($existing['provider_order_id']))return ['payment_url'=>(string)$existing['payment_url'],'provider_order_id'=>(string)$existing['provider_order_id'],'status'=>(string)$existing['status']];
    $connection=customer_payment_connection('yookassa_sbp');if(!$connection||!$connection['enabled'])throw new RuntimeException('Оплата по СБП сейчас недоступна.');if($amount<1)throw new RuntimeException('Минимальная сумма СБП — 1 ₽.');
    $payload=['amount'=>['value'=>number_format($amount,2,'.',''),'currency'=>'RUB'],'payment_method_data'=>['type'=>'sbp'],'confirmation'=>['type'=>'redirect','return_url'=>customer_payment_public_url('customer/payment-return.html')],'capture'=>true,'description'=>'Kapouch заказ '.$orderNumber,'metadata'=>['kapouch_order_id'=>(string)$orderId,'kapouch_order_number'=>$orderNumber]];
    if(customer_payment_evotor_fiscalization_enabled())$payload['receipt']=customer_payment_yookassa_receipt($orderId,$phone);
    db()->prepare("UPDATE online_orders SET status='awaiting_payment',payment_status='pending',payment_method='sbp',payment_provider='yookassa_sbp' WHERE id=? AND status='new'")->execute([$orderId]);
    $key=substr(hash('sha256','kapouch-yookassa-'.$orderId.'-'.$orderNumber),0,64);$response=customer_payment_yookassa_request($connection,'POST','/payments',$payload,$key);
    $providerId=trim((string)($response['id']??''));$url=trim((string)($response['confirmation']['confirmation_url']??''));if($providerId===''||$url==='')throw new RuntimeException('ЮKassa создала платёж без ссылки для оплаты.');
    $stmt=db()->prepare("INSERT INTO customer_payments(order_id,provider,method,status,amount,provider_order_id,provider_order_number,payment_url,provider_response) VALUES(?,'yookassa_sbp','sbp','pending',?,?,?,?,?) ON DUPLICATE KEY UPDATE provider_order_id=VALUES(provider_order_id),provider_order_number=VALUES(provider_order_number),payment_url=VALUES(payment_url),provider_response=VALUES(provider_response),status='pending'");
    $stmt->execute([$orderId,$amount,$providerId,$orderNumber,$url,json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);return ['payment_url'=>$url,'provider_order_id'=>$providerId,'status'=>'pending'];
}

function customer_payment_mark_cash(int $orderId): void{db()->prepare("UPDATE online_orders SET payment_status='unpaid',payment_method='cash',payment_provider=NULL WHERE id=?")->execute([$orderId]);}

function customer_payment_yookassa_sync_by_provider_id(string $providerId): ?array
{
    if(!preg_match('/^[A-Za-z0-9_-]{20,80}$/',$providerId))return null;$stmt=db()->prepare("SELECT p.*,o.total_amount FROM customer_payments p JOIN online_orders o ON o.id=p.order_id WHERE p.provider='yookassa_sbp' AND p.provider_order_id=? LIMIT 1");$stmt->execute([$providerId]);$payment=$stmt->fetch();if(!$payment)return null;
    $connection=customer_payment_connection('yookassa_sbp');if(!$connection)return null;$remote=customer_payment_yookassa_request($connection,'GET','/payments/'.rawurlencode($providerId));$remoteAmount=(float)($remote['amount']['value']??0);$paid=((string)($remote['status']??'')==='succeeded'&&abs($remoteAmount-(float)$payment['amount'])<0.001&&(string)($remote['paid']??false)==='1');
    if($paid){db()->prepare("UPDATE customer_payments SET status='paid',paid_at=COALESCE(paid_at,NOW()),provider_response=? WHERE id=?")->execute([json_encode($remote,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id']]);db()->prepare("UPDATE online_orders SET status=CASE WHEN status='awaiting_payment' THEN 'new' ELSE status END,payment_status='paid' WHERE id=?")->execute([(int)$payment['order_id']]);}else{db()->prepare('UPDATE customer_payments SET provider_response=? WHERE id=?')->execute([json_encode($remote,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id']]);}
    return ['paid'=>$paid,'order_id'=>(int)$payment['order_id'],'payment_state'=>(string)($remote['status']??'')];
}

function customer_payment_status_for_order(int $orderId): ?array{$stmt=db()->prepare('SELECT provider,method,status,amount,payment_url,sbp_payload,provider_order_id FROM customer_payments WHERE order_id=? ORDER BY id DESC LIMIT 1');$stmt->execute([$orderId]);return $stmt->fetch()?:null;}
