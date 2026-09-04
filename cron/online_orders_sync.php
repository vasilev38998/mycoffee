<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    http_response_code(403);
    exit("CLI only\n");
}

$root=dirname(__DIR__);
$lock=fopen(sys_get_temp_dir().'/kapouch_online_orders_sync.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){
    echo '['.date('c')."] online orders sync already running\n";
    exit(0);
}

require $root.'/inc/bootstrap.php';
require_once $root.'/inc/online_orders.php';

$failed=false;
try{
    $result=online_orders_pull_once();
    if(!$result['configured']){
        echo '['.date('c')."] online orders pull URL is not configured; push API is active\n";
    }else{
        echo sprintf("[%s] online orders: received %d, created %d, updated %d\n",date('c'),$result['received'],$result['created'],$result['updated']);
    }
}catch(Throwable $e){
    $failed=true;
    try{set_system_meta('online_orders_last_pull_error',mb_substr($e->getMessage(),0,500));set_system_meta('online_orders_last_pull_error_at',date('Y-m-d H:i:s'));}catch(Throwable $ignored){}
    fwrite(STDERR,'['.date('c').'] online orders: '.$e->getMessage()."\n");
}

try{
    require_once $root.'/inc/customer_loyalty.php';
    $loyalty=customer_loyalty_refresh_completed(100);
    if($loyalty['orders']>0)echo sprintf("[%s] customer loyalty: processed %d orders, earned %.2f\n",date('c'),$loyalty['orders'],$loyalty['amount']);
}catch(Throwable $e){
    $failed=true;
    fwrite(STDERR,'['.date('c').'] customer loyalty: '.$e->getMessage()."\n");
}

try{
    require_once $root.'/inc/customer_push.php';
    $readyRows=db()->query("SELECT o.id FROM online_orders o JOIN customer_order_access a ON a.order_id=o.id WHERE o.status='ready' AND a.customer_id IS NOT NULL AND o.ready_at>=DATE_SUB(NOW(),INTERVAL 3 DAY) ORDER BY o.id DESC LIMIT 200")->fetchAll();
    foreach($readyRows as $row)customer_push_enqueue_order_ready((int)$row['id']);
    $loyaltyRows=db()->query("SELECT l.customer_id,l.order_id,l.amount FROM customer_loyalty_ledger l WHERE l.operation_type='earn' AND l.order_id IS NOT NULL AND l.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY l.id DESC LIMIT 300")->fetchAll();
    foreach($loyaltyRows as $row)customer_push_enqueue_loyalty((int)$row['customer_id'],(int)$row['order_id'],(float)$row['amount']);
    $push=customer_push_process_queue(50);
    if($push['processed']>0)echo sprintf("[%s] customer push: processed %d, sent %d, failed %d\n",date('c'),$push['processed'],$push['sent'],$push['failed']);
}catch(Throwable $e){
    $failed=true;
    fwrite(STDERR,'['.date('c').'] customer push: '.$e->getMessage()."\n");
}

try{
    require_once $root.'/inc/customer_auth.php';
    $authCleanup=customer_auth_cleanup();
    if($authCleanup['codes']>0||$authCleanup['sessions']>0)echo sprintf("[%s] customer auth cleanup: codes %d, sessions %d\n",date('c'),$authCleanup['codes'],$authCleanup['sessions']);
}catch(Throwable $e){
    $failed=true;
    fwrite(STDERR,'['.date('c').'] customer auth cleanup: '.$e->getMessage()."\n");
}

try{
    $cleaned=online_orders_cleanup_test_orders(24);
    if($cleaned>0)echo sprintf("[%s] removed old completed test orders: %d\n",date('c'),$cleaned);
}catch(Throwable $e){
    $failed=true;
    fwrite(STDERR,'['.date('c').'] test cleanup: '.$e->getMessage()."\n");
}

flock($lock,LOCK_UN);
fclose($lock);
exit($failed?1:0);
