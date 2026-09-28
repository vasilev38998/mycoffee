<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$profile=file_get_contents($root.'/customer/assets/profile-plus.js');
$avatar=file_get_contents($root.'/customer/assets/profile-avatar.js');
$loyalty=file_get_contents($root.'/customer/assets/loyalty-card.js');
$status=file_get_contents($root.'/customer/assets/status-once.js');
$authCall=file_get_contents($root.'/customer/assets/auth-call.js');
$config=file_get_contents($root.'/customer/config.js');
$sw=file_get_contents($root.'/customer/sw.js');
$offline=file_get_contents($root.'/customer/assets/offline-resilience.js');
$pwaNext=file_get_contents($root.'/customer/assets/pwa-next.js');
$pwaPolish=file_get_contents($root.'/customer/assets/pwa-polish.js');
$currentOrder=file_get_contents($root.'/customer/assets/current-order.js');
$personalization=file_get_contents($root.'/customer/assets/personalization.js');
$contrast=file_get_contents($root.'/customer/assets/contrast-fix.css');
$manifest=file_get_contents($root.'/api/customer_manifest.php');
preg_match('/function sanitizeProfile\(profile\)(.*?)function sanitizeLoyalty/s',$config,$snapshotMatch);
$profileSnapshotBlock=$snapshotMatch[1]??'';

