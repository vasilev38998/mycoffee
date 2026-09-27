<?php
declare(strict_types=1);

require_once __DIR__.'/customer_loyalty.php';
require_once __DIR__.'/customer_drink_loyalty.php';

function customer_wheel_settings(): array
{
    $started=trim((string)app_setting('customer_wheel_started_at',''));
    if($started===''||strtotime($started)===false)$started=date('Y-m-d H:i:s');
    return [
        'enabled'=>(string)app_setting('customer_wheel_enabled','1')==='1',
        'min_order'=>round(max(0,(float)app_setting('customer_wheel_min_order','250')),2),
        'cooldown_hours'=>max(0,min(720,(int)app_setting('customer_wheel_cooldown_hours','20'))),
        'started_at'=>$started,
        'title'=>trim((string)app_setting('customer_wheel_title','Колесо Kapouch'))?:'Колесо Kapouch',
        'subtitle'=>trim((string)app_setting('customer_wheel_subtitle','Заверши заказ — забери подарок'))?:'Заверши заказ — забери подарок',
    ];
}

function customer_wheel_prizes(bool $activeOnly=true,?PDO $pdo=null): array
{
    $pdo=$pdo??db();
    $where=$activeOnly?'WHERE active=1 AND weight>0':'';
    $rows=$pdo->query("SELECT id,title,subtitle,prize_type,value,cap_value,weight,daily_limit,validity_days,icon,accent,active,sort_order FROM customer_wheel_prizes {$where} ORDER BY sort_order,id")->fetchAll();
    foreach($rows as &$row){
        $row['id']=(int)$row['id'];$row['value']=(float)$row['value'];$row['cap_value']=(float)$row['cap_value'];$row['weight']=(int)$row['weight'];$row['daily_limit']=(int)$row['daily_limit'];$row['validity_days']=(int)$row['validity_days'];$row['active']=(bool)$row['active'];$row['sort_order']=(int)$row['sort_order'];
    }unset($row);
    return $rows;
}

function customer_wheel_public_prizes(?PDO $pdo=null): array
{
    $rows=[];
    foreach(customer_wheel_prizes(true,$pdo) as $prize){
        if($prize['prize_type']==='free_drink'&&!customer_drink_loyalty_settings()['enabled'])continue;
        $rows[]=[
            'id'=>$prize['id'],'title'=>$prize['title'],'subtitle'=>$prize['subtitle'],'type'=>$prize['prize_type'],
            'value'=>$prize['value'],'cap'=>$prize['cap_value'],'icon'=>$prize['icon'],'accent'=>$prize['accent'],
        ];
    }
    return $rows;
}

function customer_wheel_uuid(): string
{
    $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    $hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}

function customer_wheel_expire_discounts(int $customerId=0,?PDO $pdo=null): int
{
    $pdo=$pdo??db();
    if($customerId>0){$stmt=$pdo->prepare("UPDATE customer_wheel_spins SET reward_status='expired' WHERE customer_id=? AND prize_type='discount_percent' AND reward_status='available' AND expires_at IS NOT NULL AND expires_at<NOW()");$stmt->execute([$customerId]);return $stmt->rowCount();}
    return (int)$pdo->exec("UPDATE customer_wheel_spins SET reward_status='expired' WHERE prize_type='discount_percent' AND reward_status='available' AND expires_at IS NOT NULL AND expires_at<NOW()");
}

function customer_wheel_latest_spin(int $customerId,?PDO $pdo=null): ?array
{
    if($customerId<=0)return null;$pdo=$pdo??db();$stmt=$pdo->prepare('SELECT id,created_at FROM customer_wheel_spins WHERE customer_id=? AND source_order_id IS NOT NULL ORDER BY id DESC LIMIT 1');$stmt->execute([$customerId]);$row=$stmt->fetch();return $row?:null;
}

function customer_wheel_manual_attempts(int $customerId,?PDO $pdo=null): int
{
    if($customerId<=0)return 0;$pdo=$pdo??db();$stmt=$pdo->prepare('SELECT COALESCE(SUM(attempts_remaining),0) FROM customer_wheel_attempt_grants WHERE customer_id=? AND attempts_remaining>0');$stmt->execute([$customerId]);return max(0,(int)$stmt->fetchColumn());
}

