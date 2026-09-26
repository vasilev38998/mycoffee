<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$config=file_get_contents($root.'/customer/config.js');
$saved=file_get_contents($root.'/customer/assets/saved-order.js');
$countdown=file_get_contents($root.'/customer/assets/pickup-countdown.js');
$current=file_get_contents($root.'/customer/assets/current-order.js');
$statusApi=file_get_contents($root.'/api/customer_order_status.php');
$sw=file_get_contents($root.'/customer/sw.js');
$checks=[
    'saved order module is loaded'=>str_contains($config,"savedOrder.src='assets/saved-order.js?v=1'"),
    'preset uses dedicated versioned storage'=>str_contains($saved,"PRESET_KEY='kapouch_saved_order_v1'")&&str_contains($saved,'version:1'),
    'current cart can be saved and restored'=>str_contains($saved,'function saveCurrent()')&&str_contains($saved,'async function loadPreset()'),
    'restore validates current catalog'=>str_contains($saved,'customer_catalog.php?saved_order=')&&str_contains($saved,'function catalogRules(data)'),
    'stale products and modifiers are filtered'=>str_contains($saved,'if(!allowed){changed=true;continue}')&&str_contains($saved,'filter(id=>allowed.has(id))'),
    'home quick order is conditional'=>str_contains($saved,"card.id='savedOrderCard'")&&str_contains($saved,"if(!preset){card.hidden=true"),
    'cart offers save update delete controls'=>str_contains($saved,'Обновить «Мой обычный»')&&str_contains($saved,'Сохранить любимый заказ')&&str_contains($saved,'Удалить сохранённый'),
    'pickup countdown module is loaded'=>str_contains($config,"pickupCountdown.src='assets/pickup-countdown.js?v=1'"),
    'active order emits status for countdown'=>str_contains($current,"new CustomEvent('kapouch-order-status'")&&str_contains($countdown,"window.addEventListener('kapouch-order-status'"),
    'status API exposes promised pickup time'=>str_contains($statusApi,"['promised_at']")&&str_contains($statusApi,"['promised_display']"),
    'countdown updates locally without extra polling'=>str_contains($countdown,'setTimeout(render,30000)')&&!str_contains($countdown,'fetch('),
    'ready state has explicit pickup message'=>str_contains($countdown,"order.status==='ready'")&&str_contains($countdown,'Можно забирать сейчас'),
    'service worker cache is refreshed'=>str_contains($sw,"const CACHE='kapouch-pwa-v55'")&&str_contains($sw,"./assets/saved-order.js?v=1")&&str_contains($sw,"./assets/pickup-countdown.js?v=1"),
    'convenience assets are network first'=>str_contains($sw,"url.pathname.endsWith('/config.js')")&&str_contains($sw,"url.pathname.endsWith('/assets/saved-order.js')")&&str_contains($sw,"url.pathname.endsWith('/assets/pickup-countdown.js')"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA convenience contract failed: {$label}\n");exit(1);}echo "OK: {$label}\n";}
echo "PWA CONVENIENCE FEATURES CONTRACT PASSED\n";
