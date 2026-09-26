<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$db=file_get_contents($root.'/inc/db.php');
$access=file_get_contents($root.'/inc/access.php');
$htaccess=file_get_contents($root.'/.htaccess');
$discount=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/LoyaltyDiscountApi.java');
$discountApi=file_get_contents($root.'/api/evotor_loyalty_discount.php');
$drink=file_get_contents($root.'/inc/customer_drink_loyalty.php');
$evotorLoyalty=file_get_contents($root.'/inc/evotor_customer_loyalty.php');
$build=file_get_contents($root.'/evotor-app/app/build.gradle');
$config=file_get_contents($root.'/customer/config.js');
$standalone=file_get_contents($root.'/customer/assets/pwa-standalone.js');
$sw=file_get_contents($root.'/customer/sw.js');

$checks=[
    'db capacity cooldown has process-independent file gate'=>str_contains($db,'db_capacity_cooldown_file')&&str_contains($db,'kapouch_db_capacity_until')&&str_contains($db,'db_capacity_mark_busy(20)'),
    'db cooldown is classified as capacity failure'=>str_contains($db,'KapouchDatabaseBusyException')&&str_contains($db,'if($e instanceof KapouchDatabaseBusyException)return true;'),
    'successful db connection clears cooldown'=>str_contains($db,'db_capacity_clear_busy();'),
    'Evotor loyalty discount endpoint is public and sessionless'=>str_contains($access,"'evotor_loyalty_discount.php'")&&str_contains($access,'kapouch_sessionless_pages'),
    'Evotor discount has extensionless Apache route'=>str_contains($htaccess,'evotor-loyalty-discount')&&str_contains($htaccess,'api/evotor_loyalty_discount.php'),
    'Evotor APK uses extensionless discount transport'=>str_contains($discount,'https://kapouch.store/evotor-loyalty-discount')&&!str_contains($discount,'https://kapouch.store/api/evotor_loyalty_discount.php'),
    'Evotor APK bumped for terminal install'=>str_contains($build,'versionCode 35')&&str_contains($build,"versionName '1.2.28'")&&str_contains($discount,'Kapouch-Orders-Evotor/1.2.28'),
    'Evotor discount quote coalesces loyalty refresh'=>str_contains($discountApi,'customer_loyalty_refresh_customer_if_due($customerId,20,15)')&&!str_contains($discountApi,'customer_drink_loyalty_refresh_customer($customerId,100)'),
    'automatic gift reference prefers current Evotor product'=>str_contains($drink,'evotor_links')&&str_contains($drink,'$mapped>$bestMapped')&&str_contains($drink,"$price>(float)$best['price']"),
    'PAYBACK base document is parsed'=>str_contains($evotorLoyalty,'base_document_id')&&str_contains($evotorLoyalty,'evotor_customer_loyalty_payback_base_document_id'),
    'PAYBACK reverses customer loyalty and drink state'=>str_contains($evotorLoyalty,'evotor_customer_loyalty_attach_payback')&&str_contains($evotorLoyalty,'customer_drink_loyalty_refund_evotor_sale')&&str_contains($evotorLoyalty,"gross_amount<0")&&str_contains($evotorLoyalty,"operation_type,note) VALUES(?,NULL,?,'adjust',?)"),
    'historical drink refresh ignores refund linkage rows'=>str_contains($drink,'cs.gross_amount>0'),
    'PWA publishes catalog response to addons'=>str_contains($config,'KAPOUCH_CATALOG_SHOP')&&str_contains($config,"'kapouch:catalog'"),
    'MAX addon does not issue a second catalog request'=>!str_contains($standalone,'customer_catalog.php')&&str_contains($standalone,'KAPOUCH_CATALOG_SHOP'),
    'service worker keeps last good catalog on 5xx'=>str_contains($sw,"kapouch-pwa-v43")&&str_contains($sw,'if(res.ok){const copy=res.clone()')&&str_contains($sw,'cached=>cached||res'),
];

foreach($checks as $label=>$ok){
    if(!$ok){fwrite(STDERR,"Runtime stability/Evotor contract failed: {$label}\n");exit(1);}
}
echo "RUNTIME STABILITY / EVOTOR CONTRACT PASSED\n";
