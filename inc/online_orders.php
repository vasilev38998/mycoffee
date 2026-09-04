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

function online_orders_status_label(string $status): string
{
    return online_orders_status_labels()[$status]??$status;
}

function online_orders_allowed_statuses(): array
{
    return array_keys(online_orders_status_labels());
}

function online_orders_transition(int $id,string $status): void
{
    if($id<=0||!in_array($status,online_orders_allowed_statuses(),true))throw new RuntimeException('Некорректный статус заказа.');
    $stmt=db()->prepare('SELECT id,status FROM online_orders WHERE id=?');$stmt->execute([$id]);$order=$stmt->fetch();
    if(!$order)throw new RuntimeException('Заказ не найден.');
    $allowed=[
        'new'=>['preparing','ready','cancelled'],
        'preparing'=>['ready','cancelled'],
        'ready'=>['completed','preparing','cancelled'],
        'completed'=>[],
        'cancelled'=>[],
    ];
    $from=(string)$order['status'];
    if($from===$status)return;
    if(!in_array($status,$allowed[$from]??[],true))throw new RuntimeException('Нельзя перевести заказ из «'.online_orders_status_label($from).'» в «'.online_orders_status_label($status).'».');
    $timeColumn=['preparing'=>'preparing_at','ready'=>'ready_at','completed'=>'completed_at','cancelled'=>'cancelled_at'][$status]??null;
    $sql='UPDATE online_orders SET status=?'.($timeColumn?', '.$timeColumn.'=NOW()':'').' WHERE id=?';
    $u=db()->prepare($sql);$u->execute([$status,$id]);
    audit_write('online_order_status','Статус онлайн-заказа: '.online_orders_status_label($from).' → '.online_orders_status_label($status),'online_order',(string)$id);
}

function online_orders_fetch(string $filter='active',int $limit=80): array
{
    $limit=max(1,min(200,$limit));
    $where=$filter==='all'?'1=1':($filter==='done'?"o.status IN ('completed','cancelled')":"o.status IN ('new','preparing','ready')");
    $sql="SELECT o.* FROM online_orders o WHERE {$where} ORDER BY
        CASE o.status WHEN 'new' THEN 1 WHEN 'preparing' THEN 2 WHEN 'ready' THEN 3 ELSE 4 END,
        COALESCE(o.promised_at,o.external_created_at,o.created_at),o.id LIMIT {$limit}";
    $orders=db()->query($sql)->fetchAll();
    if(!$orders)return [];
    $ids=array_map(static fn($o)=>(int)$o['id'],$orders);
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT * FROM online_order_items WHERE order_id IN ({$placeholders}) ORDER BY order_id,sort_order,id");$stmt->execute($ids);
    $items=[];foreach($stmt->fetchAll() as $item)$items[(int)$item['order_id']][]=$item;
    foreach($orders as &$order){
        $order['items']=$items[(int)$order['id']]??[];
        $order['status_label']=online_orders_status_label((string)$order['status']);
    }
    unset($order);
    return $orders;
}

