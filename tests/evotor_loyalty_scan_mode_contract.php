<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$manifest=file_get_contents($root.'/evotor-app/app/src/main/AndroidManifest.xml');
$sell=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/KapouchSellIntegrationService.java');
$action=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/KapouchCustomerCardActionService.java');
$scan=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/CustomerCardScanActivity.java');
$receiver=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/CustomerScanReceiver.java');
$build=file_get_contents($root.'/evotor-app/app/build.gradle');

$checks=[
    'Evotor app version bumped for dedicated scanner'=>str_contains($build,'versionCode 38')&&str_contains($build,"versionName '1.2.31'"),
    'dedicated customer scan activity is private'=>str_contains($manifest,'android:name=".CustomerCardScanActivity"')&&str_contains($manifest,'android:exported="false"'),
    'payment screen exposes Kapouch action'=>str_contains($manifest,'android:name=".KapouchCustomerCardActionService"')&&str_contains($manifest,'ru.evotor.event.sell.DISCOUNT_SCREEN_ADDITIONAL_ITEMS')&&str_contains($manifest,'android.intent.category.DEFAULT'),
    'payment action works on legacy Evotor integration API'=>str_contains($action,'extends IntegrationService')&&str_contains($action,'ACTION_CUSTOMER_CARD')&&str_contains($action,'callback.startActivity(')&&str_contains($action,'CustomerCardScanActivity.class'),
    'dedicated mode completes integration request'=>str_contains($scan,'extends IntegrationActivity')&&str_contains($scan,'setIntegrationResult(new Bundle())'),
    'dedicated mode listens to physical scanner directly'=>str_contains($scan,'CustomerScanReceiver.ACTION_SCANNED')&&str_contains($scan,'ru.evotor.devices.SCANNER_SENDER')&&str_contains($scan,'registerReceiver('),
    'dedicated mode only accepts Kapouch QR through shared receiver'=>str_contains($scan,'new CustomerScanReceiver(')&&str_contains($receiver,'code.startsWith(KAPOUCH_PREFIX)'),
    'successful dedicated scan returns to Evotor'=>str_contains($scan,'stopScanner();')&&str_contains($scan,'CustomerCardScanActivity.this::finish'),
    'ordinary product barcodes remain transparent to Kapouch'=>str_contains($sell,'!code.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)')&&str_contains($sell,'return null;'),
    'legacy sell integration does not require unavailable modern event class'=>!str_contains($sell,'DiscountScreenAdditionalItemsEvent'),
];
foreach($checks as $label=>$ok){
    if(!$ok){fwrite(STDERR,"Evotor loyalty scan mode contract failed: {$label}\n");exit(1);}
}
echo "EVOTOR LOYALTY SCAN MODE CONTRACT PASSED\n";
