<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$updater=file_get_contents($root.'/inc/updater.php');
$updates=file_get_contents($root.'/updates.php');
$profile=file_get_contents($root.'/api/customer_profile.php');
$payments=file_get_contents($root.'/inc/customer_payments.php');
$return=file_get_contents($root.'/customer/payment-return.html');
$config=file_get_contents($root.'/customer/config.js');
$sw=file_get_contents($root.'/customer/sw.js');

$checks=[
    'migration lock is fail-fast'=>str_contains($updater,"SELECT GET_LOCK(?,0)")&&!str_contains($updater,"SELECT GET_LOCK(?,30)"),
    'up-to-date requests bypass migration lock'=>str_contains($updater,"if (!\$status['pending'] && !\$status['changed'])"),
    'migration registry avoids repeated DDL'=>str_contains($updater,"kapouch_table_exists(\$pdo,'schema_migrations')"),
    'failed migrations do not retry on public requests'=>str_contains($updater,'bool $retryFailed=false')&&str_contains($updater,"if(\$failed&&!\$retryFailed)"),
    'failed migration retry is owner initiated'=>str_contains($updates,'kapouch_apply_pending_migrations(db(),true,true)'),
    'profile does not double-refresh drink loyalty'=>substr_count($profile,'customer_drink_loyalty_refresh_customer($customerId)')===0&&str_contains($profile,'customer_loyalty_refresh_customer($customerId)'),
    'SBP return URL carries order tracking token'=>str_contains($payments,"'payment-return.html'.(\$trackingToken!==''?'?token='" )&&str_contains($payments,"SELECT tracking_token FROM customer_order_access WHERE order_id=?"),
    'payment return accepts token from query'=>str_contains($return,"params.get('token')")&&str_contains($return,"localStorage.setItem('kapouch_tracking_token',queryToken)"),
    'payment return scrubs token from address bar'=>str_contains($return,'history.replaceState'),
    'profile fetch circuit breaker is enabled'=>str_contains($config,'profileBlockedUntil')&&str_contains($config,'customer_profile.php'),
    'PWA shell cache bumped'=>str_contains($sw,"kapouch-pwa-v38"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Hosting/SBP contract failed: {$label}\n");exit(1);}}
echo "HOSTING STABILITY / SBP RETURN CONTRACT PASSED\n";
