<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$db=file_get_contents($root.'/inc/db.php');
$api=file_get_contents($root.'/inc/customer_api.php');
$externalApi=file_get_contents($root.'/api_online_orders.php');
$runtime=file_get_contents($root.'/inc/customer_loyalty_runtime.php');
$drink=file_get_contents($root.'/inc/customer_drink_loyalty.php');
$profile=file_get_contents($root.'/api/customer_profile.php');
$card=file_get_contents($root.'/api/customer_loyalty_card.php');
$quote=file_get_contents($root.'/api/customer_order_quote.php');
$status=file_get_contents($root.'/api/customer_order_status.php');

$checks=[
    'db exposes cross-catch capacity state'=>str_contains($db,'function db_capacity_active(): bool')&&str_contains($db,"kapouch_db_capacity_active']=true")&&str_contains($db,'db_capacity_cooldown_remaining()>0'),
    'customer JSON errors preserve retryable DB capacity status'=>str_contains($api,'status>=400')&&str_contains($api,'db_capacity_active()')&&str_contains($api,'status=503')&&str_contains($api,"header('Retry-After: '"),
    'external orders API preserves retryable DB capacity status'=>str_contains($externalApi,'db_capacity_active()')&&str_contains($externalApi,'status=503')&&str_contains($externalApi,"header('Retry-After: '"),
    'loyalty reconciliation is coalesced per customer'=>str_contains($runtime,'function customer_loyalty_refresh_customer_if_due')&&str_contains($runtime,"kapouch_local_lock('customer_loyalty_refresh:'")&&str_contains($runtime,'customer_loyalty_refresh_recent($customerId,$minInterval)'),
    'profile uses coalesced loyalty reconciliation'=>str_contains($profile,'customer_loyalty_refresh_customer_if_due($customerId,30,20)')&&!str_contains($profile,'customer_loyalty_refresh_customer($customerId);'),
    'loyalty card avoids duplicate drink reconciliation'=>str_contains($card,'customer_loyalty_refresh_customer_if_due($customerId,30,20)')&&!str_contains($card,'customer_drink_loyalty_refresh_customer($customerId)'),
    'cart quote does not replay 100 historical rows every change'=>str_contains($quote,'customer_loyalty_refresh_customer_if_due($customerId,30,20)')&&!str_contains($quote,'customer_loyalty_refresh_customer($customerId,100)'),
    'status poll does not double-credit sixth drink'=>str_contains($status,'customer_loyalty_on_order_completed($orderId)')&&!str_contains($status,'customer_drink_loyalty_credit_online_order('),
    'drink loyalty settings are cached per request'=>str_contains($drink,'kapouch_drink_loyalty_settings_cache'),
    'drink loyalty product rows are cached per request'=>str_contains($drink,'kapouch_drink_loyalty_rows_cache'),
    'drink loyalty eligibility map is cached per request'=>str_contains($drink,'kapouch_drink_loyalty_map_cache'),
    'drink loyalty reference product caches null and values'=>str_contains($drink,'kapouch_drink_loyalty_reference_loaded')&&str_contains($drink,'kapouch_drink_loyalty_reference_cache'),
    'drink loyalty cache can be reset explicitly'=>str_contains($drink,'function customer_drink_loyalty_runtime_cache_reset(): void'),
];

foreach($checks as $label=>$ok){
    if(!$ok){fwrite(STDERR,"Full project checkup v2 contract failed: {$label}\n");exit(1);}
}

echo "FULL PROJECT CHECKUP V2 CONTRACT PASSED\n";
