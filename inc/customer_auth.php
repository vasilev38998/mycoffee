<?php
declare(strict_types=1);

require_once __DIR__.'/customer_orders.php';

function customer_auth_secret_key(): string
{
    global $config;
    $secret=$config['security']['encryption_key']??(($config['db']['name']??'').'|'.($config['db']['user']??'').'|'.($config['db']['pass']??''));
    return hash('sha256',(string)$secret,true);
}

function customer_auth_encrypt(string $value): string
{
    if($value==='')return '';
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($value,'aes-256-gcm',customer_auth_secret_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)throw new RuntimeException('Не удалось зашифровать секрет SMS.ru.');
    return base64_encode($iv.$tag.$cipher);
}

function customer_auth_decrypt(string $value): string
{
    if($value==='')return '';
    $raw=base64_decode($value,true);
    if($raw===false||strlen($raw)<29)throw new RuntimeException('Не удалось прочитать секрет SMS.ru.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',customer_auth_secret_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    if($plain===false)throw new RuntimeException('Не удалось расшифровать секрет SMS.ru.');
    return $plain;
}

function customer_auth_smsru_configured(): bool{return (string)app_setting('smsru_api_id','')!=='';}
function customer_auth_code_hash(string $phone,string $code): string{return hash_hmac('sha256',$phone.'|'.$code,customer_auth_secret_key());}
function customer_auth_client_ip(): string{return mb_substr(trim((string)($_SERVER['REMOTE_ADDR']??'')),0,64);}

function customer_auth_send_smsru(string $phone,string $code): void
{
    $encrypted=(string)app_setting('smsru_api_id','');
    if($encrypted==='')throw new RuntimeException('SMS.ru ещё не настроен в Kapouch.');
    $apiId=customer_auth_decrypt($encrypted);
    $digits=preg_replace('/\D+/','',$phone)??'';
    $params=['api_id'=>$apiId,'to'=>$digits,'msg'=>'Код входа Kapouch: '.$code.'. Никому не сообщайте этот код.','json'=>1,'ip'=>customer_auth_client_ip()];
    $sender=trim((string)app_setting('smsru_sender',''));
    if($sender!=='')$params['from']=$sender;
    if((string)app_setting('smsru_test_mode','0')==='1')$params['test']=1;
    $ch=curl_init('https://sms.ru/sms/send');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$error!=='')throw new RuntimeException('Не удалось связаться с SMS.ru.');
    if($http<200||$http>=300)throw new RuntimeException('SMS.ru вернул HTTP '.$http.'.');
    $data=json_decode((string)$body,true);
    if(!is_array($data)||(int)($data['status_code']??0)!==100)throw new RuntimeException('SMS.ru отклонил запрос'.(!empty($data['status_text'])?': '.$data['status_text']:'.'));
    $sms=$data['sms'][$digits]??null;
    if(!is_array($sms)||(int)($sms['status_code']??0)!==100)throw new RuntimeException('SMS.ru не принял сообщение'.(!empty($sms['status_text'])?': '.$sms['status_text']:'.'));
}

function customer_auth_request_code(string $rawPhone): array
{
    $phone=customer_order_normalize_phone($rawPhone);$pdo=db();$ip=customer_auth_client_ip();
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM customer_auth_codes WHERE phone=? AND created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND)');$stmt->execute([$phone]);
    if((int)$stmt->fetchColumn()>0)throw new RuntimeException('Код уже отправлен. Повторите через минуту.');
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM customer_auth_codes WHERE phone=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)');$stmt->execute([$phone]);
    if((int)$stmt->fetchColumn()>=5)throw new RuntimeException('Слишком много кодов для этого номера. Попробуйте позже.');
    if($ip!==''){$stmt=$pdo->prepare('SELECT COUNT(*) FROM customer_auth_codes WHERE request_ip=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)');$stmt->execute([$ip]);if((int)$stmt->fetchColumn()>=20)throw new RuntimeException('Слишком много запросов. Попробуйте позже.');}
    $testMode=(string)app_setting('smsru_test_mode','0')==='1';
    $code=$testMode?'999999':(string)random_int(100000,999999);
    customer_auth_send_smsru($phone,$code);
    $stmt=$pdo->prepare('INSERT INTO customer_auth_codes(phone,code_hash,request_ip,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE))');
    $stmt->execute([$phone,customer_auth_code_hash($phone,$code),$ip?:null]);
    $result=['phone'=>$phone,'expires_in'=>300,'resend_in'=>60];
    if($testMode)$result['test_code']='999999';
    return $result;
}

