<?php
declare(strict_types=1);

function online_orders_api_token(): string
{
    $token=(string)app_setting('online_orders_api_token','');
    if($token!=='')return $token;
    $token=bin2hex(random_bytes(24));
    set_app_setting('online_orders_api_token',$token);
    return $token;
}

function online_orders_status_labels(): array
{
    return ['new'=>'Новый','preparing'=>'Готовится','ready'=>'Готов','completed'=>'Выдан','cancelled'=>'Отменён'];
}
function online_orders_status_label(string $status): string{return online_orders_status_labels()[$status]??$status;}
function online_orders_allowed_statuses(): array{return array_keys(online_orders_status_labels());}
function online_orders_payment_label(?string $status): string
{
    $status=mb_strtolower(trim((string)$status));
    return ['paid'=>'Оплачено','pending'=>'Ожидает оплаты','unpaid'=>'Не оплачено','refunded'=>'Возврат'][$status]??($status!==''?$status:'Не указано');
}
function online_orders_fulfillment_label(array $order): string
{
    $custom=trim((string)($order['fulfillment_label']??''));
    if($custom!=='')return $custom;
    return match((string)($order['fulfillment_type']??'pickup')){'delivery'=>'Доставка','other'=>'Другое',default=>'Самовывоз'};
}

function online_orders_transition(int $id,string $status): void
{
    if($id<=0||!in_array($status,online_orders_allowed_statuses(),true))throw new RuntimeException('Некорректный статус заказа.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT id,status FROM online_orders WHERE id=? FOR UPDATE');$stmt->execute([$id]);$order=$stmt->fetch();
        if(!$order)throw new RuntimeException('Заказ не найден.');
        $allowed=['new'=>['preparing','ready','cancelled'],'preparing'=>['ready','cancelled'],'ready'=>['completed','preparing','cancelled'],'completed'=>[],'cancelled'=>[]];
        $from=(string)$order['status'];
        if($from===$status){$pdo->commit();return;}
        if(!in_array($status,$allowed[$from]??[],true))throw new RuntimeException('Нельзя перевести заказ из «'.online_orders_status_label($from).'» в «'.online_orders_status_label($status).'». Обновите очередь: возможно, заказ уже изменил другой сотрудник.');
        $sql=match($status){
            'preparing'=>'UPDATE online_orders SET status=?,preparing_at=NOW(),ready_at=NULL,completed_at=NULL,cancelled_at=NULL WHERE id=? AND status=?',
            'ready'=>'UPDATE online_orders SET status=?,ready_at=NOW(),completed_at=NULL,cancelled_at=NULL WHERE id=? AND status=?',
            'completed'=>'UPDATE online_orders SET status=?,completed_at=NOW(),cancelled_at=NULL WHERE id=? AND status=?',
            'cancelled'=>'UPDATE online_orders SET status=?,cancelled_at=NOW() WHERE id=? AND status=?',
            default=>'UPDATE online_orders SET status=? WHERE id=? AND status=?',
        };
        $update=$pdo->prepare($sql);$update->execute([$status,$id,$from]);
        if($update->rowCount()!==1)throw new RuntimeException('Статус заказа уже изменился. Обновите очередь и повторите действие.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    try{audit_write('online_order_status','Статус онлайн-заказа: '.online_orders_status_label($from).' → '.online_orders_status_label($status),'online_order',(string)$id);}catch(Throwable $e){error_log('[Kapouch order audit] '.$e->getMessage());}
    if($status==='ready'){
        try{require_once __DIR__.'/customer_push.php';customer_push_enqueue_order_ready($id);}catch(Throwable $e){error_log('[Kapouch order ready push] '.$e->getMessage());}
    }elseif($status==='completed'){
        try{
            require_once __DIR__.'/customer_loyalty.php';$amount=customer_loyalty_on_order_completed($id);
            if($amount>0){require_once __DIR__.'/customer_push.php';$stmt=db()->prepare('SELECT customer_id FROM customer_order_access WHERE order_id=?');$stmt->execute([$id]);$customerId=(int)($stmt->fetchColumn()?:0);if($customerId>0)customer_push_enqueue_loyalty($customerId,$id,$amount);}
        }catch(Throwable $e){error_log('[Kapouch order completion] '.$e->getMessage());}
    }
}

function online_orders_fetch(string $filter='active',int $limit=80): array
{
    $limit=max(1,min(200,$limit));
    $where=$filter==='all'?'1=1':($filter==='done'?"o.status IN ('completed','cancelled')":"o.status IN ('new','preparing','ready')");
    $orderBy=$filter==='done'?'o.updated_at DESC,o.id DESC':"CASE o.status WHEN 'ready' THEN 1 WHEN 'preparing' THEN 2 WHEN 'new' THEN 3 ELSE 4 END,COALESCE(o.promised_at,o.external_created_at,o.created_at),o.id";
    $orders=db()->query("SELECT o.* FROM online_orders o WHERE {$where} ORDER BY {$orderBy} LIMIT {$limit}")->fetchAll();
    if(!$orders)return [];
    $ids=array_map(static fn($o)=>(int)$o['id'],$orders);
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT * FROM online_order_items WHERE order_id IN ({$placeholders}) ORDER BY order_id,sort_order,id");$stmt->execute($ids);
    $items=[];foreach($stmt->fetchAll() as $item)$items[(int)$item['order_id']][]=$item;
    $now=time();
    foreach($orders as &$order){
        $order['items']=$items[(int)$order['id']]??[];
        $order['status_label']=online_orders_status_label((string)$order['status']);
        $order['payment_label']=online_orders_payment_label($order['payment_status']??null);
        $order['fulfillment_display']=online_orders_fulfillment_label($order);
        $created=(string)($order['external_created_at']?:$order['created_at']);
        $createdTs=$created!==''?strtotime($created):false;
        $order['age_seconds']=$createdTs===false?0:max(0,$now-$createdTs);
        $order['created_display']=$createdTs===false?'':date('d.m H:i',$createdTs);
        $promised=(string)($order['promised_at']??'');$promisedTs=$promised!==''?strtotime($promised):false;
        $order['promised_display']=$promisedTs===false?'':date('H:i',$promisedTs);
    }
    unset($order);
    return $orders;
}

function online_orders_create_test(): int
{
    $suffix=date('His').'-'.strtoupper(bin2hex(random_bytes(1)));
    $payload=[
        'external_id'=>'kapouch-test-'.date('Ymd').'-'.$suffix,
        'order_number'=>'TEST-'.$suffix,
        'source'=>'Kapouch test',
        'customer'=>['name'=>'Тестовый гость','phone'=>'+7 900 000-00-00'],
        'fulfillment'=>['type'=>'pickup','label'=>'Самовывоз'],
        'payment_status'=>'paid',
        'comment'=>'Тестовый заказ. Один напиток без сахара.',
        'promised_at'=>date('c',time()+15*60),
        'created_at'=>date('c'),
        'items'=>[
            ['external_id'=>'1','name'=>'Капучино','variant'=>'350 мл','quantity'=>1,'unit_price'=>250,'comment'=>null],
            ['external_id'=>'2','name'=>'Латте','variant'=>'450 мл','quantity'=>1,'unit_price'=>240,'comment'=>'Без сахара'],
        ],
    ];
    $result=online_orders_upsert_from_api($payload);
    $id=(int)$result['id'];
    audit_write('online_order_test_created','Создан тестовый онлайн-заказ через общий API-процессор','online_order',(string)$id);
    return $id;
}
function online_orders_clear_tests(): int
{
    $count=(int)db()->exec("DELETE FROM online_orders WHERE source='Kapouch test'");
    audit_write('online_order_tests_cleared','Удалены тестовые онлайн-заказы: '.$count,'online_order');
    return $count;
}
function online_orders_cleanup_test_orders(int $hours=24): int
{
    $hours=max(1,min(720,$hours));
    return (int)db()->exec("DELETE FROM online_orders WHERE source='Kapouch test' AND status IN ('completed','cancelled') AND updated_at<DATE_SUB(NOW(),INTERVAL {$hours} HOUR)");
}

function online_orders_api_authorized(): bool
{
    $expected=online_orders_api_token();$provided=trim((string)($_SERVER['HTTP_X_KAPOUCH_TOKEN']??''));
    if($provided===''){$auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));if(preg_match('/^Bearer\s+(.+)$/i',$auth,$m))$provided=trim($m[1]);}
    return $provided!==''&&hash_equals($expected,$provided);
}
function online_orders_api_payload(): array
{
    $raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')throw new RuntimeException('Пустое тело запроса.');
    $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('JSON должен быть объектом.');return $data;
}
function online_orders_parse_datetime(mixed $value): ?string
{
    if($value===null||trim((string)$value)==='')return null;
    try{$d=new DateTime((string)$value);$d->setTimezone(new DateTimeZone(date_default_timezone_get()));return $d->format('Y-m-d H:i:s');}catch(Throwable $e){throw new RuntimeException('Некорректная дата/время: '.(string)$value);}
}

