<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$db=file_get_contents($root.'/inc/db.php');
$bootstrap=file_get_contents($root.'/inc/bootstrap.php');
$security=file_get_contents($root.'/inc/security.php');
$authApi=file_get_contents($root.'/api/customer_auth_request.php');
$auth=file_get_contents($root.'/inc/customer_auth.php');
$feed=file_get_contents($root.'/online_orders_feed.php');
$board=file_get_contents($root.'/online_orders.php');
$config=file_get_contents($root.'/customer/config.js');
$orderTracker=file_get_contents($root.'/customer/assets/current-order.js');
$sw=file_get_contents($root.'/customer/sw.js');

$checks=[
    'database capacity errors classify mysql 1040 and 1203'=>str_contains($db,'[1040,1203]')&&str_contains($db,'db_capacity_error'),
    'pdo can be disconnected before slow network work'=>str_contains($db,'function db_disconnect()'),
    'uncaught capacity failure becomes HTTP 503'=>str_contains($bootstrap,"http_response_code(503)")&&str_contains($bootstrap,"header('Retry-After: 20')"),
    'migration bootstrap rethrows capacity error'=>str_contains($bootstrap,'if(db_capacity_error($e))throw $e;'),
    'local locks exist without mysql connection'=>str_contains($security,'function kapouch_local_lock')&&str_contains($security,'LOCK_EX|LOCK_NB'),
    'sms request no longer holds mysql advisory lock'=>str_contains($authApi,"kapouch_local_lock('customer_sms_code:'")&&!str_contains($authApi,'kapouch_advisory_lock($lockPurpose'),
    'sms releases pdo before curl'=>str_contains($auth,'db_disconnect();')&&str_contains($auth,"curl_init('https://sms.ru/sms/send')"),
    'order feed short cache bypasses database'=>str_contains($feed,'$cacheAge<=8')&&str_contains($feed,"X-Kapouch-Feed: cache"),
    'order feed serves stale snapshot on temporary failure'=>str_contains($feed,'$cacheAge<=120')&&str_contains($feed,"X-Kapouch-Feed: stale"),
    'staff order board polling is reduced and backs off'=>str_contains($board,'Live · каждые 5 сек')&&str_contains($board,'pollFailures')&&str_contains($board,"r.headers.get('Retry-After')"),
    'pwa base polling interval is ten seconds'=>str_contains($config,'pollIntervalMs: 10000'),
    'pwa order tracker pauses hidden and backs off'=>str_contains($orderTracker,'ACTIVE_POLL_MS')&&str_contains($orderTracker,'document.hidden')&&str_contains($orderTracker,"r.headers.get('Retry-After')"),
    'pwa cache retains connection-pressure fix'=>str_contains($sw,"kapouch-pwa-v41")&&str_contains($sw,"kapouch-pwa-v40")&&str_contains($sw,"kapouch-pwa-v39")&&str_contains($sw,"kapouch-pwa-v38"),
];

foreach($checks as $label=>$ok){
    if(!$ok){fwrite(STDERR,"Database pressure contract failed: {$label}\n");exit(1);}
}
echo "DATABASE CONNECTION PRESSURE CONTRACT PASSED\n";
