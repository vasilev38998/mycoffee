<?php
declare(strict_types=1);

require_once __DIR__.'/customer_auth.php';

function customer_auth_self_call_b64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
}
function customer_auth_self_call_b64url_decode(string $raw): string
{
    $raw=strtr($raw,'-_','+/');$pad=strlen($raw)%4;if($pad)$raw.=str_repeat('=',4-$pad);$decoded=base64_decode($raw,true);if($decoded===false)throw new RuntimeException('Ссылка подтверждения повреждена. Запросите новую.');return $decoded;
}
function customer_auth_self_call_sign(array $payload): string
{
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$body=customer_auth_self_call_b64url_encode($json);$sig=hash_hmac('sha256',$body,customer_auth_secret_key());return $body.'.'.$sig;
}
function customer_auth_self_call_decode(string $challenge): array
{
    $parts=explode('.',$challenge,2);if(count($parts)!==2||!preg_match('/^[A-Za-z0-9_-]{20,512}$/',$parts[0])||!preg_match('/^[a-f0-9]{64}$/',$parts[1]))throw new RuntimeException('Ссылка подтверждения повреждена. Запросите новую.');
    $expected=hash_hmac('sha256',$parts[0],customer_auth_secret_key());if(!hash_equals($expected,$parts[1]))throw new RuntimeException('Ссылка подтверждения недействительна. Запросите новую.');
    $data=json_decode(customer_auth_self_call_b64url_decode($parts[0]),true,16,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Ссылка подтверждения повреждена.');
    if((int)($data['exp']??0)<time())throw new RuntimeException('Время подтверждения истекло. Запросите новый номер.');
    return $data;
}

function customer_auth_smsru_self_call_start(string $phone): array
{
    if(customer_auth_test_mode())return ['check_id'=>'test-'.bin2hex(random_bytes(4)),'call_phone'=>'78005000000','call_phone_pretty'=>'+7 (800) 500-00-00'];
    $apiId=customer_auth_smsru_api_id();$digits=preg_replace('/\D+/','',$phone)??'';$ip=customer_auth_client_ip();
    $params=['api_id'=>$apiId,'phone'=>$digits,'ip'=>$ip!==''?$ip:'-1','json'=>1];
    db_disconnect();
    $ch=curl_init('https://sms.ru/callcheck/add');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>9,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$error!=='')throw new RuntimeException('Не удалось связаться с SMS.ru для подтверждения звонком.');
    if($http<200||$http>=300)throw new RuntimeException('SMS.ru вернул HTTP '.$http.'.');
    $data=json_decode((string)$body,true);
    if(!is_array($data)||(int)($data['status_code']??0)!==100||strtoupper((string)($data['status']??''))!=='OK')throw new RuntimeException('SMS.ru не смог подготовить номер для звонка'.(!empty($data['status_text'])?': '.$data['status_text']:'.'));
    $checkId=trim((string)($data['check_id']??''));$callPhone=preg_replace('/\D+/','',(string)($data['call_phone']??''))??'';$pretty=trim((string)($data['call_phone_pretty']??''));
    if($checkId===''||strlen($checkId)>80||$callPhone==='')throw new RuntimeException('SMS.ru не вернул номер для подтверждения. Повторите попытку.');
    return ['check_id'=>$checkId,'call_phone'=>$callPhone,'call_phone_pretty'=>$pretty!==''?$pretty:'+'.$callPhone];
}

function customer_auth_smsru_self_call_status(string $checkId): int
{
    if(customer_auth_test_mode())return 401;
    $apiId=customer_auth_smsru_api_id();db_disconnect();
    $ch=curl_init('https://sms.ru/callcheck/status');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['api_id'=>$apiId,'check_id'=>$checkId,'json'=>1]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>9,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$error!=='')throw new RuntimeException('Не удалось проверить звонок. Повторите через несколько секунд.');
    if($http<200||$http>=300)throw new RuntimeException('SMS.ru вернул HTTP '.$http.'.');
    $data=json_decode((string)$body,true);if(!is_array($data)||(int)($data['status_code']??0)!==100)throw new RuntimeException('SMS.ru не смог проверить звонок.');
    return (int)($data['check_status']??0);
}