function online_orders_create_test(): int
{
    $external='test-'.date('Ymd-His').'-'.bin2hex(random_bytes(2));
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO online_orders(external_id,order_number,source,status,customer_name,fulfillment_type,fulfillment_label,payment_status,total_amount,customer_comment,promised_at,external_created_at) VALUES(?,?,?,'new',?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NOW())");
        $stmt->execute([$external,'T'.date('Hi'),'test','Тестовый гость','pickup','Самовывоз','paid',490,'Без крышки на одном напитке']);
        $id=(int)$pdo->lastInsertId();
        $item=$pdo->prepare('INSERT INTO online_order_items(order_id,product_name,variant_name,quantity,unit_price,line_total,item_comment,sort_order) VALUES(?,?,?,?,?,?,?,?)');
        $item->execute([$id,'Капучино','350 мл',1,250,250,null,1]);
        $item->execute([$id,'Латте','450 мл',1,240,240,'Без сахара',2]);
        $pdo->commit();
        audit_write('online_order_test_created','Создан тестовый онлайн-заказ','online_order',(string)$id);
        return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function online_orders_api_authorized(): bool
{
    $expected=online_orders_api_token();
    $provided=trim((string)($_SERVER['HTTP_X_KAPOUCH_TOKEN']??''));
    if($provided===''){
        $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
        if(preg_match('/^Bearer\s+(.+)$/i',$auth,$m))$provided=trim($m[1]);
    }
    return $provided!==''&&hash_equals($expected,$provided);
}

function online_orders_api_payload(): array
{
    $raw=file_get_contents('php://input');
    if($raw===false||trim($raw)==='')throw new RuntimeException('Пустое тело запроса.');
    $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('JSON должен быть объектом.');
    return $data;
}

function online_orders_parse_datetime(mixed $value): ?string
{
    if($value===null||trim((string)$value)==='')return null;
    try{$d=new DateTime((string)$value);return $d->format('Y-m-d H:i:s');}catch(Throwable $e){throw new RuntimeException('Некорректная дата/время: '.(string)$value);}
}

function online_orders_upsert_from_api(array $data): array
{
    $externalId=trim((string)($data['external_id']??''));
    $orderNumber=trim((string)($data['order_number']??$externalId));
    if($externalId===''||mb_strlen($externalId)>190)throw new RuntimeException('Поле external_id обязательно и должно быть короче 191 символа.');
    if($orderNumber==='')$orderNumber=$externalId;
    $source=trim((string)($data['source']??'online'))?:'online';
    $customer=is_array($data['customer']??null)?$data['customer']:[];
    $fulfillment=is_array($data['fulfillment']??null)?$data['fulfillment']:[];
    $type=(string)($fulfillment['type']??$data['fulfillment_type']??'pickup');
    if(!in_array($type,['pickup','delivery','other'],true))$type='other';
    $items=$data['items']??null;
    if(!is_array($items)||!$items)throw new RuntimeException('Добавьте хотя бы одну позицию в items.');
    $total=(float)($data['total_amount']??0);
    if($total<0)throw new RuntimeException('total_amount не может быть отрицательным.');
    $preparedItems=[];$calculated=0.0;$sort=0;
    foreach($items as $row){
        if(!is_array($row))throw new RuntimeException('Каждая позиция items должна быть объектом.');
        $name=trim((string)($row['name']??$row['product_name']??''));
        $qty=(float)($row['quantity']??1);$price=(float)($row['unit_price']??0);
        if($name===''||$qty<=0||$price<0)throw new RuntimeException('У каждой позиции нужны name, quantity > 0 и unit_price >= 0.');
        $line=array_key_exists('line_total',$row)?(float)$row['line_total']:$qty*$price;
        if($line<0)throw new RuntimeException('line_total не может быть отрицательным.');
        $calculated+=$line;$sort++;
        $preparedItems[]=[
            'external_item_id'=>trim((string)($row['external_id']??$row['id']??''))?:null,
            'product_name'=>$name,
            'variant_name'=>trim((string)($row['variant']??$row['variant_name']??''))?:null,
            'quantity'=>$qty,'unit_price'=>$price,'line_total'=>$line,
            'item_comment'=>trim((string)($row['comment']??''))?:null,'sort_order'=>$sort,
        ];
    }
    if(!array_key_exists('total_amount',$data))$total=$calculated;
    $pdo=db();$pdo->beginTransaction();
    try{
        $find=$pdo->prepare('SELECT id,status FROM online_orders WHERE external_id=? FOR UPDATE');$find->execute([$externalId]);$existing=$find->fetch();
        $new=!$existing;
        if($new){
            $stmt=$pdo->prepare("INSERT INTO online_orders(external_id,order_number,source,status,customer_name,customer_phone,fulfillment_type,fulfillment_label,payment_status,total_amount,customer_comment,promised_at,external_created_at) VALUES(?,?,?,'new',?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$externalId,$orderNumber,mb_substr($source,0,80),mb_substr(trim((string)($customer['name']??$data['customer_name']??'')),0,160)?:null,mb_substr(trim((string)($customer['phone']??$data['customer_phone']??'')),0,80)?:null,$type,mb_substr(trim((string)($fulfillment['label']??$fulfillment['address']??$data['fulfillment_label']??'')),0,160)?:null,mb_substr(trim((string)($data['payment_status']??'')),0,40)?:null,$total,trim((string)($data['comment']??$data['customer_comment']??''))?:null,online_orders_parse_datetime($data['promised_at']??null),online_orders_parse_datetime($data['created_at']??null)]);
            $id=(int)$pdo->lastInsertId();
        }else{
            $id=(int)$existing['id'];
            $stmt=$pdo->prepare('UPDATE online_orders SET order_number=?,source=?,customer_name=?,customer_phone=?,fulfillment_type=?,fulfillment_label=?,payment_status=?,total_amount=?,customer_comment=?,promised_at=?,external_created_at=COALESCE(?,external_created_at) WHERE id=?');
            $stmt->execute([$orderNumber,mb_substr($source,0,80),mb_substr(trim((string)($customer['name']??$data['customer_name']??'')),0,160)?:null,mb_substr(trim((string)($customer['phone']??$data['customer_phone']??'')),0,80)?:null,$type,mb_substr(trim((string)($fulfillment['label']??$fulfillment['address']??$data['fulfillment_label']??'')),0,160)?:null,mb_substr(trim((string)($data['payment_status']??'')),0,40)?:null,$total,trim((string)($data['comment']??$data['customer_comment']??''))?:null,online_orders_parse_datetime($data['promised_at']??null),online_orders_parse_datetime($data['created_at']??null),$id]);
            $pdo->prepare('DELETE FROM online_order_items WHERE order_id=?')->execute([$id]);
        }
        $insert=$pdo->prepare('INSERT INTO online_order_items(order_id,external_item_id,product_name,variant_name,quantity,unit_price,line_total,item_comment,sort_order) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach($preparedItems as $item)$insert->execute([$id,$item['external_item_id'],$item['product_name'],$item['variant_name'],$item['quantity'],$item['unit_price'],$item['line_total'],$item['item_comment'],$item['sort_order']]);
        $pdo->commit();
        return ['id'=>$id,'external_id'=>$externalId,'order_number'=>$orderNumber,'created'=>$new,'status'=>$existing['status']??'new'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function online_orders_get_by_external_id(string $externalId): ?array
{
    $stmt=db()->prepare('SELECT id,external_id,order_number,status,total_amount,promised_at,created_at,updated_at FROM online_orders WHERE external_id=?');$stmt->execute([$externalId]);$row=$stmt->fetch();
    if(!$row)return null;
    $row['status_label']=online_orders_status_label((string)$row['status']);
    return $row;
}
