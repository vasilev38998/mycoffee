<?php
declare(strict_types=1);

require_once __DIR__.'/evotor.php';

function evotor_order_push_connection(int $id): ?array
{
    $stmt=db()->prepare('SELECT * FROM evotor_connections WHERE id=? AND enabled=1 LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch()?:null;
}

function evotor_order_push_publisher_token(array $connection): string
{
    if(empty($connection['push_token_ciphertext'])||empty($connection['push_token_iv'])||empty($connection['push_token_tag'])){
        throw new RuntimeException('Ключ издателя Эвотор для push-уведомлений не сохранён.');
    }
    return evotor_decrypt_token([
        'token_ciphertext'=>$connection['push_token_ciphertext'],
        'token_iv'=>$connection['push_token_iv'],
        'token_tag'=>$connection['push_token_tag'],
    ]);
}

function evotor_order_push_valid_uuid(string $value): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$value);
}

function evotor_order_push_valid_device(string $value): bool
{
    return evotor_order_push_valid_uuid($value)||(bool)preg_match('/^[0-9]{15}$/',$value);
}

function evotor_order_push_ready(array $connection): bool
{
    return evotor_order_push_valid_uuid(trim((string)($connection['push_application_id']??'')))
        && evotor_order_push_valid_device(trim((string)($connection['push_device_uuid']??'')))
        && !empty($connection['push_token_ciphertext'])
        && !empty($connection['push_token_iv'])
        && !empty($connection['push_token_tag']);
}

function evotor_order_push_save(int $connectionId,array $data): array
{
    $connection=evotor_order_push_connection($connectionId);
    if(!$connection)throw new RuntimeException('Подключение Эвотор не найдено.');
    $enabled=!empty($data['enabled']);
    $applicationId=mb_substr(trim((string)($data['application_id']??'')),0,64);
    $deviceUuid=mb_substr(trim((string)($data['device_uuid']??'')),0,100);
    $publisherToken=trim((string)($data['publisher_token']??''));
    if($applicationId!==''&&!evotor_order_push_valid_uuid($applicationId))throw new RuntimeException('Application ID должен быть UUID приложения Эвотор.');
    if($deviceUuid!==''&&!evotor_order_push_valid_device($deviceUuid))throw new RuntimeException('Укажите UUID смарт-терминала или его 15-значный IMEI.');
    $cipher=$connection['push_token_ciphertext']??null;$iv=$connection['push_token_iv']??null;$tag=$connection['push_token_tag']??null;
    if($publisherToken!=='')[$cipher,$iv,$tag]=evotor_encrypt_token($publisherToken);
    if($enabled&&($applicationId===''||$deviceUuid===''||!$cipher||!$iv||!$tag))throw new RuntimeException('Чтобы включить уведомления, укажите Application ID, устройство и ключ издателя Эвотор.');
    $stmt=db()->prepare('UPDATE evotor_connections SET push_enabled=?,push_application_id=?,push_device_uuid=?,push_token_ciphertext=?,push_token_iv=?,push_token_tag=?,push_last_error=NULL WHERE id=? AND enabled=1');
    $stmt->execute([$enabled?1:0,$applicationId!==''?$applicationId:null,$deviceUuid!==''?$deviceUuid:null,$cipher,$iv,$tag,$connectionId]);
    return evotor_order_push_connection($connectionId)??$connection;
}

function evotor_order_action_b64_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
}

function evotor_order_action_b64_decode(string $value): string|false
{
    $value=strtr($value,'-_','+/');
    $padding=strlen($value)%4;
    if($padding)$value.=str_repeat('=',4-$padding);
    return base64_decode($value,true);
}