function customer_wheel_grant_manual_attempts(int $customerId,int $attempts,int $grantedByUserId=0,string $note='',?PDO $pdo=null): int
{
    if($customerId<=0)throw new RuntimeException('Клиент не найден.');
    $attempts=max(1,min(50,$attempts));$note=trim($note);if(mb_strlen($note)>255)$note=mb_substr($note,0,255);$pdo=$pdo??db();
    $check=$pdo->prepare('SELECT id FROM customer_accounts WHERE id=?');$check->execute([$customerId]);if(!$check->fetchColumn())throw new RuntimeException('Клиент не найден.');
    $stmt=$pdo->prepare('INSERT INTO customer_wheel_attempt_grants(customer_id,attempts_total,attempts_remaining,granted_by_user_id,note) VALUES(?,?,?,?,?)');
    $stmt->execute([$customerId,$attempts,$attempts,$grantedByUserId>0?$grantedByUserId:null,$note!==''?$note:null]);return (int)$pdo->lastInsertId();
}

function customer_wheel_take_manual_attempt(PDO $pdo,int $customerId): ?int
{
    $stmt=$pdo->prepare('SELECT id FROM customer_wheel_attempt_grants WHERE customer_id=? AND attempts_remaining>0 ORDER BY id ASC LIMIT 1 FOR UPDATE');$stmt->execute([$customerId]);$grantId=(int)($stmt->fetchColumn()?:0);if($grantId<=0)return null;
    $upd=$pdo->prepare('UPDATE customer_wheel_attempt_grants SET attempts_remaining=attempts_remaining-1 WHERE id=? AND attempts_remaining>0');$upd->execute([$grantId]);if($upd->rowCount()!==1)return null;return $grantId;
}

function customer_wheel_eligible_order(int $customerId,?PDO $pdo=null): ?array
{
    if($customerId<=0)return null;$pdo=$pdo??db();$settings=customer_wheel_settings();if(!$settings['enabled'])return null;
    $stmt=$pdo->prepare("SELECT o.id,o.order_number,o.total_amount,o.updated_at FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id LEFT JOIN customer_wheel_spins ws ON ws.source_order_id=o.id WHERE a.customer_id=? AND o.source='customer-web' AND o.status='completed' AND COALESCE(o.payment_status,'')<>'refunded' AND o.total_amount>=? AND o.updated_at>=? AND ws.id IS NULL ORDER BY o.updated_at DESC,o.id DESC LIMIT 1");
    $stmt->execute([$customerId,$settings['min_order'],$settings['started_at']]);$row=$stmt->fetch();
    if(!$row)return null;return ['id'=>(int)$row['id'],'order_number'=>(string)$row['order_number'],'total_amount'=>(float)$row['total_amount'],'completed_at'=>(string)$row['updated_at']];
}

function customer_wheel_next_available_at(int $customerId,?PDO $pdo=null): ?string
{
    $settings=customer_wheel_settings();if($settings['cooldown_hours']<=0)return null;$latest=customer_wheel_latest_spin($customerId,$pdo);if(!$latest)return null;
    $ts=strtotime((string)$latest['created_at']);if($ts===false)return null;$next=$ts+$settings['cooldown_hours']*3600;return $next>time()?date('Y-m-d H:i:s',$next):null;
}

function customer_wheel_active_discount(int $customerId,int $rewardId=0,?PDO $pdo=null): ?array
{
    if($customerId<=0)return null;$pdo=$pdo??db();customer_wheel_expire_discounts($customerId,$pdo);
    $sql="SELECT id,spin_uuid,prize_title,prize_value,prize_cap,reward_status,expires_at,created_at FROM customer_wheel_spins WHERE customer_id=? AND prize_type='discount_percent' AND reward_status='available' AND (expires_at IS NULL OR expires_at>=NOW())";
    $args=[$customerId];if($rewardId>0){$sql.=' AND id=?';$args[]=$rewardId;}$sql.=' ORDER BY id DESC LIMIT 1';
    $stmt=$pdo->prepare($sql);$stmt->execute($args);$row=$stmt->fetch();if(!$row)return null;
    return ['id'=>(int)$row['id'],'spin_uuid'=>(string)$row['spin_uuid'],'title'=>(string)$row['prize_title'],'percent'=>(float)$row['prize_value'],'cap'=>(float)$row['prize_cap'],'expires_at'=>$row['expires_at']?:null,'created_at'=>(string)$row['created_at']];
}

