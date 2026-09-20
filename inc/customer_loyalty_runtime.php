<?php
declare(strict_types=1);

require_once __DIR__.'/customer_loyalty.php';

function customer_loyalty_refresh_marker_file(int $customerId): string
{
    return rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'kapouch_loyalty_refresh_'.max(0,$customerId).'.stamp';
}

function customer_loyalty_refresh_recent(int $customerId,int $minInterval=20): bool
{
    if($customerId<=0)return true;
    $minInterval=max(5,min(300,$minInterval));
    $file=customer_loyalty_refresh_marker_file($customerId);
    clearstatcache(true,$file);
    if(!is_file($file))return false;
    $mtime=@filemtime($file);
    return is_int($mtime)&&$mtime>0&&time()-$mtime<$minInterval;
}

function customer_loyalty_refresh_customer_if_due(int $customerId,int $limit=30,int $minInterval=20): array
{
    $empty=['orders'=>0,'amount'=>0.0,'drink_stamps'=>0,'restored_spend'=>0.0,'skipped'=>true];
    if($customerId<=0||customer_loyalty_refresh_recent($customerId,$minInterval))return $empty;

    $lock=function_exists('kapouch_local_lock')?kapouch_local_lock('customer_loyalty_refresh:'.$customerId):null;
    if(!$lock)return $empty;
    try{
        // Profile, loyalty card and basket quote can open together in the PWA.
        // Recheck after winning the local lock so only one request performs the
        // expensive historical reconciliation; the others use the fresh ledger.
        if(customer_loyalty_refresh_recent($customerId,$minInterval))return $empty;
        $result=customer_loyalty_refresh_customer($customerId,$limit);
        @touch(customer_loyalty_refresh_marker_file($customerId));
        $result['skipped']=false;
        return $result;
    }finally{
        if(is_resource($lock)&&function_exists('kapouch_local_unlock'))kapouch_local_unlock($lock);
    }
}
