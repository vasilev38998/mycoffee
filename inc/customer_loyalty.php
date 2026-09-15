<?php
declare(strict_types=1);

require_once __DIR__.'/customer_drink_loyalty.php';

function customer_loyalty_rate(): float
{
    $rate=(float)app_setting('customer_loyalty_percent','5');
    return max(0,min(50,$rate));
}

function customer_loyalty_balance(int $customerId): float
{
    if($customerId<=0)return 0.0;
    $stmt=db()->prepare('SELECT loyalty_balance FROM customer_accounts WHERE id=?');
    $stmt->execute([$customerId]);
    return round((float)($stmt->fetchColumn()?:0),2);
}

function customer_loyalty_preview(float $orderTotal): float
{
    return round(max(0,$orderTotal)*customer_loyalty_rate()/100,2);
}

function customer_loyalty_normalize_spend(mixed $value): float
{
    if($value===null||$value==='')return 0.0;
    if(!is_numeric($value))throw new RuntimeException('Некорректная сумма списания бонусов.');
    $amount=round((float)$value,2);
    if(!is_finite($amount)||$amount<0||$amount>10000000)throw new RuntimeException('Некорректная сумма списания бонусов.');
    return $amount;
}

function customer_loyalty_quote_spend(int $customerId,float $amountDue,mixed $requested): array
{
    $balance=customer_loyalty_balance($customerId);
    $due=round(max(0,$amountDue),2);
    $wanted=customer_loyalty_normalize_spend($requested);
    $max=round(min($balance,$due),2);
    $applied=round(min($wanted,$max),2);
    return ['balance'=>$balance,'requested'=>$wanted,'max_spend'=>$max,'spend'=>$applied,'total'=>round(max(0,$due-$applied),2)];
}

function customer_loyalty_order_spend(int $orderId,?PDO $pdo=null): float
{
    if($orderId<=0)return 0.0;$pdo=$pdo??db();
    $stmt=$pdo->prepare("SELECT COALESCE(-SUM(amount),0) FROM customer_loyalty_ledger WHERE order_id=? AND operation_type='spend' AND amount<0");
    $stmt->execute([$orderId]);
    return round(max(0,(float)$stmt->fetchColumn()),2);
}

