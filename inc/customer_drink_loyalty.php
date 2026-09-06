<?php
declare(strict_types=1);

require_once __DIR__.'/customer_modifiers.php';

function customer_drink_loyalty_settings(): array
{
    $required=max(1,min(20,(int)app_setting('customer_sixth_drink_paid_count','5')));
    $mode=(string)app_setting('customer_sixth_drink_products_mode','auto');if(!in_array($mode,['auto','selected'],true))$mode='auto';
    $ids=[];foreach(preg_split('/\s*,\s*/',trim((string)app_setting('customer_sixth_drink_product_ids','')),-1,PREG_SPLIT_NO_EMPTY)?:[] as $raw){$id=(int)$raw;if($id>0)$ids[$id]=true;}
    $started=trim((string)app_setting('customer_sixth_drink_started_at',''));
    if($started===''||strtotime($started)===false)$started='1970-01-01 00:00:00';
    return [
        'enabled'=>(string)app_setting('customer_sixth_drink_enabled','1')==='1',
        'required_paid'=>$required,
        'products_mode'=>$mode,
        'product_ids'=>array_keys($ids),
        'reference_product_id'=>max(0,(int)app_setting('customer_sixth_drink_reference_product_id','0')),
        'started_at'=>$started,
    ];
}

function customer_drink_loyalty_product_rows(bool $activeOnly=true): array
{
    $where=$activeOnly?'WHERE p.active=1 AND p.sale_price>0':'';
    return db()->query("SELECT p.id,p.name,p.category,p.sale_price,p.active,
        (SELECT c.slug FROM customer_product_settings cps JOIN customer_categories c ON c.id=cps.category_id WHERE cps.product_id=p.id LIMIT 1) direct_slug,
        (SELECT c.name FROM customer_product_settings cps JOIN customer_categories c ON c.id=cps.category_id WHERE cps.product_id=p.id LIMIT 1) direct_category,
        (SELECT gc.slug FROM customer_product_group_variants gv JOIN customer_product_groups g ON g.id=gv.group_id LEFT JOIN customer_categories gc ON gc.id=g.category_id WHERE gv.product_id=p.id LIMIT 1) group_slug,
        (SELECT g.name FROM customer_product_group_variants gv JOIN customer_product_groups g ON g.id=gv.group_id WHERE gv.product_id=p.id LIMIT 1) group_name,
        (SELECT gv.variant_label FROM customer_product_group_variants gv WHERE gv.product_id=p.id LIMIT 1) variant_label
        FROM products p {$where} ORDER BY p.category,p.name,p.sale_price,p.id")->fetchAll();
}

function customer_drink_loyalty_auto_eligible(array $row): bool
{
    $slug=mb_strtolower(trim((string)($row['group_slug']?:$row['direct_slug']??'')));
    if(in_array($slug,['coffee','tea','lemonades','milkshakes','drinks','beverages'],true))return true;
    $name=mb_strtolower(trim(implode(' ',[(string)($row['name']??''),(string)($row['group_name']??''),(string)($row['category']??''),(string)($row['direct_category']??'')])));
    foreach(['коф','капуч','латт','раф','эспресс','американ','флэт','фильтр','чай','матча','какао','шоколад','лимонад','тоник','бамбл','коктейл','милкшейк','фраппе','айс'] as $needle)if(str_contains($name,$needle))return true;
    return false;
}

function customer_drink_loyalty_product_map(): array
{
    $settings=customer_drink_loyalty_settings();$selected=array_fill_keys($settings['product_ids'],true);$map=[];
    foreach(customer_drink_loyalty_product_rows(true) as $row){$id=(int)$row['id'];$map[$id]=$settings['products_mode']==='selected'?isset($selected[$id]):customer_drink_loyalty_auto_eligible($row);}
    return $map;
}

function customer_drink_loyalty_is_eligible_product(int $productId): bool
{
    if($productId<=0)return false;$map=customer_drink_loyalty_product_map();return !empty($map[$productId]);
}

function customer_drink_loyalty_reference_product(): ?array
{
    $settings=customer_drink_loyalty_settings();$rows=customer_drink_loyalty_product_rows(true);
    if($settings['reference_product_id']>0){foreach($rows as $row)if((int)$row['id']===$settings['reference_product_id'])return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'variant'=>(string)($row['variant_label']??''),'price'=>round((float)$row['sale_price'],2),'auto'=>false];}
    $best=null;$bestScore=-1;
    foreach($rows as $row){
        $name=mb_strtolower((string)$row['name'].' '.(string)($row['group_name']??''));if(!str_contains($name,'капуч'))continue;
        $variant=mb_strtolower(trim((string)($row['variant_label']??'')));$hay=$name.' '.$variant;$score=100;
        if(preg_match('/(^|[^0-9])(0[\.,]2|200)(\s*(мл|ml))?([^0-9]|$)/u',$hay))$score+=60;
        elseif(preg_match('/(^|[^0-9])(0[\.,]25|250)(\s*(мл|ml))?([^0-9]|$)/u',$hay))$score+=20;
        $price=(float)$row['sale_price'];if($price<=0)continue;
        if($best===null||$score>$bestScore||($score===$bestScore&&$price<(float)$best['price'])){$best=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'variant'=>(string)($row['variant_label']??''),'price'=>round($price,2),'auto'=>true];$bestScore=$score;}
    }
    return $best;
}

