<?php
declare(strict_types=1);

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

function customer_loyalty_on_order_completed(int $orderId): float
{
    if($orderId<=0)return 0.0;
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT a.customer_id,a.loyalty_earned_at,o.total_amount,o.status
            FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id
            WHERE a.order_id=? FOR UPDATE");
        $stmt->execute([$orderId]);$row=$stmt->fetch();
        if(!$row||(string)$row['status']!=='completed'||!$row['customer_id']||$row['loyalty_earned_at']){$pdo->commit();return 0.0;}
        $customerId=(int)$row['customer_id'];$amount=customer_loyalty_preview((float)$row['total_amount']);
        if($amount>0){
            $ledger=$pdo->prepare("INSERT INTO customer_loyalty_ledger(customer_id,order_id,amount,operation_type,note) VALUES(?,?,?,'earn',?)");
            $ledger->execute([$customerId,$orderId,$amount,'Начисление за завершённый онлайн-заказ']);
            $pdo->prepare('UPDATE customer_accounts SET loyalty_balance=loyalty_balance+? WHERE id=?')->execute([$amount,$customerId]);
        }
        $pdo->prepare('UPDATE customer_order_access SET loyalty_earned_at=NOW() WHERE order_id=?')->execute([$orderId]);
        $pdo->commit();return $amount;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_loyalty_refresh_completed(int $limit=100): array
{
    $limit=max(1,min(500,$limit));
    $rows=db()->query("SELECT a.order_id FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE o.status='completed' AND a.loyalty_earned_at IS NULL ORDER BY o.completed_at,a.order_id LIMIT {$limit}")->fetchAll();
    $orders=0;$amount=0.0;
    foreach($rows as $row){$earned=customer_loyalty_on_order_completed((int)$row['order_id']);$orders++;$amount+=$earned;}
    return ['orders'=>$orders,'amount'=>round($amount,2)];
}
