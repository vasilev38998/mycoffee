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
    try{set_system_meta('online_orders_last_pull_error',mb_substr($e->getMessage(),0,500));set_system_meta('online_orders_last_pull_at',date('Y-m-d H:i:s'));}catch(Throwable $ignored){}
    fwrite(STDERR,'['.date('c').'] online orders: '.$e->getMessage()."\n");
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