function customer_wheel_recent_wins(int $customerId,int $limit=5,?PDO $pdo=null): array
{
    if($customerId<=0)return [];$pdo=$pdo??db();$limit=max(1,min(10,$limit));
    $stmt=$pdo->prepare("SELECT id,prize_title,prize_type,prize_value,reward_status,expires_at,created_at FROM customer_wheel_spins WHERE customer_id=? ORDER BY id DESC LIMIT {$limit}");$stmt->execute([$customerId]);$rows=$stmt->fetchAll();
    return array_map(static fn(array $r): array=>['id'=>(int)$r['id'],'title'=>(string)$r['prize_title'],'type'=>(string)$r['prize_type'],'value'=>(float)$r['prize_value'],'status'=>(string)$r['reward_status'],'expires_at'=>$r['expires_at']?:null,'created_at'=>(string)$r['created_at']],$rows);
}

function customer_wheel_public_status(int $customerId): array
{
    $settings=customer_wheel_settings();$pdo=db();customer_wheel_expire_discounts($customerId,$pdo);$manual=customer_wheel_manual_attempts($customerId,$pdo);$next=$manual>0?null:customer_wheel_next_available_at($customerId,$pdo);$eligible=$manual>0?null:($next===null?customer_wheel_eligible_order($customerId,$pdo):null);$discount=customer_wheel_active_discount($customerId,0,$pdo);
    $reason='order';
    if(!$settings['enabled'])$reason='disabled';elseif($manual>0)$reason='manual';elseif($next!==null)$reason='cooldown';elseif($eligible!==null)$reason='ready';
    return [
        'enabled'=>$settings['enabled'],'title'=>$settings['title'],'subtitle'=>$settings['subtitle'],'min_order'=>$settings['min_order'],'cooldown_hours'=>$settings['cooldown_hours'],
        'can_spin'=>$settings['enabled']&&($manual>0||($next===null&&$eligible!==null)),'reason'=>$reason,'available_at'=>$next,'manual_attempts'=>$manual,
        'eligible_order'=>$eligible?['order_number'=>$eligible['order_number'],'total_amount'=>$eligible['total_amount'],'completed_at'=>$eligible['completed_at']]:null,
        'prizes'=>customer_wheel_public_prizes($pdo),'active_discount'=>$discount,'recent'=>customer_wheel_recent_wins($customerId,5,$pdo),
    ];
}

function customer_wheel_pick_prize(PDO $pdo): array
{
    $drinkEnabled=customer_drink_loyalty_settings()['enabled'];$available=[];$total=0;
    foreach(customer_wheel_prizes(true,$pdo) as $prize){
        if($prize['prize_type']==='free_drink'&&!$drinkEnabled)continue;
        $limit=(int)$prize['daily_limit'];
        if($limit>0){$stmt=$pdo->prepare('SELECT COUNT(*) FROM customer_wheel_spins WHERE prize_id=? AND created_at>=CURDATE() AND created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)');$stmt->execute([$prize['id']]);if((int)$stmt->fetchColumn()>=$limit)continue;}
        $weight=max(0,(int)$prize['weight']);if($weight<=0)continue;$total+=$weight;$available[]=['prize'=>$prize,'ceiling'=>$total];
    }
    if($total<=0||!$available)throw new RuntimeException('Призы колеса временно закончились. Попробуйте позже.');
    $roll=random_int(1,$total);foreach($available as $entry)if($roll<=$entry['ceiling'])return $entry['prize'];return $available[array_key_last($available)]['prize'];
}

