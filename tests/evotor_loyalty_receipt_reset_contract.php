<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$manifest=file_get_contents($root.'/evotor-app/app/src/main/AndroidManifest.xml');
$session=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/CustomerReceiptSession.java');
$receiver=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/ReceiptSessionReceiver.java');
$discount=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/KapouchDiscountService.java');
$trigger=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/ReceiptDiscountTrigger.java');
$build=file_get_contents($root.'/evotor-app/app/build.gradle');

$checks=[
    'Evotor app version bumped for terminal update'=>str_contains($build,'versionCode 37')&&str_contains($build,"versionName '1.2.30'"),
    'receipt lifecycle receiver is registered'=>str_contains($manifest,'android:name=".ReceiptSessionReceiver"'),
    'closed receipt clears loyalty session'=>str_contains($manifest,'evotor.intent.action.receipt.sell.RECEIPT_CLOSED')&&str_contains($receiver,'CustomerReceiptSession.clear'),
    'manually cleared receipt clears loyalty session'=>str_contains($manifest,'evotor.intent.action.receipt.sell.CLEARED'),
    'session removes all customer identity fields'=>str_contains($session,'.remove(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_NAME)')&&str_contains($session,'.remove(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_BALANCE)')&&str_contains($session,'.remove(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_CODE)')&&str_contains($session,'.remove(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_AT)')&&str_contains($session,'.remove(KEY_ACTIVE_RECEIPT_UUID)'),
    'saved card is bound to one receipt uuid'=>str_contains($session,'KEY_ACTIVE_RECEIPT_UUID')&&str_contains($session,'!normalizedReceiptUuid.equals(storedReceiptUuid)')&&str_contains($session,'clear(context);'),
    'discount service validates current receipt session'=>str_contains($discount,'CustomerReceiptSession.activeCodeForReceipt')&&!str_contains($discount,'recentSavedCode()'),
    'Evotor event cannot revive a different stale card'=>str_contains($discount,'!eventCode.equals(loyaltyCode)')&&str_contains($discount,'callback.skip();'),
    'scan-trigger binds card before discount calculation'=>str_contains($trigger,'CustomerReceiptSession.bindToCurrentReceipt(app, loyaltyCode)'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Evotor loyalty receipt reset contract failed: {$label}\n");exit(1);}}
echo "EVOTOR LOYALTY RECEIPT RESET CONTRACT PASSED\n";