function customer_loyalty_apply_order_spend(int $orderId,int $customerId,mixed $requested): array
{
    $wanted=customer_loyalty_normalize_spend($requested);
    if($orderId<=0||$customerId<=0||$wanted<=0)return ['applied'=>0.0,'balance'=>customer_loyalty_balance($customerId)];
    $pdo=db();$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT loyalty_balance FROM customer_accounts WHERE id=? FOR UPDATE');$lock->execute([$customerId]);$row=$lock->fetch();
        if(!$row)throw new RuntimeException('Профиль покупателя не найден.');
        $existing=customer_loyalty_order_spend($orderId,$pdo);
        if($existing>0){$pdo->commit();return ['applied'=>$existing,'balance'=>customer_loyalty_balance($customerId)];}
        $order=$pdo->prepare("SELECT total_amount,status,source FROM online_orders WHERE id=? FOR UPDATE");$order->execute([$orderId]);$orderRow=$order->fetch();
        if(!$orderRow)throw new RuntimeException('Заказ не найден.');
        if((string)$orderRow['source']!=='customer-web')throw new RuntimeException('Списание бонусов доступно только в приложении Kapouch.');
        if(!in_array((string)$orderRow['status'],['new','awaiting_payment'],true))throw new RuntimeException('Для этого заказа бонусы уже нельзя списать.');
        $balance=round(max(0,(float)$row['loyalty_balance']),2);$due=round(max(0,(float)$orderRow['total_amount']),2);$amount=round(min($wanted,$balance,$due),2);
        if($amount<=0){$pdo->commit();return ['applied'=>0.0,'balance'=>$balance];}
        $pdo->prepare("INSERT INTO customer_loyalty_ledger(customer_id,order_id,amount,operation_type,note) VALUES(?,?,?,'spend',?)")->execute([$customerId,$orderId,-$amount,'Списание бонусов в приложении Kapouch']);
        $pdo->prepare('UPDATE customer_accounts SET loyalty_balance=GREATEST(0,ROUND(loyalty_balance-?,2)) WHERE id=?')->execute([$amount,$customerId]);
        $pdo->prepare('UPDATE online_orders SET total_amount=GREATEST(0,ROUND(total_amount-?,2)) WHERE id=?')->execute([$amount,$orderId]);
        $pdo->commit();
        return ['applied'=>$amount,'balance'=>round($balance-$amount,2)];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_loyalty_restore_order_spend(int $orderId,string $reason='Заказ отменён'): float
{
    if($orderId<=0)return 0.0;
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT customer_id FROM customer_order_access WHERE order_id=? LIMIT 1');$stmt->execute([$orderId]);$customerId=(int)($stmt->fetchColumn()?:0);
        if($customerId<=0){$pdo->commit();return 0.0;}
        $lock=$pdo->prepare('SELECT id FROM customer_accounts WHERE id=? FOR UPDATE');$lock->execute([$customerId]);if(!$lock->fetchColumn()){$pdo->commit();return 0.0;}
        $spent=customer_loyalty_order_spend($orderId,$pdo);
        if($spent<=0){$pdo->commit();return 0.0;}
        $check=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_loyalty_ledger WHERE order_id=? AND operation_type='adjust' AND amount>0 AND note LIKE 'Возврат списанных бонусов:%'");$check->execute([$orderId]);$already=round(max(0,(float)$check->fetchColumn()),2);
        $restore=round(max(0,$spent-$already),2);
        if($restore>0){
            $note=mb_substr('Возврат списанных бонусов: '.$reason,0,255);
            $pdo->prepare("INSERT INTO customer_loyalty_ledger(customer_id,order_id,amount,operation_type,note) VALUES(?,?,?,'adjust',?)")->execute([$customerId,$orderId,$restore,$note]);
            $pdo->prepare('UPDATE customer_accounts SET loyalty_balance=ROUND(loyalty_balance+?,2) WHERE id=?')->execute([$restore,$customerId]);
        }
        $pdo->commit();return $restore;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_loyalty_on_order_completed(int $orderId): float
{
    if($orderId<=0)return 0.0;
    $pdo=db();$pdo->beginTransaction();$customerId=0;
    try{
        $stmt=$pdo->prepare("SELECT a.customer_id,a.loyalty_earned_at,o.total_amount,o.status,o.payment_status FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.order_id=? FOR UPDATE");
        $stmt->execute([$orderId]);$row=$stmt->fetch();
        if(!$row||(string)$row['status']!=='completed'||!$row['customer_id']){$pdo->commit();return 0.0;}
        $customerId=(int)$row['customer_id'];
        if((string)($row['payment_status']??'')==='refunded'){
            $pdo->prepare('UPDATE customer_order_access SET loyalty_earned_at=NOW() WHERE order_id=? AND loyalty_earned_at IS NULL')->execute([$orderId]);
            $pdo->commit();
            try{customer_loyalty_restore_order_spend($orderId,'полный возврат заказа');}catch(Throwable $e){error_log('[Kapouch loyalty spend restore] '.$e->getMessage());}
            try{customer_drink_loyalty_reverse_source($customerId,'online_order',(string)$orderId,'Отмена отметок: возврат онлайн-заказа');}catch(Throwable $e){error_log('[Kapouch drink loyalty refund] '.$e->getMessage());}
            return 0.0;
        }
        if($row['loyalty_earned_at']){
            $pdo->commit();
            try{customer_drink_loyalty_credit_online_order($orderId,$customerId);}catch(Throwable $e){error_log('[Kapouch drink loyalty online] '.$e->getMessage());}
            return 0.0;
        }
        $amount=customer_loyalty_preview((float)$row['total_amount']);
        if($amount>0){
            $pdo->prepare("INSERT INTO customer_loyalty_ledger(customer_id,order_id,amount,operation_type,note) VALUES(?,?,?,'earn',?)")->execute([$customerId,$orderId,$amount,'Начисление за завершённый онлайн-заказ']);
            $pdo->prepare('UPDATE customer_accounts SET loyalty_balance=loyalty_balance+? WHERE id=?')->execute([$amount,$customerId]);
        }
        $pdo->prepare('UPDATE customer_order_access SET loyalty_earned_at=NOW() WHERE order_id=?')->execute([$orderId]);
        $pdo->commit();
        try{customer_drink_loyalty_credit_online_order($orderId,$customerId);}catch(Throwable $e){error_log('[Kapouch drink loyalty online] '.$e->getMessage());}
        return $amount;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_loyalty_reverse_order(int $orderId): float
{
    if($orderId<=0)return 0.0;
    $pdo=db();$pdo->beginTransaction();$customerId=0;$earned=0.0;
    try{
        $stmt=$pdo->prepare('SELECT customer_id FROM customer_order_access WHERE order_id=? FOR UPDATE');$stmt->execute([$orderId]);$customerId=(int)($stmt->fetchColumn()?:0);
        if($customerId<=0){$pdo->commit();return 0.0;}
        $check=$pdo->prepare("SELECT COUNT(*) FROM customer_loyalty_ledger WHERE order_id=? AND operation_type='adjust' AND note='Отмена бонусов: полный возврат заказа'");$check->execute([$orderId]);
        if((int)$check->fetchColumn()===0){
            $earnedStmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_loyalty_ledger WHERE order_id=? AND operation_type='earn' AND amount>0");$earnedStmt->execute([$orderId]);$earned=round((float)$earnedStmt->fetchColumn(),2);
            if($earned>0){
                $pdo->prepare("INSERT INTO customer_loyalty_ledger(customer_id,order_id,amount,operation_type,note) VALUES(?,?,?,'adjust',?)")->execute([$customerId,$orderId,-$earned,'Отмена бонусов: полный возврат заказа']);
                $pdo->prepare('UPDATE customer_accounts SET loyalty_balance=loyalty_balance-? WHERE id=?')->execute([$earned,$customerId]);
            }
        }
        $pdo->commit();
        try{customer_loyalty_restore_order_spend($orderId,'полный возврат заказа');}catch(Throwable $e){error_log('[Kapouch loyalty spend restore] '.$e->getMessage());}
        try{customer_drink_loyalty_reverse_source($customerId,'online_order',(string)$orderId,'Отмена отметок: полный возврат заказа');}catch(Throwable $e){error_log('[Kapouch drink loyalty refund] '.$e->getMessage());}
        return $earned;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_loyalty_refresh_completed(int $limit=100): array
{
    $limit=max(1,min(500,$limit));
    $rows=db()->query("SELECT a.order_id FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE o.status='completed' AND a.loyalty_earned_at IS NULL ORDER BY o.completed_at,a.order_id LIMIT {$limit}")->fetchAll();
    $orders=0;$amount=0.0;
    foreach($rows as $row){$amount+=customer_loyalty_on_order_completed((int)$row['order_id']);$orders++;}
    return ['orders'=>$orders,'amount'=>round($amount,2)];
}

function customer_loyalty_refresh_customer(int $customerId,int $limit=30): array
{
    if($customerId<=0)return ['orders'=>0,'amount'=>0.0,'drink_stamps'=>0];
    $limit=max(1,min(100,$limit));
    $stmt=db()->prepare("SELECT a.order_id FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.customer_id=? AND o.status='completed' AND a.loyalty_earned_at IS NULL ORDER BY o.completed_at,a.order_id LIMIT {$limit}");
    $stmt->execute([$customerId]);
    $orders=0;$amount=0.0;
    foreach($stmt->fetchAll() as $row){$amount+=customer_loyalty_on_order_completed((int)$row['order_id']);$orders++;}
    $drink=['stamps'=>0];try{$drink=customer_drink_loyalty_refresh_customer($customerId,$limit);}catch(Throwable $e){error_log('[Kapouch drink loyalty refresh] '.$e->getMessage());}
    return ['orders'=>$orders,'amount'=>round($amount,2),'drink_stamps'=>(int)($drink['stamps']??0)];
}
