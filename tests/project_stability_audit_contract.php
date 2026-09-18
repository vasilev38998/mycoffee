<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$updater=file_get_contents($root.'/inc/updater.php');
$db=file_get_contents($root.'/inc/db.php');
$settings=file_get_contents($root.'/inc/settings.php');
$image=file_get_contents($root.'/api/customer_product_image.php');
$orderApi=file_get_contents($root.'/api/customer_order.php');
$orders=file_get_contents($root.'/inc/customer_orders.php');
$gift=file_get_contents($root.'/inc/customer_same_order_gift.php');
$payments=file_get_contents($root.'/inc/customer_payments.php');
$push=file_get_contents($root.'/inc/customer_push.php');
$pushPage=file_get_contents($root.'/push_notifications.php');
$evotorPush=file_get_contents($root.'/inc/evotor_order_notifications.php');
$telegram=file_get_contents($root.'/inc/notifications.php');
$receiptProxy=file_get_contents($root.'/receipt_proverkacheka_proxy.php');
$automatic=file_get_contents($root.'/inc/automatic_expenses.php');
$inventory=file_get_contents($root.'/inc/inventory.php');
$htaccess=file_get_contents($root.'/.htaccess');

$migrations=glob($root.'/database/migrations/*.sql')?:[];
$numbers=[];
foreach($migrations as $file){if(preg_match('/^(\d+)_/',basename($file),$m))$numbers[]=(int)$m[1];}
$latest=$numbers?max($numbers):0;
$count=count($migrations);
preg_match('/const\s+KAPOUCH_SCHEMA_VERSION\s*=\s*(\d+)\s*;/',(string)$updater,$vm);
preg_match('/const\s+KAPOUCH_SCHEMA_MIGRATION_COUNT\s*=\s*(\d+)\s*;/',(string)$updater,$cm);

$checks=[
    'schema fast-path version matches latest migration'=>(int)($vm[1]??0)===$latest,
    'schema fast-path count matches migration file count'=>(int)($cm[1]??0)===$count,
    'database missing-table errors are explicit'=>str_contains($db,'function db_missing_table_error')&&str_contains($db,'===1146'),
    'product images bypass database bootstrap'=>!str_contains($image,"inc/bootstrap.php")&&!str_contains($image,'db()')&&str_contains($image,'Cache-Control: public, max-age=31536000, immutable'),
    'pickup checkout lock is filesystem-local'=>str_contains($orderApi,"kapouch_local_lock(\$lockPurpose)")&&!str_contains($orderApi,'kapouch_advisory_lock($lockPurpose'),
    'same-order gift lock is filesystem-local'=>str_contains($gift,"kapouch_local_lock(\$lockName)")&&!str_contains($gift,'GET_LOCK'),
    'checkout disconnects DB before YooKassa'=>str_contains($orders,'db_disconnect();')&&str_contains($orders,'customer_payment_create_sbp'),
    'payment status polling drops DB before provider HTTP'=>str_contains($orders,'customer_payment_yookassa_sync_by_provider_id')&&str_contains($orders,'$stmt=null;')&&str_contains($orders,'db_disconnect();'),
    'YooKassa network timeouts are bounded'=>str_contains($payments,'CURLOPT_CONNECTTIMEOUT=>5')&&str_contains($payments,'CURLOPT_TIMEOUT=>20'),
    'YooKassa sync/refund use local locks'=>str_contains($payments,"kapouch_local_lock('yookassa_payment_sync:")&&str_contains($payments,"kapouch_local_lock('yookassa_refund_order:")&&str_contains($payments,"kapouch_local_lock('yookassa_refund_sync:"),
    'YooKassa requests release DB before HTTP'=>substr_count($payments,'db_disconnect();')>=3,
    'Evotor push releases DB before HTTP'=>str_contains($evotorPush,'Do not occupy a MySQL slot while the Evotor cloud request is in flight')&&str_contains($evotorPush,'db_disconnect();'),
    'Telegram releases DB before HTTP'=>str_contains($telegram,'Do not hold MySQL while Telegram is slow or unreachable')&&str_contains($telegram,'db_disconnect();')&&str_contains($telegram,'CURLOPT_TIMEOUT=>15'),
    'receipt proxy releases DB before external provider'=>str_contains($receiptProxy,"db_disconnect())db_disconnect()")&&str_contains($receiptProxy,'CURLOPT_CONNECTTIMEOUT=>5')&&str_contains($receiptProxy,'CURLOPT_TIMEOUT=>20'),
    'automatic expenses no longer replay migration every call'=>str_contains($automatic,"SELECT id FROM automatic_expense_rules LIMIT 1")&&str_contains($automatic,'db_missing_table_error($e)'),
    'inventory no longer replays migration every call'=>str_contains($inventory,"SELECT id FROM inventory_movements LIMIT 1")&&str_contains($inventory,'db_missing_table_error($e)'),
    'settings are batch-loaded once per request'=>str_contains($settings,'function kapouch_load_app_settings')&&str_contains($settings,"SELECT setting_key,setting_value FROM app_settings")&&str_contains($settings,'function kapouch_load_system_meta'),
    'push queue is serialized locally'=>str_contains($push,"kapouch_local_lock('customer_push_process_queue')")&&str_contains($push,'min(20,$limit)'),
    'web push HTTP runs without retained DB handle'=>str_contains($push,'customer_push_send_subscription($sub,$payload,$vapidContext)')&&substr_count($push,'db_disconnect();')>=3,
    'bulk push campaign does not synchronously send 30 notifications'=>!str_contains($pushPage,'customer_push_process_queue(30)')&&str_contains($pushPage,'customer_push_process_queue(10)'),
    'internal source directories are web-denied'=>str_contains($htaccess,'tests|\\.github|evotor-app|node_modules|vendor')&&str_contains($htaccess,'database|docs|cron'),
];

foreach($checks as $label=>$ok){
    if(!$ok){fwrite(STDERR,"Project stability audit contract failed: {$label}\n");exit(1);}
}

echo "PROJECT STABILITY AUDIT CONTRACT PASSED\n";
