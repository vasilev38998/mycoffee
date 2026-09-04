<?php
declare(strict_types=1);

require_once __DIR__.'/evotor.php';

function customer_payment_methods(): array
{
    $cash=app_setting('customer_payment_cash_enabled','1')==='1';
    $sbp=app_setting('customer_payment_sbp_enabled','0')==='1';
    $connection=customer_payment_connection('sber_sbp');
    $sbpReady=$sbp&&$connection&&$connection['enabled']&&!empty($connection['merchant_login'])&&!empty($connection['secret_ciphertext']);
    return [
        'cash'=>['id'=>'cash','label'=>'Наличными при самовывозе','enabled'=>$cash,'online'=>false],
        'sbp'=>['id'=>'sbp','label'=>'Онлайн по СБП','enabled'=>$sbpReady,'online'=>true],
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

function customer_payment_save_sber(array $data): void
{
    $enabled=!empty($data['enabled'])?1:0;
    $test=!empty($data['test_mode'])?1:0;
    $login=trim((string)($data['merchant_login']??''));
    $password=trim((string)($data['password']??''));
    $apiBase=rtrim(trim((string)($data['api_base_url']??'')),'/');
    if($apiBase!==''&&(!filter_var($apiBase,FILTER_VALIDATE_URL)||!str_starts_with(strtolower($apiBase),'https://')))throw new RuntimeException('Адрес API Сбера должен начинаться с https://');
    $current=customer_payment_connection('sber_sbp');
    $cipher=$current['secret_ciphertext']??null;$iv=$current['secret_iv']??null;$tag=$current['secret_tag']??null;
    if($password!=='')[$cipher,$iv,$tag]=evotor_encrypt_token($password);
    if($enabled&&($login===''||!$cipher))throw new RuntimeException('Для включения СБП укажите логин и пароль платёжного шлюза Сбера.');
    $stmt=db()->prepare("INSERT INTO customer_payment_connections(provider,enabled,test_mode,merchant_login,secret_ciphertext,secret_iv,secret_tag,api_base_url) VALUES('sber_sbp',?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),test_mode=VALUES(test_mode),merchant_login=VALUES(merchant_login),secret_ciphertext=VALUES(secret_ciphertext),secret_iv=VALUES(secret_iv),secret_tag=VALUES(secret_tag),api_base_url=VALUES(api_base_url)");
    $stmt->execute([$enabled,$test,$login!==''?$login:null,$cipher,$iv,$tag,$apiBase!==''?$apiBase:null]);
}

function customer_payment_sber_secret(array $connection): string
{
    return evotor_decrypt_token(['token_ciphertext'=>$connection['secret_ciphertext'],'token_iv'=>$connection['secret_iv'],'token_tag'=>$connection['secret_tag']]);
}

function customer_payment_sber_base(array $connection): string
{
    $custom=rtrim(trim((string)($connection['api_base_url']??'')),'/');
    if($custom!=='')return $custom;
    if(!empty($connection['test_mode']))return 'https://ecomtest.sberbank.ru/ecomm/gw/partner/api/v1';
    throw new RuntimeException('Для боевого режима укажите API Base URL, выданный Сбером при подключении интернет-эквайринга.');
}

function customer_payment_public_url(string $path): string
{
    $host=preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??''));if($host==='')throw new RuntimeException('Не удалось определить адрес Kapouch.');
    $forwarded=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??'');$scheme=$forwarded!==''?$forwarded:((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http');if($scheme!=='https')$scheme='https';
    return $scheme.'://'.$host.'/'.ltrim($path,'/');
}

function customer_payment_sber_request(array $connection,string $method,array $payload): array
{
    $payload['userName']=(string)$connection['merchant_login'];$payload['password']=customer_payment_sber_secret($connection);
    $ch=curl_init(customer_payment_sber_base($connection).'/'.$method.'.do');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json'],CURLOPT_USERAGENT=>'Kapouch/1.0 payments']);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$error!=='')throw new RuntimeException('Нет связи с платёжным шлюзом Сбера: '.$error);
    $json=json_decode((string)$body,true);if($status<200||$status>=300||!is_array($json))throw new RuntimeException('Сбер вернул HTTP '.$status.' или некорректный JSON.');
    return $json;
}

function customer_payment_create_sbp(int $orderId,string $orderNumber,float $amount,string $phone): array
{
    $connection=customer_payment_connection('sber_sbp');if(!$connection||!$connection['enabled'])throw new RuntimeException('Оплата по СБП сейчас недоступна.');
    $amountKop=(int)round($amount*100);if($amountKop<=0)throw new RuntimeException('Некорректная сумма заказа.');
    $providerNumber='K'.$orderId.'-'.substr(hash('sha256',$orderNumber.'|'.$orderId),0,12);
    $payload=[
        'orderNumber'=>$providerNumber,
        'amount'=>$amountKop,
        'phone'=>$phone,
        'returnUrl'=>customer_payment_public_url('customer/payment-return.html'),
        'failUrl'=>customer_payment_public_url('customer/payment-return.html?failed=1'),
        'dynamicCallbackUrl'=>customer_payment_public_url('api/customer_payment_sber_callback.php'),
        'description'=>'Kapouch заказ '.$orderNumber,
        'language'=>'ru',
        'jsonParams'=>['qrType'=>'DYNAMIC_QR_SBP','sbp.scenario'=>'C2B','returnUrl'=>customer_payment_public_url('customer/payment-return.html')],
    ];
    $response=customer_payment_sber_request($connection,'register',$payload);
    if((string)($response['errorCode']??'')!=='0'||empty($response['orderId']))throw new RuntimeException('Сбер не создал СБП-платёж: '.trim((string)($response['errorMessage']??'ошибка регистрации заказа')));
    $formUrl=trim((string)($response['formUrl']??''));$sbpPayload=trim((string)($response['externalParams']['sbpPayload']??''));
    if($formUrl===''&&$sbpPayload==='')throw new RuntimeException('Сбер создал платёж без ссылки для оплаты.');
    $stmt=db()->prepare("INSERT INTO customer_payments(order_id,provider,method,status,amount,provider_order_id,provider_order_number,payment_url,sbp_payload,provider_response) VALUES(?,'sber_sbp','sbp','pending',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE provider_order_id=VALUES(provider_order_id),provider_order_number=VALUES(provider_order_number),payment_url=VALUES(payment_url),sbp_payload=VALUES(sbp_payload),provider_response=VALUES(provider_response),status='pending'");
    $stmt->execute([$orderId,$amount,(string)$response['orderId'],$providerNumber,$formUrl?:null,$sbpPayload?:null,json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    db()->prepare("UPDATE online_orders SET payment_status='pending',payment_method='sbp',payment_provider='sber_sbp' WHERE id=?")->execute([$orderId]);
    return ['payment_url'=>$formUrl?:$sbpPayload,'sbp_payload'=>$sbpPayload,'provider_order_id'=>(string)$response['orderId']];
}

function customer_payment_mark_cash(int $orderId): void
{
    db()->prepare("UPDATE online_orders SET payment_status='unpaid',payment_method='cash',payment_provider=NULL WHERE id=?")->execute([$orderId]);
}

function customer_payment_sber_sync_by_provider_id(string $providerOrderId): ?array
{
    if(!preg_match('/^[a-f0-9-]{30,50}$/i',$providerOrderId))return null;
    $stmt=db()->prepare("SELECT p.*,o.total_amount FROM customer_payments p JOIN online_orders o ON o.id=p.order_id WHERE p.provider='sber_sbp' AND p.provider_order_id=? LIMIT 1");$stmt->execute([$providerOrderId]);$payment=$stmt->fetch();if(!$payment)return null;
    $connection=customer_payment_connection('sber_sbp');if(!$connection)return null;
    $status=customer_payment_sber_request($connection,'getOrderStatusExtended',['orderId'=>$providerOrderId]);
    $state=strtoupper((string)($status['paymentAmountInfo']['paymentState']??''));$deposited=(int)($status['paymentAmountInfo']['depositedAmount']??0);$expected=(int)round((float)$payment['amount']*100);
    $paid=((string)($status['errorCode']??'0')==='0'&&$state==='DEPOSITED'&&$deposited===$expected);
    if($paid){
        db()->prepare("UPDATE customer_payments SET status='paid',paid_at=COALESCE(paid_at,NOW()),provider_response=? WHERE id=?")->execute([json_encode($status,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id']]);
        db()->prepare("UPDATE online_orders SET payment_status='paid' WHERE id=?")->execute([(int)$payment['order_id']]);
    }else{
        db()->prepare('UPDATE customer_payments SET provider_response=? WHERE id=?')->execute([json_encode($status,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id']]);
    }
    return ['paid'=>$paid,'order_id'=>(int)$payment['order_id'],'payment_state'=>$state];
}

function customer_payment_status_for_order(int $orderId): ?array
{
    $stmt=db()->prepare('SELECT provider,method,status,amount,payment_url,sbp_payload,provider_order_id FROM customer_payments WHERE order_id=? ORDER BY id DESC LIMIT 1');$stmt->execute([$orderId]);return $stmt->fetch()?:null;
}
