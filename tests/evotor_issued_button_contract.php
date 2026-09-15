<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$main=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/MainActivity.java');
$store=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/OrderStore.java');
$api=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/OrderApi.java');
$endpoint=file_get_contents($root.'/api/evotor_order_action.php');
$bridge=file_get_contents($root.'/api/evotor_bridge.php');
$actions=file_get_contents($root.'/inc/evotor_order_terminal_actions.php');

$checks=[
    'ready order renders issued button'=>str_contains($main,'actionButton(order, "complete", "ВЫДАН"'),
    'ready order keeps action token'=>str_contains($store,'"completed".equals(record.status) || "cancelled".equals(record.status)')&&!str_contains($store,'"ready".equals(record.status) || "completed".equals(record.status)'),
    'android API accepts complete'=>str_contains($api,'!"complete".equals(action)'),
    'primary endpoint accepts complete'=>str_contains($endpoint,"['accept','ready','complete']")&&str_contains($endpoint,'evotor_order_terminal_action_apply'),
    'cookie bridge accepts complete'=>str_contains($bridge,"['accept','ready','complete']")&&str_contains($bridge,'evotor_order_terminal_action_apply'),
    'complete moves ready to completed'=>str_contains($actions,"if(\$status==='ready')online_orders_transition(\$orderId,'completed')"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Evotor issued-button contract failed: {$label}\n");exit(1);}}
echo "EVOTOR ISSUED BUTTON CONTRACT PASSED\n";
