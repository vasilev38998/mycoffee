<?php
declare(strict_types=1);

function customer_welcome_bonus_amount(): float
{
    $amount=(float)app_setting('customer_welcome_bonus','100');
    return round(max(0,min(100000,$amount)),2);
}

function customer_welcome_bonus_grant(int $customerId): float
{
    if($customerId<=0)return 0.0;
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT welcome_bonus_granted_at FROM customer_accounts WHERE id=? FOR UPDATE');
        $stmt->execute([$customerId]);$granted=$stmt->fetchColumn();
        if($granted===false){$pdo->commit();return 0.0;}
        if($granted!==null&&trim((string)$granted)!==''){$pdo->commit();return 0.0;}

        $amount=customer_welcome_bonus_amount();
        if($amount>0){
            $pdo->prepare("INSERT INTO customer_loyalty_ledger(customer_id,order_id,amount,operation_type,note) VALUES(?,NULL,?,'adjust',?)")
                ->execute([$customerId,$amount,'Приветственные бонусы Kapouch']);
            $pdo->prepare('UPDATE customer_accounts SET loyalty_balance=ROUND(loyalty_balance+?,2),welcome_bonus_granted_at=NOW() WHERE id=?')
                ->execute([$amount,$customerId]);
        }else{
            $pdo->prepare('UPDATE customer_accounts SET welcome_bonus_granted_at=NOW() WHERE id=?')->execute([$customerId]);
        }
        $pdo->commit();
        return $amount;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