$checks=[
    'separate profile data dropdown'=>str_contains($profile,"id='profileDataFold'")||str_contains($profile,"'profileDataFold'"),
    'separate profile statistics dropdown'=>str_contains($profile,"id='profileStatsFold'")||str_contains($profile,"'profileStatsFold'"),
    'old combined profile dropdown removed'=>!str_contains($profile,'Данные и статистика'),
    'avatar controls live inside My data'=>str_contains($avatar,"#profileDataFold .profile-data-content")&&str_contains($avatar,"dataContent.insertBefore(card"),
    'avatar is propagated to initial-letter surfaces'=>str_contains($avatar,"document.querySelector('.home-avatar')")&&str_contains($avatar,"document.querySelectorAll('[data-customer-avatar]')")&&str_contains($avatar,'syncGlobalAvatars()'),
    'avatar preview has no inherited button padding'=>str_contains($avatar,'padding:0!important')&&str_contains($avatar,'object-fit:cover!important')&&str_contains($avatar,'object-position:50% 50%!important'),
    'rectangular avatar is center-cropped to square'=>str_contains($avatar,'side=Math.min(sw,sh)')&&str_contains($avatar,'(sw-side)/2')&&str_contains($avatar,'(sh-side)/2')&&str_contains($avatar,'canvas.width=size;canvas.height=size'),
    'QR addressed to barista'=>str_contains($loyalty,'Покажите QR-код бариста'),
    'loyalty card can show safe offline snapshot'=>str_contains($loyalty,'Офлайн · ')&&str_contains($config,'kapouch_offline_loyalty_v1')&&str_contains($config,'sanitizeLoyalty'),
    'old scanner copy removed'=>!str_contains($loyalty,'Покажи QR сканеру на кассе'),
    'one-time notice storage'=>str_contains($status,'kapouch_status_strip_seen_v1'),
    'one-time completed notice'=>str_contains($status,'бонусы начислены'),
    'one-time ready notice'=>str_contains($status,'можно\\s+забирать'),
    'one-time notice auto hide'=>str_contains($status,'DISPLAY_MS=8000'),
    'status-once asset loaded'=>str_contains($config,'assets/status-once.js?v=1'),
    'fresh QR asset loaded'=>str_contains($config,'assets/loyalty-card.js?v=6'),
    'light contrast layer loaded'=>str_contains($config,'assets/contrast-fix.css?v=1'),
    'light tokens locked against legacy theme'=>str_contains($contrast,'--text:#251812!important')&&str_contains($contrast,'--surface:#fffaf4!important'),
    'auth fallback explainer removed'=>!str_contains($authCall,'Резервный способ без SMS')&&str_contains($authCall,"selfStart.textContent='Позвонить самому'"),
    'guest home empty card is hidden'=>str_contains($config,'#quickRepeatCard[hidden]')&&str_contains($config,'#quickRepeatCard:empty'),
    'home bonus card stays removed'=>str_contains($config,'#balanceCard{display:none!important}'),
    'stale auth helper is hidden'=>str_contains($config,'.auth-alt-hint{display:none!important}'),
    'fresh call auth asset loaded'=>str_contains($config,'assets/auth-call.js?v=3'),
    'fresh home polish asset loaded'=>str_contains($config,'assets/pwa-polish.js?v=3'),
    'offline resilience module is loaded and precached'=>str_contains($config,"offline.src='assets/offline-resilience.js?v=1'")&&str_contains($sw,'./assets/offline-resilience.js?v=1'),
    'offline screen uses requested customer copy'=>str_contains($offline,'Нет подключения')&&str_contains($offline,'Меню и сохранённые данные всё ещё доступны.')&&str_contains($offline,'Заказ требует подключения к интернету.')&&str_contains($offline,'Повторить'),
    'checkout is blocked offline while cart remains local'=>str_contains($offline,'Нужен интернет для заказа')&&str_contains($offline,"form.addEventListener('submit'")&&str_contains($personalization,'kapouch_customer_cart'),
    'favorites are stored locally'=>str_contains($personalization,"FAVORITES_KEY='kapouch_customer_favorites'")&&str_contains($personalization,'localStorage.setItem(FAVORITES_KEY'),
    'profile snapshot excludes phone email and birthday'=>$profileSnapshotBlock!==''&&!str_contains($profileSnapshotBlock,'phone')&&!str_contains($profileSnapshotBlock,'email')&&!str_contains($profileSnapshotBlock,'birth_date'),
    'recent orders and loyalty are stored in bounded offline profile snapshot'=>str_contains($profileSnapshotBlock,'slice(0,12)')&&str_contains($profileSnapshotBlock,'orders:')&&str_contains($profileSnapshotBlock,'loyalty:'),
    'current order keeps a last-known offline snapshot'=>str_contains($currentOrder,"SNAPSHOT_KEY='kapouch_current_order_snapshot_v1'")&&str_contains($currentOrder,'последний сохранённый статус'),
    'service worker keeps a normalized catalog snapshot'=>str_contains($sw,"CATALOG_KEY=new URL('./__offline/catalog.json'")&&str_contains($sw,'catalogStrategy')&&str_contains($sw,'fetchWithTimeout'),
    'service worker precaches popular product images'=>str_contains($sw,'prefetchPopularImages')&&str_contains($sw,'IMAGE_CACHE')&&str_contains($sw,'featured'),
    'service worker never caches private customer APIs'=>str_contains($sw,'isPrivateApi')&&str_contains($sw,'if(isPrivateApi(url))return;'),
    'QR library is runtime cached for offline card rendering'=>str_contains($sw,'qrcodejs/1.0.0/qrcode.min.js')&&str_contains($sw,'THIRD_PARTY_CACHE'),
    'service worker cache remains compatible with installed PWA'=>str_contains($sw,"kapouch-pwa-v58")&&str_contains($sw,'./assets/contrast-fix.css?v=1')&&str_contains($sw,'./assets/status-once.js?v=1')&&str_contains($sw,'./assets/hero-cup.svg?v=2'),
    'smart cart keeps a shadow copy and held unavailable lines'=>str_contains($pwaNext,"SHADOW_KEY='kapouch_cart_shadow_v1'")&&str_contains($pwaNext,"HELD_KEY='kapouch_cart_held_v1'")&&str_contains($pwaNext,'preserveDropped'),
    'cart price changes are compared before checkout'=>str_contains($pwaNext,"BASELINE_KEY='kapouch_cart_price_baseline_v1'")&&str_contains($pwaNext,'comparePrices')&&str_contains($pwaNext,'Цены в корзине изменились'),
    'offline search is explicitly available from cached menu'=>str_contains($pwaNext,"dataset.offlineSearchReady='1'")&&str_contains($pwaNext,'поиск работает по сохранённому меню'),
    'slow connection mode avoids aggressive image prefetch'=>str_contains($pwaNext,'Медленное соединение')&&str_contains($pwaNext,'effectiveType')&&str_contains($sw,'preferLite()')&&str_contains($sw,'if(preferLite())return;'),
    'PWA update is controlled by user'=>str_contains($pwaNext,'Доступно обновление Kapouch')&&str_contains($pwaNext,"waiting.postMessage({type:'SKIP_WAITING'})")&&str_contains($sw,"event.data?.type==='SKIP_WAITING'")&&!str_contains($sw,'cache.addAll(SHELL)).then(()=>self.skipWaiting())'),
    'resilience plus module is loaded and precached'=>str_contains($pwaPolish,"script.src='assets/pwa-next.js?v=1'")&&str_contains($sw,'./assets/pwa-next.js?v=1'),
    'ready order can set an app badge'=>str_contains($pwaNext,"status==='ready'")&&str_contains($pwaNext,'navigator.setAppBadge(1)'),
    'manifest offers menu cart and profile shortcuts'=>str_contains($manifest,"'shortcuts'=>[")&&str_contains($manifest,"'url'=>'../customer/#menu'")&&str_contains($manifest,"'url'=>'../customer/#cart'")&&str_contains($manifest,"'url'=>'../customer/#profile'"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA profile UX contract failed: {$label}\n");exit(1);}}
echo "PWA profile UX contract passed\n";