function customer_drink_loyalty_summary(int $customerId,?PDO $pdo=null): array
{
    $settings=customer_drink_loyalty_settings();$reference=customer_drink_loyalty_reference_product();
    $base=['enabled'=>$settings['enabled'],'required_paid'=>$settings['required_paid'],'progress'=>0,'paid_stamps'=>0,'earned_rewards'=>0,'available_rewards'=>0,'redeemed_rewards'=>0,'next_in'=>$settings['required_paid'],'gift_cap'=>(float)($reference['price']??0),'reference_product'=>$reference,'started_at'=>$settings['started_at']];
    if($customerId<=0)return $base;$pdo=$pdo??db();
    $stmt=$pdo->prepare('SELECT COALESCE(SUM(stamp_delta),0) stamps,COALESCE(SUM(reward_delta),0) reward_adjustment,COALESCE(SUM(CASE WHEN reward_delta<0 THEN -reward_delta ELSE 0 END),0) redeemed FROM customer_drink_loyalty_ledger WHERE customer_id=?');$stmt->execute([$customerId]);$row=$stmt->fetch()?:[];
    $stamps=max(0,(int)($row['stamps']??0));$earned=intdiv($stamps,$settings['required_paid']);$adjust=(int)($row['reward_adjustment']??0);$available=max(0,$earned+$adjust);$progress=$stamps%$settings['required_paid'];
    $base['paid_stamps']=$stamps;$base['earned_rewards']=$earned;$base['available_rewards']=$available;$base['redeemed_rewards']=(int)($row['redeemed']??0);$base['progress']=$progress;$base['next_in']=$available>0?0:$settings['required_paid']-$progress;
    return $base;
}

