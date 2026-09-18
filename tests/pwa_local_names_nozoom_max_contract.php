<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$migration=file_get_contents($root.'/database/migrations/039_customer_pwa_local_names.sql');
$admin=file_get_contents($root.'/customer_app.php');
$pwa=file_get_contents($root.'/inc/customer_pwa.php');
$config=file_get_contents($root.'/customer/config.js');
$standalone=file_get_contents($root.'/customer/assets/pwa-standalone.js');
$index=file_get_contents($root.'/customer/index.html');
$sw=file_get_contents($root.'/customer/sw.js');

$checks=[
    'migration adds PWA-only display name'=>str_contains($migration,'ADD COLUMN display_name VARCHAR(255)'),
    'admin saves local display names'=>str_contains($admin,'display_name,category_id')&&str_contains($admin,'name="display_name[')&&str_contains($admin,'Название в PWA'),
    'admin explicitly keeps Evotor names unchanged'=>str_contains($admin,'Названия Evotor не меняются')&&str_contains($admin,'Пусто = название Evotor'),
    'catalog uses local name with Evotor fallback'=>str_contains($pwa,"product_display_name")&&str_contains($pwa,"\$displayName=trim((string)(\$r['display_name']??''))?:trim((string)\$r['name'])"),
    'catalog never rewrites product source names'=>!str_contains($pwa,'UPDATE products SET name')&&!str_contains($admin,'UPDATE products SET name'),
    'MAX is configurable in admin'=>str_contains($admin,'customer_max_url')&&str_contains($admin,'>MAX<')&&str_contains($pwa,"'max_url'=>(string)app_setting('customer_max_url','')"),
    'zoom lock only activates in standalone mode'=>str_contains($standalone,"matchMedia('(display-mode: standalone)')")&&str_contains($standalone,'window.navigator.standalone===true')&&str_contains($standalone,'if(!standalone)return;'),
    'standalone viewport disables zoom'=>str_contains($standalone,'maximum-scale=1,user-scalable=no')&&str_contains($standalone,"'gesturestart','gesturechange','gestureend'"),
    'standalone script does not globally block touchend'=>!str_contains($standalone,"addEventListener('touchend'")&&!str_contains($standalone,'addEventListener("touchend"'),
    'catalog response is published once for addons'=>str_contains($config,'KAPOUCH_CATALOG_SHOP')&&str_contains($config,"'kapouch:catalog'")&&str_contains($config,'response.clone().json()'),
    'MAX link reuses catalog response without extra API request'=>str_contains($standalone,'KAPOUCH_CATALOG_SHOP')&&str_contains($standalone,"'kapouch:catalog'")&&str_contains($standalone,"link.textContent='MAX →'")&&!str_contains($standalone,'customer_catalog.php'),
    'PWA shell loads standalone layer'=>str_contains($index,'assets/pwa-standalone.js?v=1'),
    'service worker shell bumped and caches standalone layer'=>str_contains($sw,"kapouch-pwa-v43")&&str_contains($sw,'./assets/pwa-standalone.js?v=1'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA local names/no-zoom/MAX contract failed: {$label}\n");exit(1);}}
echo "PWA LOCAL NAMES / NO-ZOOM / MAX CONTRACT PASSED\n";
