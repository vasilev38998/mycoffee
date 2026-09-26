<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$config=file_get_contents($root.'/customer/config.js');
$feature=file_get_contents($root.'/customer/assets/saved-order.js');
$sw=file_get_contents($root.'/customer/sw.js');
$checks=[
    'saved order module is loaded'=>str_contains($config,"savedOrder.src='assets/saved-order.js?v=1'"),
    'preset uses dedicated versioned storage'=>str_contains($feature,"PRESET_KEY='kapouch_saved_order_v1'")&&str_contains($feature,'version:1'),
    'current cart can be saved and restored'=>str_contains($feature,'function saveCurrent()')&&str_contains($feature,'async function loadPreset()'),
    'restore validates current catalog'=>str_contains($feature,'customer_catalog.php?saved_order=')&&str_contains($feature,'function catalogRules(data)'),
    'stale products and modifiers are filtered'=>str_contains($feature,'if(!allowed){changed=true;continue}')&&str_contains($feature,'filter(id=>allowed.has(id))'),
    'home quick order is compact and conditional'=>str_contains($feature,'id=\'savedOrderCard\'')===false&&str_contains($feature,"card.id='savedOrderCard'")&&str_contains($feature,"if(!preset){card.hidden=true"),
    'cart offers save update delete controls'=>str_contains($feature,'Обновить «Мой обычный»')&&str_contains($feature,'Сохранить любимый заказ')&&str_contains($feature,'Удалить сохранённый'),
    'service worker cache is refreshed'=>str_contains($sw,"const CACHE='kapouch-pwa-v54'")&&str_contains($sw,"./assets/saved-order.js?v=1"),
    'config and feature are network first'=>str_contains($sw,"url.pathname.endsWith('/config.js')")&&str_contains($sw,"url.pathname.endsWith('/assets/saved-order.js')"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA saved order contract failed: {$label}\n");exit(1);}echo "OK: {$label}\n";}
echo "PWA SAVED ORDER CONTRACT PASSED\n";