function customer_drink_loyalty_quote_cart(int $customerId,array $rawItems): array
{
    $summary=customer_drink_loyalty_summary($customerId);$subtotal=0.0;$candidate=null;
    if(!$rawItems)return ['subtotal'=>0.0,'discount'=>0.0,'total'=>0.0,'reward'=>$summary,'gift'=>null];
    $eligible=customer_drink_loyalty_product_map();$ids=[];$lines=[];
    foreach($rawItems as $row){
        if(!is_array($row))continue;$id=(int)($row['product_id']??$row['id']??0);$qty=max(0,min(20,(int)($row['quantity']??0)));if($id<=0||$qty<=0)continue;
        $modifierIds=[];foreach((array)($row['modifiers']??[]) as $m){$optionId=is_array($m)?(int)($m['option_id']??$m['id']??0):(int)$m;if($optionId>0)$modifierIds[$optionId]=true;}
        $ids[$id]=true;$lines[]=['id'=>$id,'qty'=>$qty,'modifiers'=>array_keys($modifierIds)];
    }
    if(!$lines)return ['subtotal'=>0.0,'discount'=>0.0,'total'=>0.0,'reward'=>$summary,'gift'=>null];
    $productIds=array_keys($ids);$ph=implode(',',array_fill(0,count($productIds),'?'));$stmt=db()->prepare("SELECT id,name,sale_price FROM products WHERE active=1 AND sale_price>0 AND id IN ({$ph})");$stmt->execute($productIds);$products=[];foreach($stmt->fetchAll() as $p)$products[(int)$p['id']]=$p;
    foreach($lines as $line){
        if(!isset($products[$line['id']]))continue;$product=$products[$line['id']];$base=round((float)$product['sale_price'],2);$mods=customer_modifier_validate_selection($line['id'],$line['modifiers']);$modifierTotal=0.0;foreach($mods as $m)$modifierTotal+=round((float)$m['price'],2);
        $subtotal+=($base+$modifierTotal)*$line['qty'];
        if($summary['enabled']&&$summary['available_rewards']>0&&$summary['gift_cap']>0&&!empty($eligible[$line['id']])){
            $discount=round(min($base,(float)$summary['gift_cap']),2);
            if($discount>0&&($candidate===null||$discount>(float)$candidate['discount']))$candidate=['product_id'=>$line['id'],'product_name'=>(string)$product['name'],'product_price'=>$base,'discount'=>$discount,'customer_due'=>round(max(0,$base-$discount),2),'gift_cap'=>(float)$summary['gift_cap']];
        }
    }
    $subtotal=round($subtotal,2);$discount=round((float)($candidate['discount']??0),2);return ['subtotal'=>$subtotal,'discount'=>$discount,'total'=>round(max(0,$subtotal-$discount),2),'reward'=>$summary,'gift'=>$candidate];
}

function customer_drink_loyalty_online_reward(int $orderId,?PDO $pdo=null): ?array
{
    if($orderId<=0)return null;$pdo=$pdo??db();$stmt=$pdo->prepare("SELECT id,customer_id,source_line_id,product_id,reward_value,note FROM customer_drink_loyalty_ledger WHERE operation_key=? AND reward_delta=-1 LIMIT 1");$stmt->execute(['redeem:online_order:'.$orderId]);$row=$stmt->fetch();return $row?:null;
}