function evotor_order_action_token(int $connectionId,int $orderId,?int $expiresAt=null): string
{
    if($connectionId<=0||$orderId<=0)throw new RuntimeException('Некорректные параметры действия заказа.');
    $expiresAt=$expiresAt??(time()+8*3600);
    $payload=evotor_order_action_b64_encode(json_encode(['v'=>1,'c'=>$connectionId,'o'=>$orderId,'e'=>$expiresAt],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $signature=hash_hmac('sha256',$payload,evotor_crypto_key(),true);
    return $payload.'.'.evotor_order_action_b64_encode($signature);
}

function evotor_order_action_claims(string $token): ?array
{
    $token=trim($token);
    if($token===''||strlen($token)>600||substr_count($token,'.')!==1)return null;
    [$payload,$signature]=explode('.',$token,2);
    $signatureRaw=evotor_order_action_b64_decode($signature);
    if($signatureRaw===false||strlen($signatureRaw)!==32)return null;
    $expected=hash_hmac('sha256',$payload,evotor_crypto_key(),true);
    if(!hash_equals($expected,$signatureRaw))return null;
    $decoded=evotor_order_action_b64_decode($payload);
    if($decoded===false)return null;
    $claims=json_decode($decoded,true);
    if(!is_array($claims)||(int)($claims['v']??0)!==1)return null;
    $connectionId=(int)($claims['c']??0);$orderId=(int)($claims['o']??0);$expiresAt=(int)($claims['e']??0);
    if($connectionId<=0||$orderId<=0||$expiresAt<time()-30||$expiresAt>time()+86400)return null;
    return ['connection_id'=>$connectionId,'order_id'=>$orderId,'expires_at'=>$expiresAt];
}

function evotor_order_action_public_url(): string
{
    $configured=trim((string)app_setting('customer_app_url',''));
    if($configured!==''&&filter_var($configured,FILTER_VALIDATE_URL)){
        $parts=parse_url($configured);
        if(is_array($parts)&&strtolower((string)($parts['scheme']??''))==='https'&&!empty($parts['host'])){
            $port=isset($parts['port'])?':'.(int)$parts['port']:'';
            return 'https://'.$parts['host'].$port.'/api/evotor_order_action.php';
        }
    }
    $host=preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??''));
    if($host!=='')return 'https://'.$host.'/api/evotor_order_action.php';
    return 'https://kapouch.store/api/evotor_order_action.php';
}

function evotor_order_action_apply(int $orderId,string $action): array
{
    require_once __DIR__.'/online_orders.php';
    $action=trim($action);
    if(!in_array($action,['accept','ready'],true))throw new RuntimeException('Неизвестное действие заказа.');
    $stmt=db()->prepare('SELECT id,order_number,source,status,payment_status FROM online_orders WHERE id=? LIMIT 1');
    $stmt->execute([$orderId]);$order=$stmt->fetch();
    if(!$order||(string)$order['source']!=='customer-web')throw new RuntimeException('PWA-заказ не найден.');
    $status=(string)$order['status'];
    if($status==='cancelled')throw new RuntimeException('Заказ уже отменён.');
    if($action==='accept'){
        if($status==='new')online_orders_transition($orderId,'preparing');
        elseif(!in_array($status,['preparing','ready','completed'],true))throw new RuntimeException('Заказ нельзя принять в текущем статусе.');
    }else{
        if($status==='new')throw new RuntimeException('Сначала примите заказ.');
        if($status==='preparing')online_orders_transition($orderId,'ready');
        elseif(!in_array($status,['ready','completed'],true))throw new RuntimeException('Заказ нельзя отметить готовым в текущем статусе.');
    }
    $stmt=db()->prepare('SELECT id,order_number,status,payment_status FROM online_orders WHERE id=? LIMIT 1');
    $stmt->execute([$orderId]);$current=$stmt->fetch();
    if(!$current)throw new RuntimeException('Заказ не найден после изменения статуса.');
    return [
        'order_id'=>(int)$current['id'],
        'order_number'=>(string)$current['order_number'],
        'status'=>(string)$current['status'],
        'status_label'=>online_orders_status_label((string)$current['status']),
        'payment_status'=>(string)($current['payment_status']??''),
    ];
}