function online_orders_upsert_from_api(array $data): array
{
    $externalId=trim((string)($data['external_id']??''));$orderNumber=trim((string)($data['order_number']??$externalId));
    if($externalId===''||mb_strlen($externalId)>190)throw new RuntimeException('Поле external_id обязательно и должно быть короче 191 символа.');
    if($orderNumber==='')$orderNumber=$externalId;
    $source=trim((string)($data['source']??'online'))?:'online';$customer=is_array($data['customer']??null)?$data['customer']:[];$fulfillment=is_array($data['fulfillment']??null)?$data['fulfillment']:[];
    $type=(string)($fulfillment['type']??$data['fulfillment_type']??'pickup');if(!in_array($type,['pickup','delivery','other'],true))$type='other';
    $items=$data['items']??null;if(!is_array($items)||!$items)throw new RuntimeException('Добавьте хотя бы одну позицию в items.');
    $total=array_key_exists('total_amount',$data)?(float)$data['total_amount']:0.0;if($total<0)throw new RuntimeException('total_amount не может быть отрицательным.');
    $preparedItems=[];$calculated=0.0;$sort=0;
    foreach($items as $row){
        if(!is_array($row))throw new RuntimeException('Каждая позиция items должна быть объектом.');
        $name=trim((string)($row['name']??$row['product_name']??''));$qty=(float)($row['quantity']??1);$price=(float)($row['unit_price']??0);
        if($name===''||$qty<=0||$price<0)throw new RuntimeException('У каждой позиции нужны name, quantity > 0 и unit_price >= 0.');
        $line=array_key_exists('line_total',$row)?(float)$row['line_total']:$qty*$price;if($line<0)throw new RuntimeException('line_total не может быть отрицательным.');
        $calculated+=$line;$sort++;
        $preparedItems[]=['external_item_id'=>trim((string)($row['external_id']??$row['id']??''))?:null,'product_name'=>mb_substr($name,0,190),'variant_name'=>mb_substr(trim((string)($row['variant']??$row['variant_name']??'')),0,160)?:null,'quantity'=>$qty,'unit_price'=>$price,'line_total'=>$line,'item_comment'=>mb_substr(trim((string)($row['comment']??'')),0,500)?:null,'sort_order'=>$sort];
    }
    if(!array_key_exists('total_amount',$data))$total=$calculated;
    $pdo=db();$pdo->beginTransaction();
    try{
        $find=$pdo->prepare('SELECT id,status FROM online_orders WHERE external_id=? FOR UPDATE');$find->execute([$externalId]);$existing=$find->fetch();$new=!$existing;
        $values=[mb_substr($orderNumber,0,80),mb_substr($source,0,80),mb_substr(trim((string)($customer['name']??$data['customer_name']??'')),0,160)?:null,mb_substr(trim((string)($customer['phone']??$data['customer_phone']??'')),0,80)?:null,$type,mb_substr(trim((string)($fulfillment['label']??$fulfillment['address']??$data['fulfillment_label']??'')),0,160)?:null,mb_substr(trim((string)($data['payment_status']??'')),0,40)?:null,$total,trim((string)($data['comment']??$data['customer_comment']??''))?:null,online_orders_parse_datetime($data['promised_at']??null),online_orders_parse_datetime($data['created_at']??null)];
        if($new){
            $stmt=$pdo->prepare("INSERT INTO online_orders(external_id,order_number,source,status,customer_name,customer_phone,fulfillment_type,fulfillment_label,payment_status,total_amount,customer_comment,promised_at,external_created_at) VALUES(?,?,?,'new',?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$externalId,...$values]);$id=(int)$pdo->lastInsertId();$status='new';
        }else{
            $id=(int)$existing['id'];$status=(string)$existing['status'];
            if(in_array($status,['new','awaiting_payment'],true)){
                $stmt=$pdo->prepare('UPDATE online_orders SET order_number=?,source=?,customer_name=?,customer_phone=?,fulfillment_type=?,fulfillment_label=?,payment_status=?,total_amount=?,customer_comment=?,promised_at=?,external_created_at=COALESCE(?,external_created_at) WHERE id=?');
                $stmt->execute([...$values,$id]);$pdo->prepare('DELETE FROM online_order_items WHERE order_id=?')->execute([$id]);
            }else{
                $pdo->commit();
                return ['id'=>$id,'external_id'=>$externalId,'order_number'=>$orderNumber,'created'=>false,'status'=>$status,'locked'=>true];
            }
        }
        $insert=$pdo->prepare('INSERT INTO online_order_items(order_id,external_item_id,product_name,variant_name,quantity,unit_price,line_total,item_comment,sort_order) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach($preparedItems as $item)$insert->execute([$id,$item['external_item_id'],$item['product_name'],$item['variant_name'],$item['quantity'],$item['unit_price'],$item['line_total'],$item['item_comment'],$item['sort_order']]);
        $pdo->commit();return ['id'=>$id,'external_id'=>$externalId,'order_number'=>$orderNumber,'created'=>$new,'status'=>$status,'locked'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function online_orders_get_by_external_id(string $externalId): ?array
{
    $stmt=db()->prepare('SELECT id,external_id,order_number,status,total_amount,promised_at,created_at,updated_at FROM online_orders WHERE external_id=?');$stmt->execute([$externalId]);$row=$stmt->fetch();
    if(!$row)return null;$row['status_label']=online_orders_status_label((string)$row['status']);return $row;
}

function online_orders_secret_key(): string
{
    global $config;$secret=$config['security']['encryption_key']??(($config['db']['name']??'').'|'.($config['db']['user']??'').'|'.($config['db']['pass']??''));return hash('sha256',(string)$secret,true);
}
function online_orders_encrypt_secret(string $value): string
{
    if($value==='')return '';$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',online_orders_secret_key(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Не удалось зашифровать токен источника заказов.');return base64_encode($iv.$tag.$cipher);
}
function online_orders_decrypt_secret(string $value): string
{
    if($value==='')return '';$raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)throw new RuntimeException('Не удалось прочитать токен источника заказов.');$iv=substr($raw,0,12);$tag=substr($raw,12,16);$cipher=substr($raw,28);$plain=openssl_decrypt($cipher,'aes-256-gcm',online_orders_secret_key(),OPENSSL_RAW_DATA,$iv,$tag);if($plain===false)throw new RuntimeException('Не удалось расшифровать токен источника заказов.');return $plain;
}
function online_orders_pull_once(): array
{
    $url=trim((string)app_setting('online_orders_pull_url',''));
    if($url==='')return ['configured'=>false,'received'=>0,'created'=>0,'updated'=>0];
    if(!filter_var($url,FILTER_VALIDATE_URL)||!preg_match('#^https?://#i',$url))throw new RuntimeException('Некорректный URL источника онлайн-заказов.');
    $token=online_orders_decrypt_secret((string)app_setting('online_orders_pull_token',''));
    $headers=['Accept: application/json'];if($token!=='')$headers[]='Authorization: Bearer '.$token;
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>$headers]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$error!=='')throw new RuntimeException('Ошибка подключения к сайту онлайн-заказов: '.$error);
    if($status<200||$status>=300)throw new RuntimeException('Сайт онлайн-заказов вернул HTTP '.$status.'.');
    $decoded=json_decode((string)$body,true,64,JSON_THROW_ON_ERROR);$orders=is_array($decoded)&&array_key_exists('orders',$decoded)?$decoded['orders']:$decoded;
    if(!is_array($orders))throw new RuntimeException('Источник заказов вернул некорректный JSON. Ожидается массив заказов или объект с полем orders.');
    $received=0;$created=0;$updated=0;
    foreach($orders as $payload){if(!is_array($payload))continue;$received++;$result=online_orders_upsert_from_api($payload);if($result['created'])$created++;else $updated++;}
    set_system_meta('online_orders_last_pull_at',date('Y-m-d H:i:s'));set_system_meta('online_orders_last_pull_error','');
    return ['configured'=>true,'received'=>$received,'created'=>$created,'updated'=>$updated];
}