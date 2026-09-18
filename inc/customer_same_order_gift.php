<?php
declare(strict_types=1);

require_once __DIR__.'/customer_drink_loyalty.php';
require_once __DIR__.'/customer_checkout_loyalty.php';

function customer_same_order_gift_eligible_units(array $items): int
{
    $eligible=customer_drink_loyalty_product_map();$units=0;
    foreach($items as $row){
        if(!is_array($row))continue;
        $productId=(int)($row['product_id']??$row['id']??0);
        $qty=max(0,min(20,(int)($row['quantity']??0)));
        if($productId>0&&$qty>0&&!empty($eligible[$productId]))$units+=$qty;
    }
    return $units;
}

function customer_same_order_gift_should_unlock(int $customerId,array $items,?array $summary=null): bool
{
    if($customerId<=0||!$items)return false;
    $summary=$summary??customer_drink_loyalty_summary($customerId);
    if(empty($summary['enabled'])||(int)($summary['available_rewards']??0)>0||(float)($summary['gift_cap']??0)<=0)return false;
    $needed=max(1,(int)($summary['next_in']??$summary['required_paid']??1));
    // One or more items first finish the paid-stamp cycle. There must still be
    // another eligible drink in this same order that can become the gift.
    return customer_same_order_gift_eligible_units($items)>$needed;
}

function customer_same_order_gift_insert_provisional(PDO $pdo,int $customerId,string $key): bool
{
    $stmt=$pdo->prepare("INSERT IGNORE INTO customer_drink_loyalty_ledger(customer_id,operation_key,source_type,source_id,source_line_id,product_id,stamp_delta,reward_delta,reward_value,note) VALUES(?,?, 'checkout_pending', ?, 'same_order', NULL, 0, 1, 0, ?)");
    $stmt->execute([$customerId,$key,$key,'Временный reward для подарка, заработанного текущим заказом']);
    return $stmt->rowCount()>0;
}

function customer_same_order_gift_remove_provisional(PDO $pdo,int $customerId,string $key): void
{
    $stmt=$pdo->prepare("DELETE FROM customer_drink_loyalty_ledger WHERE customer_id=? AND operation_key=? AND source_type='checkout_pending' AND reward_delta=1");
    $stmt->execute([$customerId,$key]);
}

function customer_same_order_gift_quote(int $customerId,array $items): array
{
    $quote=customer_drink_loyalty_quote_cart($customerId,$items);
    if(!empty($quote['gift'])||!customer_same_order_gift_should_unlock($customerId,$items,(array)($quote['reward']??[])))return $quote;
    $pdo=db();$ownsTransaction=!$pdo->inTransaction();
    if(!$ownsTransaction)return $quote;
    $key='provisional:quote:'.$customerId.':'.bin2hex(random_bytes(8));
    $pdo->beginTransaction();
    try{
        if(!customer_same_order_gift_insert_provisional($pdo,$customerId,$key)){throw new RuntimeException('Не удалось рассчитать подарок.');}
        $quote=customer_drink_loyalty_quote_cart($customerId,$items);
        if(!empty($quote['gift']))$quote['gift']['earned_in_current_order']=true;
        $pdo->rollBack();
        return $quote;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_same_order_gift_create(array $data,array $customer): array
{
    // The PWA loyalty mechanics are mutually exclusive: when the customer
    // chooses ordinary points, the sixth-drink reward must stay untouched for
    // a future order. Do not create a provisional same-order reward either.
    if(customer_checkout_loyalty_mode($data)!=='gift')return customer_order_create($data,$customer);

    $customerId=(int)($customer['id']??0);$items=$data['items']??[];
    if($customerId<=0||!is_array($items))return customer_order_create($data,$customer);

    // Checkout must not rely on a quote having run just before it. Bring any
    // completed online/Evotor purchases into the stamp ledger first, then make
    // the same-order decision again while holding a per-customer lock.
    customer_drink_loyalty_refresh_customer($customerId,100);
    if(!customer_same_order_gift_should_unlock($customerId,$items))return customer_order_create($data,$customer);

    $lockName='customer_same_order_gift:'.$customerId;
    // This section can lead to an external YooKassa request. A MySQL advisory
    // lock would pin its DB connection until that request returns, so serialize
    // the checkout with the host-local file lock instead.
    $lockHandle=function_exists('kapouch_local_lock')?kapouch_local_lock($lockName):true;
    if(!$lockHandle)throw new RuntimeException('Бонусная программа сейчас обновляется. Повторите оформление через пару секунд.');
    $clientId=trim((string)($data['client_order_id']??''));
    $key='provisional:order:'.$customerId.':'.substr(hash('sha256',$clientId!==''?$clientId:bin2hex(random_bytes(12))),0,32);
    $inserted=false;
    $cleanup=static function()use($customerId,$key,&$inserted): void{
        if(!$inserted)return;
        try{customer_same_order_gift_remove_provisional(db(),$customerId,$key);$inserted=false;}catch(Throwable $e){error_log('[Kapouch same-order gift cleanup] '.$e->getMessage());}
    };
    register_shutdown_function($cleanup);
    try{
        customer_drink_loyalty_refresh_customer($customerId,100);
        if(!customer_same_order_gift_should_unlock($customerId,$items))return customer_order_create($data,$customer);

        // A committed +1 lets the existing atomic redemption path treat the
        // just-earned gift exactly like an already-earned reward. The row is
        // removed immediately after order creation; the resulting -1 redemption
        // is balanced by stamps credited when the paid drinks are completed.
        $pdo=db();
        customer_same_order_gift_remove_provisional($pdo,$customerId,$key);
        $inserted=customer_same_order_gift_insert_provisional($pdo,$customerId,$key);
        if(!$inserted)throw new RuntimeException('Не удалось активировать подарок для текущего заказа.');
        // Do not keep a local PDO reference alive while order creation may wait
        // on YooKassa. customer_order_create() can now release MySQL cleanly.
        $pdo=null;
        if(function_exists('db_disconnect'))db_disconnect();
        return customer_order_create($data,$customer);
    }finally{
        $cleanup();
        if(is_resource($lockHandle)&&function_exists('kapouch_local_unlock'))kapouch_local_unlock($lockHandle);
    }
}