function customer_auth_request_self_call(string $rawPhone): array
{
    $phone=customer_order_normalize_phone($rawPhone);$pdo=db();$ip=customer_auth_client_ip();
    // Self-call is the fallback for a missed incoming call, so it must be usable
    // immediately. The hourly cap below still prevents repeated provider calls.
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM customer_auth_codes WHERE phone=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)');$stmt->execute([$phone]);if((int)$stmt->fetchColumn()>=5)throw new RuntimeException('Слишком много запросов для этого номера. Попробуйте позже.');
    $stmt=null;$pdo=null;db_disconnect();$provider=customer_auth_smsru_self_call_start($phone);$checkId=(string)$provider['check_id'];
    $pdo=db();$hash=customer_auth_code_hash($phone,'callcheck:'.$checkId);$stmt=$pdo->prepare('INSERT INTO customer_auth_codes(phone,code_hash,request_ip,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE))');$stmt->execute([$phone,$hash,$ip?:null]);$rowId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE customer_auth_codes SET consumed_at=NOW() WHERE phone=? AND id<>? AND consumed_at IS NULL')->execute([$phone,$rowId]);$exp=time()+300;
    $challenge=customer_auth_self_call_sign(['v'=>1,'row'=>$rowId,'phone'=>$phone,'check'=>$checkId,'exp'=>$exp]);
    return ['phone'=>$phone,'delivery'=>'self_call','challenge'=>$challenge,'call_phone'=>(string)$provider['call_phone'],'call_phone_pretty'=>(string)$provider['call_phone_pretty'],'call_href'=>'tel:+'.(string)$provider['call_phone'],'expires_in'=>300,'poll_after'=>2];
}

function customer_auth_self_call_issue_session(PDO $pdo,string $phone): array
{
    $stmt=$pdo->prepare('SELECT id,name FROM customer_accounts WHERE phone=?');$stmt->execute([$phone]);$account=$stmt->fetch();
    if(!$account){$pdo->prepare('INSERT INTO customer_accounts(phone) VALUES(?)')->execute([$phone]);$customerId=(int)$pdo->lastInsertId();$name='';}else{$customerId=(int)$account['id'];$name=(string)($account['name']??'');}
    $token=bin2hex(random_bytes(32));$days=customer_auth_session_days();$pdo->prepare("INSERT INTO customer_sessions(customer_id,token_hash,expires_at,last_seen_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL {$days} DAY),NOW())")->execute([$customerId,hash('sha256',$token)]);
    return ['token'=>$token,'expires_in'=>$days*86400,'customer'=>['id'=>$customerId,'phone'=>$phone,'name'=>$name,'loyalty_balance'=>customer_loyalty_balance($customerId)]];
}

function customer_auth_verify_self_call(string $challenge): array
{
    $payload=customer_auth_self_call_decode(trim($challenge));$rowId=(int)($payload['row']??0);$phone=customer_order_normalize_phone((string)($payload['phone']??''));$checkId=trim((string)($payload['check']??''));if($rowId<=0||$checkId==='')throw new RuntimeException('Подтверждение не найдено. Запросите новое.');
    $pdo=db();$stmt=$pdo->prepare('SELECT id,phone,code_hash,consumed_at,CASE WHEN expires_at<=NOW() THEN 1 ELSE 0 END is_expired FROM customer_auth_codes WHERE id=? AND phone=? LIMIT 1');$stmt->execute([$rowId,$phone]);$row=$stmt->fetch();
    if(!$row||!hash_equals((string)$row['code_hash'],customer_auth_code_hash($phone,'callcheck:'.$checkId)))throw new RuntimeException('Подтверждение не найдено. Запросите новое.');
    if(!empty($row['consumed_at']))throw new RuntimeException('Этот звонок уже использован. Войдите заново, если сессия не открылась.');if((int)$row['is_expired']===1)throw new RuntimeException('Время подтверждения истекло. Запросите новый номер.');
    $stmt=null;$pdo=null;db_disconnect();$status=customer_auth_smsru_self_call_status($checkId);
    if($status===400)return ['pending'=>true,'verified'=>false,'poll_after'=>2];
    if($status===402)throw new RuntimeException('Время подтверждения истекло. Запросите новый номер.');
    if($status!==401)throw new RuntimeException('Звонок пока не подтверждён. Попробуйте ещё раз через несколько секунд.');
    $pdo=db();$pdo->beginTransaction();
    try{$stmt=$pdo->prepare('SELECT id,consumed_at,CASE WHEN expires_at<=NOW() THEN 1 ELSE 0 END is_expired FROM customer_auth_codes WHERE id=? AND phone=? FOR UPDATE');$stmt->execute([$rowId,$phone]);$locked=$stmt->fetch();if(!$locked||!empty($locked['consumed_at'])||(int)$locked['is_expired']===1)throw new RuntimeException('Подтверждение уже завершено или истекло.');$pdo->prepare('UPDATE customer_auth_codes SET consumed_at=NOW() WHERE id=?')->execute([$rowId]);$auth=customer_auth_self_call_issue_session($pdo,$phone);$pdo->commit();return ['pending'=>false,'verified'=>true]+$auth;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
