<?php
declare(strict_types=1);

require_once __DIR__.'/online_orders.php';
require_once __DIR__.'/customer_loyalty.php';
require_once __DIR__.'/customer_modifiers.php';
require_once __DIR__.'/customer_payments.php';

function customer_order_catalog(): array
{
    $rows=db()->query("SELECT id,name,category,sale_price FROM products WHERE active=1 AND sale_price>0 ORDER BY COALESCE(NULLIF(category,''),'Другое'),name")->fetchAll();
    return array_map(static function(array $row): array{return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'category'=>trim((string)($row['category']??''))?:'Другое','price'=>(float)$row['sale_price']];},$rows);
}
function customer_order_normalize_phone(string $phone): string
{
    $digits=preg_replace('/\D+/','',$phone)??'';if(strlen($digits)===11&&$digits[0]==='8')$digits='7'.substr($digits,1);if(strlen($digits)<10||strlen($digits)>15)throw new RuntimeException('Проверьте номер телефона.');return '+'.$digits;
}
function customer_order_account(string $phone,string $name): int
{
    $stmt=db()->prepare('SELECT id FROM customer_accounts WHERE phone=?');$stmt->execute([$phone]);$id=(int)($stmt->fetchColumn()?:0);if($id>0){if($name!=='')db()->prepare('UPDATE customer_accounts SET name=? WHERE id=?')->execute([mb_substr($name,0,160),$id]);return $id;}$stmt=db()->prepare('INSERT INTO customer_accounts(phone,name) VALUES(?,?)');$stmt->execute([$phone,$name!==''?mb_substr($name,0,160):null]);return (int)db()->lastInsertId();
}
function customer_order_modifier_option_ids(mixed $raw): array
{
    if(!is_array($raw))return [];$ids=[];foreach($raw as $value){$id=is_array($value)?(int)($value['option_id']??$value['id']??0):(int)$value;if($id>0)$ids[$id]=true;}return array_keys($ids);
}
function customer_order_attach_product_identity(int $orderId): void
{
    $stmt=db()->prepare("UPDATE online_order_items SET local_product_id=CAST(external_item_id AS UNSIGNED) WHERE order_id=? AND external_item_id REGEXP '^[0-9]+$'");$stmt->execute([$orderId]);
    $stmt=db()->prepare('UPDATE online_order_items oi SET oi.evotor_product_id=(SELECT ep.evotor_product_id FROM evotor_products ep WHERE ep.local_product_id=oi.local_product_id ORDER BY ep.id LIMIT 1) WHERE oi.order_id=? AND oi.local_product_id IS NOT NULL');$stmt->execute([$orderId]);
}
function customer_order_payment_method(array $data): array
{
    $enabled=customer_payment_enabled_methods();if(!$enabled)throw new RuntimeException('Сейчас нет доступных способов оплаты. Свяжитесь с кофейней.');
    $requested=trim((string)($data['payment_method']??''));
    if($requested===''){if(count($enabled)===1)return $enabled[0];throw new RuntimeException('Выберите способ оплаты.');}
    foreach($enabled as $method)if($method['id']===$requested)return $method;
    throw new RuntimeException('Выбранный способ оплаты сейчас недоступен.');
}
function customer_order_create(array $data): array
{
    $name=trim((string)($data['name']??''));$phone=customer_order_normalize_phone((string)($data['phone']??''));$comment=trim((string)($data['comment']??''));$fulfillment=(string)($data['fulfillment_type']??'pickup');if(!in_array($fulfillment,['pickup','delivery'],true))$fulfillment='pickup';if($fulfillment==='delivery')throw new RuntimeException('Доставка пока не запущена. Выберите самовывоз.');
    $paymentMethod=customer_order_payment_method($data);
    $clientOrderId=trim((string)($data['client_order_id']??''));if($clientOrderId!==''&&!preg_match('/^[A-Za-z0-9_-]{8,80}$/',$clientOrderId))throw new RuntimeException('Некорректный идентификатор оформления. Обновите страницу и попробуйте ещё раз.');if($clientOrderId==='')$clientOrderId=bin2hex(random_bytes(16));
    $rawItems=$data['items']??null;if(!is_array($rawItems)||!$rawItems)throw new RuntimeException('Корзина пустая.');
    $lines=[];$baseIds=[];$baseUnits=0;
    foreach($rawItems as $row){if(!is_array($row))continue;$id=(int)($row['product_id']??$row['id']??0);$qty=max(0,min(20,(int)($row['quantity']??0)));if($id<=0||$qty<=0)continue;$baseUnits+=$qty;$baseIds[$id]=true;$lines[]=['product_id'=>$id,'quantity'=>$qty,'modifier_option_ids'=>customer_order_modifier_option_ids($row['modifiers']??[])];}
    if(!$lines)throw new RuntimeException('В корзине нет корректных позиций.');if($baseUnits>50)throw new RuntimeException('В одном заказе можно оформить не больше 50 основных позиций.');
    $ids=array_keys($baseIds);$placeholders=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT p.id,p.name,p.sale_price FROM products p LEFT JOIN customer_product_settings cps ON cps.product_id=p.id LEFT JOIN customer_categories pc ON pc.id=cps.category_id LEFT JOIN customer_product_group_variants cgv ON cgv.product_id=p.id LEFT JOIN customer_product_groups cg ON cg.id=cgv.group_id LEFT JOIN customer_categories gc ON gc.id=cg.category_id WHERE p.active=1 AND p.sale_price>0 AND COALESCE(cps.visible,1)=1 AND (cps.category_id IS NULL OR COALESCE(pc.active,0)=1) AND (cgv.group_id IS NULL OR COALESCE(cg.visible,0)=1) AND (cg.category_id IS NULL OR COALESCE(gc.active,0)=1) AND p.id IN ({$placeholders})");$stmt->execute($ids);$products=[];foreach($stmt->fetchAll() as $p)$products[(int)$p['id']]=$p;if(count($products)!==count($ids))throw new RuntimeException('Одна из позиций больше недоступна. Обновите меню.');
    $items=[];$total=0.0;$allUnits=0;
    foreach($lines as $line){$id=$line['product_id'];$qty=$line['quantity'];$p=$products[$id];$modifiers=customer_modifier_validate_selection($id,$line['modifier_option_ids']);$allUnits+=$qty*(1+count($modifiers));if($allUnits>100)throw new RuntimeException('Слишком много позиций и добавок в одном заказе.');
        $price=(float)$p['sale_price'];$lineTotal=$price*$qty;$total+=$lineTotal;
        $items[]=['external_id'=>(string)$id,'name'=>(string)$p['name'],'quantity'=>$qty,'unit_price'=>$price,'line_total'=>$lineTotal,'comment'=>null];
        foreach($modifiers as $m){$mPrice=(float)$m['price'];$mTotal=$mPrice*$qty;$total+=$mTotal;$items[]=['external_id'=>(string)$m['product_id'],'name'=>(string)$m['product_name'],'variant'=>(string)$m['label'],'quantity'=>$qty,'unit_price'=>$mPrice,'line_total'=>$mTotal,'comment'=>null];}
    }
    $customerId=customer_order_account($phone,$name);$publicId='customer-web-'.$clientOrderId;$orderNumber='W'.date('Hi').'-'.strtoupper(substr(hash('sha256',$clientOrderId),0,2));
    $initialPayment=$paymentMethod['id']==='sbp'?'pending':'unpaid';
    $payload=['external_id'=>$publicId,'order_number'=>$orderNumber,'source'=>'customer-web','customer'=>['name'=>$name!==''?$name:'Гость','phone'=>$phone],'fulfillment'=>['type'=>$fulfillment,'label'=>(string)app_setting('customer_pickup_label','Самовывоз')],'payment_status'=>$initialPayment,'total_amount'=>round($total,2),'comment'=>$comment!==''?mb_substr($comment,0,1000):null,'created_at'=>date('c'),'items'=>$items];
    $result=online_orders_upsert_from_api($payload);$orderId=(int)$result['id'];customer_order_attach_product_identity($orderId);
    $findAccess=db()->prepare('SELECT tracking_token,customer_id FROM customer_order_access WHERE order_id=?');$findAccess->execute([$orderId]);$existingAccess=$findAccess->fetch();if($existingAccess){$token=(string)$existingAccess['tracking_token'];$customerId=(int)($existingAccess['customer_id']?:$customerId);}else{$token=bin2hex(random_bytes(32));db()->prepare('INSERT INTO customer_order_access(order_id,customer_id,tracking_token) VALUES(?,?,?)')->execute([$orderId,$customerId,$token]);}
    $payment=null;
    try{
        if($paymentMethod['id']==='sbp')$payment=customer_payment_create_sbp($orderId,$orderNumber,round($total,2),$phone);else customer_payment_mark_cash($orderId);
    }catch(Throwable $e){
        if($paymentMethod['id']==='sbp')db()->prepare("UPDATE online_orders SET status='cancelled',cancelled_at=NOW(),payment_status='failed',payment_method='sbp',payment_provider='sber_sbp' WHERE id=? AND status IN ('new','awaiting_payment')")->execute([$orderId]);
        throw $e;
    }
    $status=$paymentMethod['id']==='sbp'?'awaiting_payment':(string)$result['status'];
    return ['order_id'=>$orderId,'order_number'=>$orderNumber,'tracking_token'=>$token,'total_amount'=>round($total,2),'status'=>$status,'status_label'=>$status==='awaiting_payment'?'Ожидает оплаты':online_orders_status_label($status),'payment_method'=>$paymentMethod['id'],'payment_label'=>$paymentMethod['label'],'payment_status'=>$paymentMethod['id']==='sbp'?(string)($payment['status']??'pending'):'unpaid','payment_url'=>$payment['payment_url']??null,'sbp_payload'=>$payment['sbp_payload']??null,'loyalty_balance'=>customer_loyalty_balance($customerId),'loyalty_expected'=>customer_loyalty_preview($total),'loyalty_percent'=>customer_loyalty_rate()];
}
function customer_order_public_status(string $token): ?array
{
    if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;$stmt=db()->prepare("SELECT o.id,o.order_number,o.status,o.payment_status,o.payment_method,o.total_amount,o.fulfillment_type,o.fulfillment_label,o.external_created_at,o.created_at,o.updated_at,a.customer_id,a.loyalty_earned_at FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.tracking_token=? LIMIT 1");$stmt->execute([$token]);$order=$stmt->fetch();if(!$order)return null;
    if((string)$order['payment_method']==='sbp'&&(string)$order['payment_status']==='pending'){$pay=customer_payment_status_for_order((int)$order['id']);if($pay&&!empty($pay['provider_order_id'])){try{customer_payment_sber_sync_by_provider_id((string)$pay['provider_order_id']);$stmt->execute([$token]);$order=$stmt->fetch()?:$order;}catch(Throwable $e){}}}
    $items=db()->prepare('SELECT product_name,variant_name,quantity,unit_price,line_total,item_comment FROM online_order_items WHERE order_id=? ORDER BY sort_order,id');$items->execute([(int)$order['id']]);$customerId=(int)($order['customer_id']??0);$status=(string)$order['status'];
    return ['order_number'=>(string)$order['order_number'],'status'=>$status,'status_label'=>$status==='awaiting_payment'?'Ожидает оплаты':online_orders_status_label($status),'payment_status'=>(string)($order['payment_status']??''),'payment_method'=>(string)($order['payment_method']??''),'total_amount'=>(float)$order['total_amount'],'fulfillment_label'=>online_orders_fulfillment_label($order),'created_at'=>(string)($order['external_created_at']?:$order['created_at']),'updated_at'=>(string)$order['updated_at'],'items'=>$items->fetchAll(),'loyalty_balance'=>customer_loyalty_balance($customerId),'loyalty_expected'=>customer_loyalty_preview((float)$order['total_amount']),'loyalty_earned'=>(bool)$order['loyalty_earned_at'],'loyalty_percent'=>customer_loyalty_rate()];
}