function customer_wheel_grant_prize(PDO $pdo,int $spinId,string $spinUuid,int $customerId,array $prize): array
{
    $type=(string)$prize['prize_type'];$value=(float)$prize['value'];$title=(string)$prize['title'];$status='granted';$expires=null;
    if($type==='points'){
        $amount=round(max(0,$value),2);if($amount<=0)throw new RuntimeException('Некорректный бонусный приз.');
        $pdo->prepare("INSERT INTO customer_loyalty_ledger(customer_id,order_id,amount,operation_type,note) VALUES(?,NULL,?,'adjust',?)")->execute([$customerId,$amount,'Колесо Kapouch · '.$title]);
        $pdo->prepare('UPDATE customer_accounts SET loyalty_balance=ROUND(loyalty_balance+?,2) WHERE id=?')->execute([$amount,$customerId]);
    }elseif($type==='stamp'){
        $stamps=max(1,min(20,(int)round($value)));$pdo->prepare("INSERT INTO customer_drink_loyalty_ledger(customer_id,operation_key,source_type,source_id,source_line_id,product_id,stamp_delta,reward_delta,reward_value,note) VALUES(?,?,?,?, '',NULL,?,0,0,?)")->execute([$customerId,'wheel:'.$spinUuid,'wheel',$spinUuid,$stamps,'Колесо Kapouch · '.$title]);
    }elseif($type==='free_drink'){
        $pdo->prepare("INSERT INTO customer_drink_loyalty_ledger(customer_id,operation_key,source_type,source_id,source_line_id,product_id,stamp_delta,reward_delta,reward_value,note) VALUES(?,?,?,?, '',NULL,0,1,0,?)")->execute([$customerId,'wheel:'.$spinUuid,'wheel',$spinUuid,'Колесо Kapouch · '.$title]);
    }elseif($type==='discount_percent'){
        $days=max(1,min(365,(int)$prize['validity_days']));$status='available';$expires=date('Y-m-d H:i:s',time()+$days*86400);
    }else throw new RuntimeException('Неизвестный тип приза.');
    $stmt=$pdo->prepare('UPDATE customer_wheel_spins SET reward_status=?,expires_at=? WHERE id=?');$stmt->execute([$status,$expires,$spinId]);
    return ['status'=>$status,'expires_at'=>$expires];
}