function customer_drink_loyalty_apply_online_order_reward(int $orderId,int $customerId): array
{
    if($orderId<=0||$customerId<=0)return ['applied'=>false,'discount'=>0.0];$pdo=db();$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM customer_accounts WHERE id=? FOR UPDATE');$lock->execute([$customerId]);if(!$lock->fetchColumn())throw new RuntimeException('Клиент не найден.');
        $existing=customer_drink_loyalty_online_reward($orderId,$pdo);if($existing){$pdo->commit();return ['applied'=>true,'discount'=>(float)$existing['reward_value'],'product_id'=>(int)$existing['product_id'],'line_id'=>(int)$existing['source_line_id'],'restored'=>false];}
        $summary=customer_drink_loyalty_summary($customerId,$pdo);if(!$summary['enabled']||$summary['available_rewards']<=0||$summary['gift_cap']<=0){$pdo->commit();return ['applied'=>false,'discount'=>0.0];}
        $eligible=customer_drink_loyalty_product_map();$items=$pdo->prepare("SELECT id,local_product_id,product_name,quantity,unit_price,line_total,item_comment FROM online_order_items WHERE order_id=? AND (variant_name IS NULL OR variant_name='') ORDER BY id");$items->execute([$orderId]);$best=null;
        foreach($items->fetchAll() as $item){$productId=(int)($item['local_product_id']??0);$qty=(float)$item['quantity'];$unit=round((float)$item['unit_price'],2);if($productId<=0||$qty<=0||$unit<=0||empty($eligible[$productId]))continue;$wanted=round(min($unit,(float)$summary['gift_cap']),2);if($wanted<=0)continue;if($best===null||$wanted>(float)$best['wanted'])$best=['item'=>$item,'product_id'=>$productId,'wanted'=>$wanted];}
        if($best===null){$pdo->commit();return ['applied'=>false,'discount'=>0.0];}
        $item=$best['item'];$qty=(float)$item['quantity'];$oldLine=round((float)$item['line_total'],2);$wanted=(float)$best['wanted'];$due=max(0,$oldLine-$wanted);$newUnit=$qty>0?ceil(($due/$qty)*100-0.000001)/100:0.0;$newLine=round($newUnit*$qty,2);$discount=round(max(0,$oldLine-$newLine),2);if($discount<=0){$pdo->commit();return ['applied'=>false,'discount'=>0.0];}
        $note='Подарок «6-й напиток»: скидка '.number_format($discount,2,'.','').' ₽';$existingComment=trim((string)($item['item_comment']??''));$comment=mb_substr($existingComment!==''?$existingComment.' · '.$note:$note,0,500);
        $upd=$pdo->prepare('UPDATE online_order_items SET unit_price=?,line_total=?,item_comment=? WHERE id=? AND order_id=?');$upd->execute([$newUnit,$newLine,$comment,(int)$item['id'],$orderId]);if($upd->rowCount()!==1)throw new RuntimeException('Не удалось применить подарочный напиток к заказу.');
        $upd=$pdo->prepare('UPDATE online_orders SET total_amount=GREATEST(0,ROUND(total_amount-?,2)) WHERE id=?');$upd->execute([$discount,$orderId]);
        $key='redeem:online_order:'.$orderId;$stmt=$pdo->prepare('INSERT INTO customer_drink_loyalty_ledger(customer_id,operation_key,source_type,source_id,source_line_id,product_id,stamp_delta,reward_delta,reward_value,note) VALUES(?,?,?,?,?,?,0,-1,?,?)');$stmt->execute([$customerId,$key,'online_order',(string)$orderId,(string)$item['id'],$best['product_id'],$discount,'Автоматически применён подарок «6-й напиток»']);
        $pdo->commit();return ['applied'=>true,'discount'=>$discount,'product_id'=>(int)$best['product_id'],'product_name'=>(string)$item['product_name'],'line_id'=>(int)$item['id'],'gift_cap'=>(float)$summary['gift_cap'],'restored'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_drink_loyalty_restore_online_order_reward(int $orderId,string $reason='Заказ отменён'): int
{
    if($orderId<=0)return 0;$pdo=db();$pdo->beginTransaction();
    try{
        $reward=customer_drink_loyalty_online_reward($orderId,$pdo);if(!$reward){$pdo->commit();return 0;}$customerId=(int)$reward['customer_id'];$lock=$pdo->prepare('SELECT id FROM customer_accounts WHERE id=? FOR UPDATE');$lock->execute([$customerId]);if(!$lock->fetchColumn()){$pdo->commit();return 0;}
        $key='restore:online_order:'.$orderId;$stmt=$pdo->prepare('INSERT IGNORE INTO customer_drink_loyalty_ledger(customer_id,operation_key,source_type,source_id,source_line_id,product_id,stamp_delta,reward_delta,reward_value,note) VALUES(?,?,?,?,?,?,0,1,0,?)');$stmt->execute([$customerId,$key,'online_order',(string)$orderId,'restore',(int)$reward['product_id'],mb_substr($reason,0,255)]);$changed=$stmt->rowCount();$pdo->commit();return $changed>0?1:0;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_drink_loyalty_insert_stamp(PDO $pdo,int $customerId,string $operationKey,string $sourceType,string $sourceId,string $lineId,int $productId,int $units,string $note): int
{
    if($units<=0)return 0;$stmt=$pdo->prepare('INSERT IGNORE INTO customer_drink_loyalty_ledger(customer_id,operation_key,source_type,source_id,source_line_id,product_id,stamp_delta,reward_delta,reward_value,note) VALUES(?,?,?,?,?,?,?,0,0,?)');
    $stmt->execute([$customerId,mb_substr($operationKey,0,255),mb_substr($sourceType,0,40),mb_substr($sourceId,0,190),mb_substr($lineId,0,190),$productId>0?$productId:null,$units,mb_substr($note,0,255)]);return $stmt->rowCount()>0?$units:0;
}

function customer_drink_loyalty_credit_online_order(int $orderId,int $customerId=0): int
{
    $settings=customer_drink_loyalty_settings();if(!$settings['enabled']||$orderId<=0)return 0;$pdo=db();
    $stmt=$pdo->prepare("SELECT o.id,o.status,o.payment_status,o.completed_at,a.customer_id FROM online_orders o JOIN customer_order_access a ON a.order_id=o.id WHERE o.id=? LIMIT 1");$stmt->execute([$orderId]);$order=$stmt->fetch();
    if(!$order||(string)$order['status']!=='completed'||(string)($order['payment_status']??'')==='refunded'||empty($order['completed_at'])||strtotime((string)$order['completed_at'])<strtotime($settings['started_at']))return 0;
    $customerId=$customerId>0?$customerId:(int)$order['customer_id'];if($customerId<=0)return 0;$eligible=customer_drink_loyalty_product_map();$reward=customer_drink_loyalty_online_reward($orderId,$pdo);$giftLineId=(int)($reward['source_line_id']??0);
    $items=$pdo->prepare('SELECT id,local_product_id,quantity,product_name FROM online_order_items WHERE order_id=? ORDER BY id');$items->execute([$orderId]);$added=0;
    foreach($items->fetchAll() as $item){$productId=(int)($item['local_product_id']??0);if($productId<=0||empty($eligible[$productId]))continue;$units=max(0,(int)floor((float)$item['quantity']+0.00001));if((int)$item['id']===$giftLineId&&$units>0)$units--;if($units<=0)continue;$added+=customer_drink_loyalty_insert_stamp($pdo,$customerId,'stamp:online:'.$orderId.':'.(int)$item['id'],'online_order',(string)$orderId,(string)$item['id'],$productId,$units,'Напиток по онлайн-заказу #'.$orderId);}
    return $added;
}

function customer_drink_loyalty_credit_sale(PDO $pdo,int $customerId,int $saleId,string $documentId): int
{
    $settings=customer_drink_loyalty_settings();if(!$settings['enabled']||$customerId<=0||$saleId<=0)return 0;
    $sale=$pdo->prepare('SELECT sold_at FROM sales WHERE id=? LIMIT 1');$sale->execute([$saleId]);$soldAt=(string)($sale->fetchColumn()?:'');if($soldAt===''||strtotime($soldAt)<strtotime($settings['started_at']))return 0;
    $eligible=customer_drink_loyalty_product_map();$items=$pdo->prepare('SELECT id,product_id,quantity FROM sale_items WHERE sale_id=? AND quantity>0 ORDER BY id');$items->execute([$saleId]);$added=0;
    foreach($items->fetchAll() as $item){$productId=(int)$item['product_id'];if(empty($eligible[$productId]))continue;$units=max(0,(int)floor((float)$item['quantity']+0.00001));if($units<=0)continue;$added+=customer_drink_loyalty_insert_stamp($pdo,$customerId,'stamp:evotor:'.$documentId.':'.(int)$item['id'],'evotor_sale',$documentId,(string)$item['id'],$productId,$units,'Напиток по чеку Эвотора');}
    return $added;
}

function customer_drink_loyalty_reverse_source(int $customerId,string $sourceType,string $sourceId,string $reason): int
{
    if($customerId<=0||$sourceId==='')return 0;$pdo=db();$pdo->beginTransaction();
    try{
        $sum=$pdo->prepare('SELECT COALESCE(SUM(stamp_delta),0) FROM customer_drink_loyalty_ledger WHERE customer_id=? AND source_type=? AND source_id=? AND stamp_delta>0');$sum->execute([$customerId,$sourceType,$sourceId]);$units=(int)$sum->fetchColumn();
        if($units<=0){$pdo->commit();return 0;}$key='reverse:'.$sourceType.':'.$sourceId;$stmt=$pdo->prepare('INSERT IGNORE INTO customer_drink_loyalty_ledger(customer_id,operation_key,source_type,source_id,source_line_id,stamp_delta,reward_delta,reward_value,note) VALUES(?,?,?,?,?, ?,0,0,?)');$stmt->execute([$customerId,mb_substr($key,0,255),$sourceType,$sourceId,'reverse',-$units,mb_substr($reason,0,255)]);$changed=$stmt->rowCount()>0?$units:0;$pdo->commit();return $changed;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_drink_loyalty_redeem(int $customerId,string $sourceType,string $sourceId,int $productId,float $productPrice): array
{
    if($customerId<=0||$sourceId===''||$productId<=0)throw new RuntimeException('Не удалось определить клиента или напиток.');if(!customer_drink_loyalty_is_eligible_product($productId))throw new RuntimeException('Этот товар не участвует в программе «6-й напиток».');
    $pdo=db();$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM customer_accounts WHERE id=? FOR UPDATE');$lock->execute([$customerId]);if(!$lock->fetchColumn())throw new RuntimeException('Клиент не найден.');
        $summary=customer_drink_loyalty_summary($customerId,$pdo);if(!$summary['enabled'])throw new RuntimeException('Программа «6-й напиток» выключена.');if($summary['available_rewards']<=0)throw new RuntimeException('Подарочный напиток пока недоступен.');if($summary['gift_cap']<=0)throw new RuntimeException('Не настроена стоимость подарочного напитка.');
        $discount=round(min(max(0,$productPrice),(float)$summary['gift_cap']),2);$key='redeem:'.$sourceType.':'.$sourceId;$stmt=$pdo->prepare('INSERT IGNORE INTO customer_drink_loyalty_ledger(customer_id,operation_key,source_type,source_id,source_line_id,product_id,stamp_delta,reward_delta,reward_value,note) VALUES(?,?,?,?,?,?,0,-1,?,?)');$stmt->execute([$customerId,mb_substr($key,0,255),mb_substr($sourceType,0,40),mb_substr($sourceId,0,190),'reward',$productId,$discount,'Использован бесплатный напиток']);
        if($stmt->rowCount()===0)throw new RuntimeException('Подарок для этой продажи уже использован.');$pdo->commit();return ['discount'=>$discount,'customer_due'=>round(max(0,$productPrice-$discount),2),'gift_cap'=>(float)$summary['gift_cap']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_drink_loyalty_refresh_customer(int $customerId,int $limit=100): array
{
    if($customerId<=0)return ['orders'=>0,'sales'=>0,'stamps'=>0];$limit=max(1,min(300,$limit));$pdo=db();$stamps=0;$orders=0;$sales=0;
    $stmt=$pdo->prepare("SELECT a.order_id FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.customer_id=? AND o.status='completed' AND COALESCE(o.payment_status,'')<>'refunded' ORDER BY o.completed_at DESC,o.id DESC LIMIT {$limit}");$stmt->execute([$customerId]);foreach($stmt->fetchAll() as $row){$orders++;$stamps+=customer_drink_loyalty_credit_online_order((int)$row['order_id'],$customerId);}
    $stmt=$pdo->prepare("SELECT cs.evotor_document_id,cs.sale_id FROM evotor_customer_sales cs WHERE cs.customer_id=? AND cs.sale_id IS NOT NULL ORDER BY cs.id DESC LIMIT {$limit}");$stmt->execute([$customerId]);foreach($stmt->fetchAll() as $row){$sales++;$stamps+=customer_drink_loyalty_credit_sale($pdo,$customerId,(int)$row['sale_id'],(string)$row['evotor_document_id']);}
    return ['orders'=>$orders,'sales'=>$sales,'stamps'=>$stamps];
}
