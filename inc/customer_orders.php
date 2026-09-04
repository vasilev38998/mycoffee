<?php
declare(strict_types=1);

require_once __DIR__.'/online_orders.php';
require_once __DIR__.'/customer_loyalty.php';

function customer_order_catalog(): array
{
    $rows=db()->query("SELECT id,name,category,sale_price FROM products WHERE active=1 AND sale_price>0 ORDER BY COALESCE(NULLIF(category,''),'Другое'),name")->fetchAll();
    return array_map(static function(array $row): array{
        return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'category'=>trim((string)($row['category']??''))?:'Другое','price'=>(float)$row['sale_price']];
    },$rows);
}

function customer_order_normalize_phone(string $phone): string
{
    $digits=preg_replace('/\D+/','',$phone)??'';
    if(strlen($digits)===11&&$digits[0]==='8')$digits='7'.substr($digits,1);
    if(strlen($digits)<10||strlen($digits)>15)throw new RuntimeException('Проверьте номер телефона.');
    return '+'.$digits;
}

function customer_order_account(string $phone,string $name): int
{
    $stmt=db()->prepare('SELECT id FROM customer_accounts WHERE phone=?');$stmt->execute([$phone]);$id=(int)($stmt->fetchColumn()?:0);
    if($id>0){if($name!=='')db()->prepare('UPDATE customer_accounts SET name=? WHERE id=?')->execute([mb_substr($name,0,160),$id]);return $id;}
    $stmt=db()->prepare('INSERT INTO customer_accounts(phone,name) VALUES(?,?)');$stmt->execute([$phone,$name!==''?mb_substr($name,0,160):null]);
    return (int)db()->lastInsertId();
}

function customer_order_create(array $data): array
{
    $name=trim((string)($data['name']??''));
    $phone=customer_order_normalize_phone((string)($data['phone']??''));
    $comment=trim((string)($data['comment']??''));
    $fulfillment=(string)($data['fulfillment_type']??'pickup');
    if(!in_array($fulfillment,['pickup','delivery'],true))$fulfillment='pickup';
    if($fulfillment==='delivery')throw new RuntimeException('Доставка пока не запущена. Выберите самовывоз.');
    $clientOrderId=trim((string)($data['client_order_id']??''));
    if($clientOrderId!==''&&!preg_match('/^[A-Za-z0-9_-]{8,80}$/',$clientOrderId))throw new RuntimeException('Некорректный идентификатор оформления. Обновите страницу и попробуйте ещё раз.');
    if($clientOrderId==='')$clientOrderId=bin2hex(random_bytes(16));
    $rawItems=$data['items']??null;
    if(!is_array($rawItems)||!$rawItems)throw new RuntimeException('Корзина пустая.');

    $qtyById=[];
    foreach($rawItems as $item){
        if(!is_array($item))continue;
        $id=(int)($item['product_id']??$item['id']??0);$qty=(int)($item['quantity']??0);
        if($id<=0||$qty<=0)continue;
        $qtyById[$id]=min(20,($qtyById[$id]??0)+$qty);
    }
    if(!$qtyById)throw new RuntimeException('В корзине нет корректных позиций.');
    if(array_sum($qtyById)>50)throw new RuntimeException('В одном заказе можно оформить не больше 50 позиций.');

    $ids=array_keys($qtyById);$placeholders=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT id,name,sale_price FROM products WHERE active=1 AND sale_price>0 AND id IN ({$placeholders})");$stmt->execute($ids);
    $products=[];foreach($stmt->fetchAll() as $p)$products[(int)$p['id']]=$p;
    if(count($products)!==count($ids))throw new RuntimeException('Одна из позиций больше недоступна. Обновите каталог.');

    $items=[];$total=0.0;
    foreach($qtyById as $id=>$qty){$p=$products[$id];$price=(float)$p['sale_price'];$line=$price*$qty;$total+=$line;$items[]=['external_id'=>(string)$id,'name'=>(string)$p['name'],'quantity'=>$qty,'unit_price'=>$price,'line_total'=>$line];}

    $customerId=customer_order_account($phone,$name);
    $publicId='customer-web-'.$clientOrderId;
    $orderNumber='W'.date('Hi').'-'.strtoupper(substr(hash('sha256',$clientOrderId),0,2));
    $payload=[
        'external_id'=>$publicId,'order_number'=>$orderNumber,'source'=>'customer-web',
        'customer'=>['name'=>$name!==''?$name:'Гость','phone'=>$phone],
        'fulfillment'=>['type'=>$fulfillment,'label'=>'Самовывоз'],
        'payment_status'=>'unpaid','total_amount'=>round($total,2),
        'comment'=>$comment!==''?mb_substr($comment,0,1000):null,'created_at'=>date('c'),'items'=>$items,
    ];
    $result=online_orders_upsert_from_api($payload);
    $orderId=(int)$result['id'];
    $findAccess=db()->prepare('SELECT tracking_token,customer_id FROM customer_order_access WHERE order_id=?');$findAccess->execute([$orderId]);$existingAccess=$findAccess->fetch();
    if($existingAccess){
        $token=(string)$existingAccess['tracking_token'];
        $customerId=(int)($existingAccess['customer_id']?:$customerId);
    }else{
        $token=bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO customer_order_access(order_id,customer_id,tracking_token) VALUES(?,?,?)')->execute([$orderId,$customerId,$token]);
    }
    return [
        'order_id'=>$orderId,'order_number'=>$orderNumber,'tracking_token'=>$token,'total_amount'=>round($total,2),
        'status'=>(string)$result['status'],'status_label'=>online_orders_status_label((string)$result['status']),
        'loyalty_balance'=>customer_loyalty_balance($customerId),'loyalty_expected'=>customer_loyalty_preview($total),'loyalty_percent'=>customer_loyalty_rate(),
    ];
}

function customer_order_public_status(string $token): ?array
{
    if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;
    $stmt=db()->prepare("SELECT o.id,o.order_number,o.status,o.total_amount,o.fulfillment_type,o.fulfillment_label,o.external_created_at,o.created_at,o.updated_at,a.customer_id,a.loyalty_earned_at
        FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.tracking_token=? LIMIT 1");
    $stmt->execute([$token]);$order=$stmt->fetch();if(!$order)return null;
    $items=db()->prepare('SELECT product_name,variant_name,quantity,unit_price,line_total,item_comment FROM online_order_items WHERE order_id=? ORDER BY sort_order,id');$items->execute([(int)$order['id']]);
    $customerId=(int)($order['customer_id']??0);
    return [
        'order_number'=>(string)$order['order_number'],'status'=>(string)$order['status'],'status_label'=>online_orders_status_label((string)$order['status']),
        'total_amount'=>(float)$order['total_amount'],'fulfillment_label'=>online_orders_fulfillment_label($order),
        'created_at'=>(string)($order['external_created_at']?:$order['created_at']),'updated_at'=>(string)$order['updated_at'],'items'=>$items->fetchAll(),
        'loyalty_balance'=>customer_loyalty_balance($customerId),'loyalty_expected'=>customer_loyalty_preview((float)$order['total_amount']),
        'loyalty_earned'=>(bool)$order['loyalty_earned_at'],'loyalty_percent'=>customer_loyalty_rate(),
    ];
}