function evotor_order_push_payload(int $orderId): array
{
    $stmt=db()->prepare("SELECT id,order_number,source,status,payment_status,payment_method,total_amount,promised_at,customer_name,customer_phone FROM online_orders WHERE id=? LIMIT 1");
    $stmt->execute([$orderId]);$order=$stmt->fetch();
    if(!$order)throw new RuntimeException('Заказ для уведомления не найден.');
    $items=db()->prepare('SELECT product_name,variant_name,quantity FROM online_order_items WHERE order_id=? ORDER BY sort_order,id LIMIT 8');
    $items->execute([$orderId]);$rows=$items->fetchAll();
    $parts=[];$units=0.0;
    foreach($rows as $row){
        $qty=(float)$row['quantity'];$units+=$qty;$name=trim((string)$row['product_name']);$variant=trim((string)($row['variant_name']??''));
        if($variant!=='')$name.=' · '.$variant;
        if(count($parts)<3)$parts[]=rtrim(rtrim(number_format($qty,2,'.',''),'0'),'.').'× '.$name;
    }
    $pickup='';if(!empty($order['promised_at'])){$ts=strtotime((string)$order['promised_at']);if($ts!==false)$pickup=date('H:i',$ts);}
    $title='Новый заказ '.$order['order_number'];
    $description=number_format((float)$order['total_amount'],0,',',' ').' ₽';
    if($pickup!=='')$description.=' · к '.$pickup;
    if($parts)$description.=' · '.implode(', ',$parts);
    $description=mb_substr($description,0,520);
    return [
        'type'=>'new_order',
        'order_id'=>(string)$orderId,
        'order_number'=>(string)$order['order_number'],
        'status'=>'new',
        'title'=>$title,
        'description'=>$description,
        'amount'=>number_format((float)$order['total_amount'],2,'.',''),
        'pickup_time'=>$pickup,
        'items_count'=>(string)(int)round($units),
    ];
}

function evotor_order_push_validate_response(array $response): array
{
    $state=strtoupper(trim((string)($response['status']??'')));
    if(!in_array($state,['ACCEPTED','RUNNING','COMPLETED'],true)){
        throw new RuntimeException('Облако Эвотор не приняло push-уведомление'.($state!==''?': '.$state:'.'));
    }
    return $response;
}

function evotor_order_push_http(array $connection,array $payload): array
{
    $applicationId=trim((string)$connection['push_application_id']);$deviceUuid=trim((string)$connection['push_device_uuid']);
    $url='https://api.evotor.ru/api/apps/'.rawurlencode($applicationId).'/devices/'.rawurlencode($deviceUuid).'/push-notifications';
    $request=['payload'=>$payload,'active_until'=>gmdate('Y-m-d\TH:i:s.000\Z',time()+600)];
    $encoded=json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($encoded)>1900)throw new RuntimeException('Push-уведомление Эвотор получилось слишком большим.');
    if(isset($GLOBALS['kapouch_evotor_push_transport'])&&is_callable($GLOBALS['kapouch_evotor_push_transport'])){
        $result=($GLOBALS['kapouch_evotor_push_transport'])($url,evotor_order_push_publisher_token($connection),$request);
        if(!is_array($result))throw new RuntimeException('Тестовый transport Эвотор вернул некорректный ответ.');
        return evotor_order_push_validate_response($result);
    }
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,
        CURLOPT_HTTPHEADER=>['Accept: application/vnd.evotor.v2+json','Content-Type: application/json','Authorization: Bearer '.evotor_order_push_publisher_token($connection)],
        CURLOPT_POSTFIELDS=>$encoded,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$error!=='')throw new RuntimeException('Нет связи с push API Эвотор: '.$error);
    $json=json_decode((string)$body,true);
    if($status<200||$status>=300||!is_array($json)){
        $detail=is_array($json)?json_encode($json,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):mb_substr((string)$body,0,500);
        throw new RuntimeException('Push API Эвотор вернул HTTP '.$status.($detail!==''?': '.mb_substr((string)$detail,0,500):''));
    }
    return evotor_order_push_validate_response($json);
}

function evotor_order_push_dispatch_log(int $logId): bool
{
    $stmt=db()->prepare('SELECT l.payload_json,l.connection_id,c.* FROM evotor_order_push_log l JOIN evotor_connections c ON c.id=l.connection_id WHERE l.id=? LIMIT 1');
    $stmt->execute([$logId]);$row=$stmt->fetch();if(!$row)return false;
    if(empty($row['enabled'])||empty($row['push_enabled']))return false;
    $payload=json_decode((string)$row['payload_json'],true);if(!is_array($payload))throw new RuntimeException('В очереди Эвотор сохранён некорректный payload.');
    db()->prepare('UPDATE evotor_order_push_log SET attempts=attempts+1,last_error=NULL WHERE id=?')->execute([$logId]);
    try{
        $response=evotor_order_push_http($row,$payload);$pushId=trim((string)($response['id']??''));
        db()->prepare("UPDATE evotor_order_push_log SET status='sent',provider_push_id=?,sent_at=NOW(),last_error=NULL WHERE id=?")->execute([$pushId!==''?$pushId:null,$logId]);
        db()->prepare('UPDATE evotor_connections SET push_last_sent_at=NOW(),push_last_error=NULL WHERE id=?')->execute([(int)$row['connection_id']]);
        return true;
    }catch(Throwable $e){
        $message=mb_substr($e->getMessage(),0,1000);
        db()->prepare("UPDATE evotor_order_push_log SET status='error',last_error=? WHERE id=?")->execute([$message,$logId]);
        db()->prepare('UPDATE evotor_connections SET push_last_error=? WHERE id=?')->execute([$message,(int)$row['connection_id']]);
        error_log('[Kapouch Evotor push] '.$message);
        return false;
    }
}

