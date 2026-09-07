<?php
declare(strict_types=1);

function evotor_queue_contract_fail(string $message): void
{
    fwrite(STDERR,"ERROR: {$message}\n");
    exit(1);
}

function evotor_queue_contract_require(bool $condition,string $message): void
{
    if(!$condition)evotor_queue_contract_fail($message);
}

$root=dirname(__DIR__);
$notifications=file_get_contents($root.'/inc/evotor_order_notifications.php');
$checkout=file_get_contents($root.'/api/customer_order.php');
$webhook=file_get_contents($root.'/api/customer_payment_yookassa_webhook.php');

foreach(['notifications'=>$notifications,'checkout'=>$checkout,'webhook'=>$webhook] as $name=>$source){
    evotor_queue_contract_require(is_string($source)&&$source!=='',"cannot read {$name} source");
}

$notifyStart=strpos($notifications,'function evotor_order_notify_new');
$deferStart=strpos($notifications,'function evotor_order_push_defer');
evotor_queue_contract_require($notifyStart!==false&&$deferStart!==false&&$deferStart>$notifyStart,'queue/defer functions are missing or reordered unexpectedly');
$notifyBody=substr($notifications,$notifyStart,$deferStart-$notifyStart);

evotor_queue_contract_require(!str_contains($notifyBody,'evotor_order_push_dispatch_log('),'evotor_order_notify_new must enqueue only and never call the external push transport');
evotor_queue_contract_require(str_contains($notifyBody,"'log_ids'"),'enqueue result must expose log_ids for post-response dispatch');
evotor_queue_contract_require(str_contains($notifications,'function evotor_order_push_defer'),'post-response Evotor dispatch helper is missing');
evotor_queue_contract_require(str_contains($notifications,'fastcgi_finish_request'),'deferred push must flush the HTTP response before network delivery');
evotor_queue_contract_require(str_contains($notifications,'attempts=attempts+1'),'push dispatch must atomically claim an attempt');
evotor_queue_contract_require(str_contains($notifications,"attempts=? AND attempts<5"),'atomic claim must reject stale/duplicate workers');
evotor_queue_contract_require(str_contains($notifications,"INTERVAL 6 HOUR"),'retry horizon must preserve failed notifications beyond the old 30-minute window');
evotor_queue_contract_require(str_contains($notifications,"INTERVAL 60 MINUTE"),'retry schedule must include backoff for the final attempt');
evotor_queue_contract_require(str_contains($notifications,"return 'https://kapouch.store/api/evotor_order_action.php';"),'server action URL must match the Android allowlist exactly');
evotor_queue_contract_require(str_contains($checkout,'evotor_order_push_defer'),'checkout must defer Evotor delivery until after its response');
evotor_queue_contract_require(str_contains($webhook,'evotor_order_push_defer'),'payment webhook must defer Evotor delivery until after its response');

echo "Evotor order push queue contract OK\n";