function customer_auth_verify_code(string $rawPhone,string $code): array
{
    $phone=customer_order_normalize_phone($rawPhone);$code=trim($code);
    if(!preg_match('/^\d{6}$/',$code))throw new RuntimeException('Введите 6 цифр из SMS.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT *,CASE WHEN expires_at<=NOW() THEN 1 ELSE 0 END AS is_expired FROM customer_auth_codes WHERE phone=? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');$stmt->execute([$phone]);$row=$stmt->fetch();
        if(!$row)throw new RuntimeException('Запросите новый код.');
        if((int)$row['is_expired']===1)throw new RuntimeException('Код истёк. Запросите новый.');
        if((int)$row['attempts']>=5)throw new RuntimeException('Превышено число попыток. Запросите новый код.');
        if(!hash_equals((string)$row['code_hash'],customer_auth_code_hash($phone,$code))){$pdo->prepare('UPDATE customer_auth_codes SET attempts=attempts+1 WHERE id=?')->execute([(int)$row['id']]);$pdo->commit();throw new RuntimeException('Неверный код.');}
        $pdo->prepare('UPDATE customer_auth_codes SET consumed_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        $stmt=$pdo->prepare('SELECT id,name FROM customer_accounts WHERE phone=?');$stmt->execute([$phone]);$account=$stmt->fetch();
        if(!$account){$pdo->prepare('INSERT INTO customer_accounts(phone) VALUES(?)')->execute([$phone]);$customerId=(int)$pdo->lastInsertId();$name='';}else{$customerId=(int)$account['id'];$name=(string)($account['name']??'');}
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
        $pdo->prepare('INSERT INTO customer_sessions(customer_id,token_hash,expires_at,last_seen_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY),NOW())')->execute([$customerId,$hash]);
        $pdo->commit();
        return ['token'=>$token,'expires_in'=>2592000,'customer'=>['id'=>$customerId,'phone'=>$phone,'name'=>$name,'loyalty_balance'=>customer_loyalty_balance($customerId)]];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_auth_bearer_token(): string
{
    $direct=trim((string)($_SERVER['HTTP_X_CUSTOMER_TOKEN']??''));
    if($direct!=='')return $direct;
    $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    return preg_match('/^Bearer\s+(.+)$/i',$auth,$m)?trim($m[1]):'';
}

function customer_auth_current(): ?array
{
    $token=customer_auth_bearer_token();if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;
    $stmt=db()->prepare('SELECT s.id session_id,s.customer_id,c.phone,c.name,c.loyalty_balance FROM customer_sessions s JOIN customer_accounts c ON c.id=s.customer_id WHERE s.token_hash=? AND s.expires_at>NOW() LIMIT 1');
    $stmt->execute([hash('sha256',$token)]);$row=$stmt->fetch();if(!$row)return null;
    db()->prepare('UPDATE customer_sessions SET last_seen_at=NOW() WHERE id=?')->execute([(int)$row['session_id']]);
    return ['session_id'=>(int)$row['session_id'],'id'=>(int)$row['customer_id'],'phone'=>(string)$row['phone'],'name'=>(string)($row['name']??''),'loyalty_balance'=>(float)$row['loyalty_balance']];
}
function customer_auth_require(): array{$customer=customer_auth_current();if(!$customer)throw new RuntimeException('AUTH_REQUIRED');return $customer;}
function customer_auth_logout(): void{$token=customer_auth_bearer_token();if($token==='')return;db()->prepare('DELETE FROM customer_sessions WHERE token_hash=?')->execute([hash('sha256',$token)]);}

function customer_auth_profile(array $customer): array
{
    $stmt=db()->prepare("SELECT o.order_number,o.status,o.total_amount,o.external_created_at,o.created_at,o.updated_at,a.tracking_token FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.customer_id=? ORDER BY o.id DESC LIMIT 30");
    $stmt->execute([(int)$customer['id']]);$orders=$stmt->fetchAll();
    foreach($orders as &$order){$order['status_label']=online_orders_status_label((string)$order['status']);$order['total_amount']=(float)$order['total_amount'];}unset($order);
    $ledger=db()->prepare('SELECT amount,operation_type,note,created_at FROM customer_loyalty_ledger WHERE customer_id=? ORDER BY id DESC LIMIT 30');$ledger->execute([(int)$customer['id']]);
    return ['customer'=>['id'=>$customer['id'],'phone'=>$customer['phone'],'name'=>$customer['name'],'loyalty_balance'=>customer_loyalty_balance((int)$customer['id'])],'orders'=>$orders,'loyalty'=>$ledger->fetchAll()];
}

function customer_auth_cleanup(): array
{
    $codes=(int)db()->exec('DELETE FROM customer_auth_codes WHERE expires_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');
    $sessions=(int)db()->exec('DELETE FROM customer_sessions WHERE expires_at<NOW()');
    return ['codes'=>$codes,'sessions'=>$sessions];
}