function evotor_order_notify_new(int $orderId): array
{
    if($orderId<=0)return ['queued'=>0,'sent'=>0];
    $order=db()->prepare('SELECT source,status FROM online_orders WHERE id=? LIMIT 1');$order->execute([$orderId]);$state=$order->fetch();
    if(!$state||(string)$state['source']!=='customer-web'||(string)$state['status']!=='new')return ['queued'=>0,'sent'=>0];
    $basePayload=evotor_order_push_payload($orderId);
    $connections=db()->query('SELECT * FROM evotor_connections WHERE enabled=1 AND push_enabled=1 ORDER BY id')->fetchAll();$queued=0;$sent=0;
    foreach($connections as $connection){
        if(!evotor_order_push_ready($connection))continue;
        $expiresAt=time()+8*3600;
        $payload=$basePayload;
        $payload['action_url']=evotor_order_action_public_url();
        $payload['action_token']=evotor_order_action_token((int)$connection['id'],$orderId,$expiresAt);
        $payload['action_expires_at']=(string)$expiresAt;
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $insert=db()->prepare("INSERT IGNORE INTO evotor_order_push_log(connection_id,order_id,event_type,status,payload_json) VALUES(?,?,'new_order','pending',?)");
        $insert->execute([(int)$connection['id'],$orderId,$json]);
        if($insert->rowCount()!==1)continue;
        $queued++;$logId=(int)db()->lastInsertId();if($logId>0&&evotor_order_push_dispatch_log($logId))$sent++;
    }
    return ['queued'=>$queued,'sent'=>$sent];
}

function evotor_order_push_test(int $connectionId): array
{
    $connection=evotor_order_push_connection($connectionId);if(!$connection)throw new RuntimeException('Подключение Эвотор не найдено.');
    if(!evotor_order_push_ready($connection))throw new RuntimeException('Сначала сохраните Application ID, устройство и ключ издателя.');
    return evotor_order_push_http($connection,[
        'type'=>'test','order_id'=>'0','order_number'=>'TEST','title'=>'Kapouch · тест уведомления',
        'description'=>'Если вы видите это сообщение на Эвоторе, push-уведомления настроены правильно.','amount'=>'0.00','pickup_time'=>date('H:i'),'items_count'=>'0',
    ]);
}

function evotor_order_push_retry_pending(int $limit=20): array
{
    $limit=max(1,min(100,$limit));
    $rows=db()->query("SELECT l.id FROM evotor_order_push_log l JOIN evotor_connections c ON c.id=l.connection_id JOIN online_orders o ON o.id=l.order_id WHERE l.status IN ('pending','error') AND l.attempts<5 AND l.created_at>=DATE_SUB(NOW(),INTERVAL 30 MINUTE) AND c.enabled=1 AND c.push_enabled=1 AND o.status IN ('new','preparing') ORDER BY l.id LIMIT {$limit}")->fetchAll(PDO::FETCH_COLUMN);
    $sent=0;foreach($rows as $id)if(evotor_order_push_dispatch_log((int)$id))$sent++;
    return ['processed'=>count($rows),'sent'=>$sent];
}

function evotor_order_push_recent(int $connectionId,int $limit=20): array
{
    $limit=max(1,min(100,$limit));$stmt=db()->prepare("SELECT l.*,o.order_number FROM evotor_order_push_log l JOIN online_orders o ON o.id=l.order_id WHERE l.connection_id=? ORDER BY l.id DESC LIMIT {$limit}");$stmt->execute([$connectionId]);return $stmt->fetchAll();
}