function customer_wheel_spin(int $customerId): array
{
    if($customerId<=0)throw new RuntimeException('Сначала войдите в профиль Kapouch.');$settings=customer_wheel_settings();if(!$settings['enabled'])throw new RuntimeException('Колесо сейчас на паузе.');
    $lock=function_exists('kapouch_local_lock')?kapouch_local_lock('customer_wheel_spin:'.$customerId):true;if(!$lock)throw new RuntimeException('Колесо уже крутится. Подождите пару секунд.');
    try{
        $pdo=db();$pdo->beginTransaction();
        try{
            $customerLock=$pdo->prepare('SELECT id FROM customer_accounts WHERE id=? FOR UPDATE');$customerLock->execute([$customerId]);if(!$customerLock->fetchColumn())throw new RuntimeException('Профиль клиента не найден.');
            $manualGrantId=customer_wheel_take_manual_attempt($pdo,$customerId);$order=null;
            if($manualGrantId===null){
                $next=customer_wheel_next_available_at($customerId,$pdo);if($next!==null)throw new RuntimeException('Следующее вращение будет доступно позже.');
                $order=customer_wheel_eligible_order($customerId,$pdo);if(!$order)throw new RuntimeException('Завершите подходящий заказ, чтобы открыть вращение.');
            }
            $prize=customer_wheel_pick_prize($pdo);$uuid=customer_wheel_uuid();
            $insert=$pdo->prepare("INSERT INTO customer_wheel_spins(spin_uuid,customer_id,source_order_id,attempt_grant_id,prize_id,prize_title,prize_type,prize_value,prize_cap,reward_status) VALUES(?,?,?,?,?,?,?,?,?, 'granted')");
            $insert->execute([$uuid,$customerId,$order['id']??null,$manualGrantId,$prize['id'],$prize['title'],$prize['prize_type'],$prize['value'],$prize['cap_value']]);$spinId=(int)$pdo->lastInsertId();
            $grant=customer_wheel_grant_prize($pdo,$spinId,$uuid,$customerId,$prize);$pdo->commit();
            $public=customer_wheel_public_prizes();$index=0;foreach($public as $i=>$segment)if((int)$segment['id']===(int)$prize['id']){$index=$i;break;}
            return ['spin_id'=>$spinId,'spin_uuid'=>$uuid,'source'=>$manualGrantId!==null?'manual':'order','prize'=>['id'=>(int)$prize['id'],'title'=>(string)$prize['title'],'subtitle'=>(string)($prize['subtitle']??''),'type'=>(string)$prize['prize_type'],'value'=>(float)$prize['value'],'cap'=>(float)$prize['cap_value'],'icon'=>(string)$prize['icon'],'accent'=>(string)$prize['accent'],'status'=>$grant['status'],'expires_at'=>$grant['expires_at']],'prize_index'=>$index,'segment_count'=>count($public),'source_order_number'=>$order['order_number']??null];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }finally{if(is_resource($lock)&&function_exists('kapouch_local_unlock'))kapouch_local_unlock($lock);}
}

function customer_wheel_discount_quote(int $customerId,array $rawItems,int $rewardId=0): ?array
{
    $voucher=customer_wheel_active_discount($customerId,$rewardId);if(!$voucher||!$rawItems)return null;$eligible=customer_drink_loyalty_product_map();$ids=[];$lines=[];
    foreach($rawItems as $row){if(!is_array($row))continue;$id=(int)($row['product_id']??$row['id']??0);$qty=max(0,min(20,(int)($row['quantity']??0)));if($id<=0||$qty<=0||empty($eligible[$id]))continue;$ids[$id]=true;$lines[]=['id'=>$id,'qty'=>$qty];}
    if(!$lines)return null;$productIds=array_keys($ids);$ph=implode(',',array_fill(0,count($productIds),'?'));$stmt=db()->prepare("SELECT id,name,sale_price FROM products WHERE active=1 AND sale_price>0 AND id IN ({$ph})");$stmt->execute($productIds);$products=[];foreach($stmt->fetchAll() as $row)$products[(int)$row['id']]=$row;
    $best=null;$percent=max(0,min(100,(float)$voucher['percent']));$cap=max(0,(float)$voucher['cap']);
    foreach($lines as $line){if(!isset($products[$line['id']]))continue;$price=round((float)$products[$line['id']]['sale_price'],2);$discount=round($price*$percent/100,2);if($cap>0)$discount=min($discount,$cap);if($discount>0&&($best===null||$discount>$best['discount']))$best=['product_id'=>$line['id'],'product_name'=>(string)$products[$line['id']]['name'],'product_price'=>$price,'discount'=>$discount];}
    if(!$best)return null;return $best+['reward_id'=>$voucher['id'],'title'=>$voucher['title'],'percent'=>$percent,'cap'=>$cap,'expires_at'=>$voucher['expires_at']];
}

function customer_wheel_order_discount(int $orderId,?PDO $pdo=null): ?array
{
    if($orderId<=0)return null;$pdo=$pdo??db();$stmt=$pdo->prepare("SELECT id,customer_id,prize_title,prize_value,prize_cap,reward_status,redeemed_order_id,expires_at,redeemed_at FROM customer_wheel_spins WHERE prize_type='discount_percent' AND redeemed_order_id=? LIMIT 1");$stmt->execute([$orderId]);$row=$stmt->fetch();if(!$row)return null;return ['id'=>(int)$row['id'],'customer_id'=>(int)$row['customer_id'],'title'=>(string)$row['prize_title'],'percent'=>(float)$row['prize_value'],'cap'=>(float)$row['prize_cap'],'status'=>(string)$row['reward_status'],'expires_at'=>$row['expires_at']?:null,'redeemed_at'=>$row['redeemed_at']?:null];
}

function customer_wheel_apply_order_discount(int $orderId,int $customerId,int $rewardId=0): array
{
    if($orderId<=0||$customerId<=0)return ['applied'=>false,'discount'=>0.0];$pdo=db();$pdo->beginTransaction();
    try{
        $existing=customer_wheel_order_discount($orderId,$pdo);if($existing){$pdo->commit();return ['applied'=>true,'discount'=>0.0,'reward_id'=>$existing['id'],'restored'=>false,'existing'=>true];}
        $sql="SELECT * FROM customer_wheel_spins WHERE customer_id=? AND prize_type='discount_percent' AND reward_status='available' AND (expires_at IS NULL OR expires_at>=NOW())";$args=[$customerId];if($rewardId>0){$sql.=' AND id=?';$args[]=$rewardId;}$sql.=' ORDER BY id DESC LIMIT 1 FOR UPDATE';$stmt=$pdo->prepare($sql);$stmt->execute($args);$voucher=$stmt->fetch();if(!$voucher){$pdo->commit();return ['applied'=>false,'discount'=>0.0];}
        $eligible=customer_drink_loyalty_product_map();$items=$pdo->prepare("SELECT id,local_product_id,product_name,quantity,unit_price,line_total,item_comment FROM online_order_items WHERE order_id=? AND (variant_name IS NULL OR variant_name='') ORDER BY id");$items->execute([$orderId]);$best=null;$percent=max(0,min(100,(float)$voucher['prize_value']));$cap=max(0,(float)$voucher['prize_cap']);
        foreach($items->fetchAll() as $item){$productId=(int)($item['local_product_id']??0);$qty=(float)$item['quantity'];$unit=round((float)$item['unit_price'],2);if($productId<=0||$qty<=0||$unit<=0||empty($eligible[$productId]))continue;$wanted=round($unit*$percent/100,2);if($cap>0)$wanted=min($wanted,$cap);if($wanted>0&&($best===null||$wanted>$best['wanted']))$best=['item'=>$item,'wanted'=>$wanted];}
        if(!$best){$pdo->commit();return ['applied'=>false,'discount'=>0.0];}
        $item=$best['item'];$qty=(float)$item['quantity'];$oldLine=round((float)$item['line_total'],2);$wanted=(float)$best['wanted'];$due=max(0,$oldLine-$wanted);$newUnit=$qty>0?ceil(($due/$qty)*100-0.000001)/100:0.0;$newLine=round($newUnit*$qty,2);$discount=round(max(0,$oldLine-$newLine),2);if($discount<=0){$pdo->commit();return ['applied'=>false,'discount'=>0.0];}
        $note='Колесо Kapouch: скидка '.number_format($percent,0,'.','').'% (−'.number_format($discount,2,'.','').' ₽)';$existingComment=trim((string)($item['item_comment']??''));$comment=mb_substr($existingComment!==''?$existingComment.' · '.$note:$note,0,500);
        $upd=$pdo->prepare('UPDATE online_order_items SET unit_price=?,line_total=?,item_comment=? WHERE id=? AND order_id=?');$upd->execute([$newUnit,$newLine,$comment,(int)$item['id'],$orderId]);if($upd->rowCount()!==1)throw new RuntimeException('Не удалось применить приз колеса.');
        $pdo->prepare('UPDATE online_orders SET total_amount=ROUND(GREATEST(0,total_amount-?),2) WHERE id=?')->execute([$discount,$orderId]);
        $pdo->prepare("UPDATE customer_wheel_spins SET reward_status='redeemed',redeemed_order_id=?,redeemed_at=NOW() WHERE id=? AND reward_status='available'")->execute([$orderId,(int)$voucher['id']]);
        $pdo->commit();return ['applied'=>true,'discount'=>$discount,'reward_id'=>(int)$voucher['id'],'product_name'=>(string)$item['product_name'],'percent'=>$percent,'restored'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_wheel_restore_order_discount(int $orderId,string $reason=''): bool
{
    if($orderId<=0)return false;$pdo=db();$stmt=$pdo->prepare("UPDATE customer_wheel_spins SET reward_status='available',redeemed_order_id=NULL,redeemed_at=NULL,expires_at=IF(expires_at IS NULL OR expires_at<NOW(),DATE_ADD(NOW(),INTERVAL 7 DAY),expires_at) WHERE prize_type='discount_percent' AND reward_status='redeemed' AND redeemed_order_id=?");$stmt->execute([$orderId]);
    if($stmt->rowCount()>0&&function_exists('kapouch_runtime_log'))kapouch_runtime_log('wheel','discount_restored',['order_id'=>$orderId,'reason'=>mb_substr($reason,0,160)]);return $stmt->rowCount()>0;
}
