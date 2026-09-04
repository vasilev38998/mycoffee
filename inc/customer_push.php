<?php
declare(strict_types=1);

function customer_push_b64url_encode(string $value): string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
function customer_push_b64url_decode(string $value): string|false{
    $value=strtr($value,'-_','+/');$pad=strlen($value)%4;if($pad)$value.=str_repeat('=',4-$pad);return base64_decode($value,true);
}
function customer_push_hkdf_extract(string $salt,string $ikm): string{return hash_hmac('sha256',$ikm,$salt,true);}
function customer_push_hkdf_expand(string $prk,string $info,int $length): string{
    $out='';$prev='';$i=1;while(strlen($out)<$length){$prev=hash_hmac('sha256',$prev.$info.chr($i),$prk,true);$out.=$prev;$i++;}return substr($out,0,$length);
}
function customer_push_der_len(string $der,int &$offset): int{
    $first=ord($der[$offset++]);if(($first&0x80)===0)return $first;$count=$first&0x7f;$len=0;for($i=0;$i<$count;$i++)$len=($len<<8)|ord($der[$offset++]);return $len;
}
function customer_push_der_signature_to_raw(string $der): string{
    $o=0;if(ord($der[$o++])!==0x30)throw new RuntimeException('Некорректная VAPID-подпись.');customer_push_der_len($der,$o);
    if(ord($der[$o++])!==0x02)throw new RuntimeException('Некорректная VAPID-подпись.');$rl=customer_push_der_len($der,$o);$r=substr($der,$o,$rl);$o+=$rl;
    if(ord($der[$o++])!==0x02)throw new RuntimeException('Некорректная VAPID-подпись.');$sl=customer_push_der_len($der,$o);$s=substr($der,$o,$sl);
    $r=ltrim($r,"\0");$s=ltrim($s,"\0");return str_pad($r,32,"\0",STR_PAD_LEFT).str_pad($s,32,"\0",STR_PAD_LEFT);
}
function customer_push_generate_vapid(): array{
    require_once __DIR__.'/customer_auth.php';
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if($key===false)throw new RuntimeException('OpenSSL не смог создать VAPID-ключ.');
    $pem='';if(!openssl_pkey_export($key,$pem))throw new RuntimeException('Не удалось экспортировать VAPID-ключ.');$details=openssl_pkey_get_details($key);$ec=$details['ec']??null;
    if(!is_array($ec)||empty($ec['x'])||empty($ec['y']))throw new RuntimeException('Не удалось получить публичный VAPID-ключ.');
    $public=customer_push_b64url_encode("\x04".$ec['x'].$ec['y']);set_app_setting('customer_push_vapid_private',customer_auth_encrypt($pem));set_app_setting('customer_push_vapid_public',$public);return ['private'=>$pem,'public'=>$public];
}
function customer_push_vapid_keys(): array{
    require_once __DIR__.'/customer_auth.php';
    $pub=(string)app_setting('customer_push_vapid_public','');$enc=(string)app_setting('customer_push_vapid_private','');
    if($pub===''||$enc==='')return customer_push_generate_vapid();
    return ['private'=>customer_auth_decrypt($enc),'public'=>$pub];
}
function customer_push_vapid_subject(): string{
    $saved=trim((string)app_setting('customer_push_vapid_subject',''));if($saved!=='')return $saved;
    $host=preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??'kapouch.local'))?:'kapouch.local';return 'mailto:push@'.$host;
}
function customer_push_vapid_jwt(string $endpoint,string $privatePem,string $public): string{
    $parts=parse_url($endpoint);if(!$parts||empty($parts['scheme'])||empty($parts['host']))throw new RuntimeException('Некорректный push endpoint.');
    $aud=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
    $header=customer_push_b64url_encode(json_encode(['typ'=>'JWT','alg'=>'ES256'],JSON_UNESCAPED_SLASHES));
    $payload=customer_push_b64url_encode(json_encode(['aud'=>$aud,'exp'=>time()+43200,'sub'=>customer_push_vapid_subject()],JSON_UNESCAPED_SLASHES));$input=$header.'.'.$payload;
    $key=openssl_pkey_get_private($privatePem);if(!$key||!openssl_sign($input,$der,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('Не удалось подписать VAPID JWT.');
    return $input.'.'.customer_push_b64url_encode(customer_push_der_signature_to_raw($der));
}
function customer_push_client_public_pem(string $raw): string{
    if(strlen($raw)!==65||$raw[0]!=="\x04")throw new RuntimeException('Некорректный p256dh ключ подписки.');
    $der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw;return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
}
function customer_push_encrypt_payload(string $payload,string $clientKeyB64,string $authB64): string{
    $clientRaw=customer_push_b64url_decode($clientKeyB64);$auth=customer_push_b64url_decode($authB64);if($clientRaw===false||$auth===false||strlen($auth)<16)throw new RuntimeException('Некорректные ключи push-подписки.');
    $clientKey=openssl_pkey_get_public(customer_push_client_public_pem($clientRaw));if(!$clientKey)throw new RuntimeException('Не удалось прочитать ключ подписки.');
    $serverKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if(!$serverKey)throw new RuntimeException('Не удалось создать ephemeral push-ключ.');
    $details=openssl_pkey_get_details($serverKey);$ec=$details['ec']??null;if(!is_array($ec)||empty($ec['x'])||empty($ec['y']))throw new RuntimeException('Не удалось получить ephemeral push-ключ.');$serverRaw="\x04".$ec['x'].$ec['y'];
    $shared=openssl_pkey_derive($clientKey,$serverKey,32);if($shared===false)throw new RuntimeException('Не удалось вычислить push shared secret.');
    $prkKey=customer_push_hkdf_extract($auth,$shared);$ikm=customer_push_hkdf_expand($prkKey,"WebPush: info\0".$clientRaw.$serverRaw,32);$salt=random_bytes(16);$prk=customer_push_hkdf_extract($salt,$ikm);
    $cek=customer_push_hkdf_expand($prk,"Content-Encoding: aes128gcm\0",16);$nonce=customer_push_hkdf_expand($prk,"Content-Encoding: nonce\0",12);$tag='';
    $cipher=openssl_encrypt($payload."\x02",'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16);if($cipher===false)throw new RuntimeException('Не удалось зашифровать push payload.');
    return $salt.pack('N',4096).chr(strlen($serverRaw)).$serverRaw.$cipher.$tag;
}
function customer_push_send_subscription(array $sub,array $payload): array{
    $keys=customer_push_vapid_keys();$json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Не удалось собрать push payload.');
    $body=customer_push_encrypt_payload($json,(string)$sub['p256dh'],(string)$sub['auth_secret']);$jwt=customer_push_vapid_jwt((string)$sub['endpoint'],$keys['private'],$keys['public']);
    $headers=['TTL: 86400','Urgency: high','Content-Encoding: aes128gcm','Content-Type: application/octet-stream','Authorization: vapid t='.$jwt.', k='.$keys['public']];
    $ch=curl_init((string)$sub['endpoint']);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);$response=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($response===false||$error!=='')return ['ok'=>false,'http'=>$http,'error'=>$error?:'Ошибка push-сервиса','gone'=>false];
    return ['ok'=>$http>=200&&$http<300,'http'=>$http,'error'=>$http>=200&&$http<300?'':'Push HTTP '.$http,'gone'=>in_array($http,[404,410],true)];
}
function customer_push_subscribe(int $customerId,array $subscription): void{
    if($customerId<=0)throw new RuntimeException('Требуется вход.');$endpoint=trim((string)($subscription['endpoint']??''));$keys=$subscription['keys']??null;
    if(!filter_var($endpoint,FILTER_VALIDATE_URL)||!str_starts_with($endpoint,'https://')||!is_array($keys))throw new RuntimeException('Некорректная push-подписка.');
    $p256dh=trim((string)($keys['p256dh']??''));$auth=trim((string)($keys['auth']??''));if($p256dh===''||$auth==='')throw new RuntimeException('В подписке не хватает ключей.');
    if(customer_push_b64url_decode($p256dh)===false||customer_push_b64url_decode($auth)===false)throw new RuntimeException('Некорректные ключи подписки.');
    $stmt=db()->prepare("INSERT INTO customer_push_subscriptions(customer_id,endpoint,endpoint_hash,p256dh,auth_secret,user_agent,active) VALUES(?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id),endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth_secret=VALUES(auth_secret),user_agent=VALUES(user_agent),active=1,last_error=NULL");
    $stmt->execute([$customerId,$endpoint,hash('sha256',$endpoint),$p256dh,$auth,mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)?:null]);
}
function customer_push_unsubscribe(int $customerId,string $endpoint): void{
    if($customerId<=0||$endpoint==='')return;$stmt=db()->prepare('UPDATE customer_push_subscriptions SET active=0 WHERE customer_id=? AND endpoint_hash=?');$stmt->execute([$customerId,hash('sha256',$endpoint)]);
}
function customer_push_enqueue(int $customerId,string $eventType,string $title,string $body,string $url,string $dedupeKey,?int $campaignId=null): bool{
    if($customerId<=0)return false;$stmt=db()->prepare("INSERT IGNORE INTO customer_push_queue(customer_id,campaign_id,event_type,title,body,target_url,dedupe_key,status) VALUES(?,?,?,?,?,?,?,'pending')");$stmt->execute([$customerId,$campaignId,$eventType,mb_substr($title,0,120),mb_substr($body,0,500),mb_substr($url,0,500)?:null,mb_substr($dedupeKey,0,190)]);return $stmt->rowCount()>0;
}
function customer_push_enqueue_order_ready(int $orderId): bool{
    $stmt=db()->prepare('SELECT a.customer_id,o.order_number FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.order_id=?');$stmt->execute([$orderId]);$row=$stmt->fetch();if(!$row||!$row['customer_id'])return false;
    return customer_push_enqueue((int)$row['customer_id'],'order_ready','Заказ готов ☕','Заказ #'.(string)$row['order_number'].' готов. Можно забирать!','./#home','order-ready:'.$orderId);
}
function customer_push_enqueue_loyalty(int $customerId,int $orderId,float $amount): bool{
    if($amount<=0)return false;return customer_push_enqueue($customerId,'loyalty','Бонусы начислены ★','На ваш баланс начислено '.number_format($amount,2,',',' ').' бонусов.','./#profile','loyalty:'.$orderId);
}
function customer_push_customer_ids_for_segment(string $segment,?int $categoryId=null): array{
    $base=db()->query('SELECT DISTINCT customer_id FROM customer_push_subscriptions WHERE active=1')->fetchAll(PDO::FETCH_COLUMN);$ids=array_map('intval',$base);if(!$ids)return [];
    if($segment==='all')return $ids;
    if($segment==='bonus'){$rows=db()->query('SELECT id FROM customer_accounts WHERE loyalty_balance>0')->fetchAll(PDO::FETCH_COLUMN);return array_values(array_intersect($ids,array_map('intval',$rows)));}
    if($segment==='active30'){$rows=db()->query("SELECT DISTINCT a.customer_id FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE o.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchAll(PDO::FETCH_COLUMN);return array_values(array_intersect($ids,array_map('intval',$rows)));}
    if($segment==='inactive30'){$rows=db()->query("SELECT DISTINCT a.customer_id FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE o.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchAll(PDO::FETCH_COLUMN);return array_values(array_diff($ids,array_map('intval',$rows)));}
    if($segment==='category'&&$categoryId){require_once __DIR__.'/customer_pwa.php';$catalog=customer_pwa_catalog();$productIds=[];foreach($catalog['products'] as $p)if((int)$p['category_id']===$categoryId)$productIds[(int)$p['id']]=true;if(!$productIds)return [];
        $rows=db()->query("SELECT DISTINCT a.customer_id,oi.external_item_id FROM customer_order_access a JOIN online_order_items oi ON oi.order_id=a.order_id WHERE a.customer_id IS NOT NULL")->fetchAll();$matched=[];foreach($rows as $r)if(isset($productIds[(int)$r['external_item_id']]))$matched[(int)$r['customer_id']]=true;return array_values(array_intersect($ids,array_keys($matched)));}
    return $ids;
}
function customer_push_create_campaign(string $title,string $body,string $url,string $segment,?int $categoryId,?int $createdBy): array{
    $title=trim($title);$body=trim($body);if($title===''||$body==='')throw new RuntimeException('Укажите заголовок и текст push-уведомления.');if(!in_array($segment,['all','active30','inactive30','bonus','category'],true))$segment='all';if($segment!=='category')$categoryId=null;
    $pdo=db();$pdo->beginTransaction();try{$stmt=$pdo->prepare("INSERT INTO customer_push_campaigns(title,body,target_url,segment_type,category_id,status,created_by) VALUES(?,?,?,?,?,'queued',?)");$stmt->execute([mb_substr($title,0,120),mb_substr($body,0,500),mb_substr(trim($url),0,500)?:null,$segment,$categoryId,$createdBy]);$id=(int)$pdo->lastInsertId();$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $customers=customer_push_customer_ids_for_segment($segment,$categoryId);foreach($customers as $customerId)customer_push_enqueue($customerId,'campaign',$title,$body,$url,'campaign:'.$id.':customer:'.$customerId,$id);db()->prepare("UPDATE customer_push_campaigns SET recipient_count=?,status=? WHERE id=?")->execute([count($customers),count($customers)?'sending':'completed',$id]);return ['id'=>$id,'recipients'=>count($customers)];
}
function customer_push_process_queue(int $limit=30): array{
    $limit=max(1,min(100,$limit));$pdo=db();$pdo->beginTransaction();try{$rows=$pdo->query("SELECT * FROM customer_push_queue WHERE status IN ('pending','failed') AND attempts<3 AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY id LIMIT {$limit} FOR UPDATE")->fetchAll();$ids=array_map(static fn($r)=>(int)$r['id'],$rows);if($ids)$pdo->exec("UPDATE customer_push_queue SET status='processing' WHERE id IN (".implode(',',$ids).')');$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $sent=0;$failed=0;$campaigns=[];
    foreach($rows as $row){$stmt=$pdo->prepare('SELECT * FROM customer_push_subscriptions WHERE customer_id=? AND active=1');$stmt->execute([(int)$row['customer_id']]);$subs=$stmt->fetchAll();$any=false;$errors=[];
        $payload=['title'=>(string)$row['title'],'body'=>(string)$row['body'],'url'=>(string)($row['target_url']?:'./'),'tag'=>(string)$row['event_type'],'icon'=>'./assets/icon.svg','badge'=>'./assets/icon.svg'];
        foreach($subs as $sub){try{$r=customer_push_send_subscription($sub,$payload);}catch(Throwable $e){$r=['ok'=>false,'gone'=>false,'error'=>$e->getMessage(),'http'=>0];}
            if($r['ok']){$any=true;$pdo->prepare('UPDATE customer_push_subscriptions SET last_success_at=NOW(),last_error=NULL WHERE id=?')->execute([(int)$sub['id']]);}
            else{$errors[]=(string)$r['error'];$pdo->prepare('UPDATE customer_push_subscriptions SET last_failure_at=NOW(),last_error=?,active=? WHERE id=?')->execute([mb_substr((string)$r['error'],0,500),$r['gone']?0:1,(int)$sub['id']]);}}
        $attempt=(int)$row['attempts']+1;if($any){$sent++;$pdo->prepare("UPDATE customer_push_queue SET status='sent',attempts=?,processed_at=NOW(),last_error=NULL WHERE id=?")->execute([$attempt,(int)$row['id']]);}
        else{$failed++;$status=$attempt>=3?'failed':'failed';$pdo->prepare("UPDATE customer_push_queue SET status=?,attempts=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),last_error=? WHERE id=?")->execute([$status,$attempt,mb_substr(implode('; ',$errors)?:'Нет активных push-подписок',0,500),(int)$row['id']]);}
        if($row['campaign_id'])$campaigns[(int)$row['campaign_id']]=true;
    }
    foreach(array_keys($campaigns) as $campaignId){$stmt=$pdo->prepare("SELECT SUM(status='sent') sent,SUM(status='failed' AND attempts>=3) failed,SUM(status IN ('pending','processing') OR (status='failed' AND attempts<3)) waiting FROM customer_push_queue WHERE campaign_id=?");$stmt->execute([$campaignId]);$s=$stmt->fetch();$done=(int)($s['waiting']??0)===0;$pdo->prepare('UPDATE customer_push_campaigns SET sent_count=?,failed_count=?,status=?,completed_at=IF(?,NOW(),completed_at) WHERE id=?')->execute([(int)($s['sent']??0),(int)($s['failed']??0),$done?'completed':'sending',$done?1:0,$campaignId]);}
    return ['processed'=>count($rows),'sent'=>$sent,'failed'=>$failed];
}
function customer_push_stats(): array{
    return ['active_subscriptions'=>(int)db()->query('SELECT COUNT(*) FROM customer_push_subscriptions WHERE active=1')->fetchColumn(),'customers'=>(int)db()->query('SELECT COUNT(DISTINCT customer_id) FROM customer_push_subscriptions WHERE active=1')->fetchColumn(),'queued'=>(int)db()->query("SELECT COUNT(*) FROM customer_push_queue WHERE status IN ('pending','processing') OR (status='failed' AND attempts<3)")->fetchColumn()];
}
