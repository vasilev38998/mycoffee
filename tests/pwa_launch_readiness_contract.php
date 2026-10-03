<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$index=file_get_contents($root.'/customer/index.html');
$sw=file_get_contents($root.'/customer/sw.js');
$update=file_get_contents($root.'/customer/assets/pwa-update.js');
$launchCss=file_get_contents($root.'/customer/assets/launch-polish.css');
$standalone=file_get_contents($root.'/customer/assets/pwa-standalone.js');
$polish=file_get_contents($root.'/customer/assets/pwa-polish.js');
$offline=file_get_contents($root.'/customer/assets/offline-resilience.js');
$push=file_get_contents($root.'/customer/assets/push.js');
$manifest=file_get_contents($root.'/customer/api/customer_manifest.php');
$monitor=file_get_contents($root.'/.github/workflows/uptime-monitor.yml');

$checks=[
  'launch polish CSS is loaded and precached'=>str_contains($index,'assets/launch-polish.css?v=1')&&str_contains($sw,'./assets/launch-polish.css?v=1'),
  'forms avoid iOS focus zoom without disabling user zoom'=>str_contains($launchCss,'font-size:16px!important')&&!str_contains($standalone,'user-scalable=no')&&!str_contains($standalone,'maximum-scale=1'),
  'keyboard focus remains visible'=>str_contains($launchCss,':focus-visible')&&str_contains($launchCss,'outline:'),
  'reduced motion is respected'=>str_contains($launchCss,'prefers-reduced-motion:reduce'),
  'interactive controls keep practical touch targets'=>str_contains($launchCss,'min-width:44px')&&str_contains($launchCss,'min-height:44px'),
  'product dialog has dialog semantics'=>str_contains($index,'role="dialog"')&&str_contains($index,'aria-modal="true"')&&str_contains($index,'aria-labelledby="productName"'),
  'status and errors expose live regions'=>str_contains($index,'id="orderStatusStrip" role="status" aria-live="polite"')&&str_contains($index,'id="checkoutError" role="alert"'),
  'service worker stages updates'=>str_contains($sw,"const CACHE='kapouch-pwa-v61'")&&str_contains($sw,"const PREVIOUS_CACHE='kapouch-pwa-v60'")&&str_contains($sw,'precacheShell()')&&!str_contains($sw,'precacheShell().then(()=>self.skipWaiting())'),
  'update prompt can explicitly activate waiting worker'=>str_contains($update,"type:'SKIP_WAITING'")&&str_contains($sw,"event.data?.type==='SKIP_WAITING'")&&str_contains($index,'assets/pwa-update.js?v=1'),
  'navigation uses consistent cached shell'=>str_contains($sw,'async function navigationStrategy')&&str_contains($sw,'if(cached)return cached;'),
  'versioned app assets use cache-first stability'=>str_contains($sw,'async function cacheFirst')&&str_contains($sw,'if(inAppScope(url)){event.respondWith(cacheFirst(req,CACHE));}'),
  'slow catalog fallback does not falsely mark app offline'=>str_contains($sw,'setTimeout(()=>resolve(null),900)')&&str_contains($sw,'return cached;')&&str_contains($sw,'if(response.status>=500)return markOfflineJson(cached)')&&str_contains($sw,"network.catch(()=>notifyClients('catalog')"),
  'online startup clears sticky offline state'=>str_contains($offline,"sessionStorage.removeItem('kapouch_effective_offline')")&&str_contains($offline,'if(navigator.onLine)setTimeout(()=>probe(false),900)')&&str_contains($offline,"function acceptNetworkOk(){if(!navigator.onLine)return;setOffline(false,'')}"),
  'runtime observers are throttled'=>str_contains($polish,'requestAnimationFrame')&&str_contains($offline,'requestAnimationFrame'),
  'critical runtime layers are idempotent'=>str_contains($polish,'__KAPOUCH_PWA_POLISH_BOOTSTRAPPED')&&str_contains($offline,'__KAPOUCH_OFFLINE_BOOTSTRAPPED')&&str_contains($push,'__KAPOUCH_PUSH_BOOTSTRAPPED'),
  'push is not statically booted twice'=>!str_contains($index,'assets/push.js?v=2'),
  'manifest splash matches light PWA'=>str_contains($manifest,"'background_color'=>'#f7f1e8'")&&str_contains($manifest,"'theme_color'=>'#f7f1e8'"),
  'manifest provides launch shortcuts'=>str_contains($manifest,"'shortcuts'=>[")&&str_contains($manifest,"'url'=>'../#menu'")&&str_contains($manifest,"'url'=>'../#cart'"),
  'production uptime workflow covers both domains'=>str_contains($monitor,'https://kapouch.store/')&&str_contains($monitor,'https://app.kapouch.store/')&&str_contains($monitor,"cron: '*/5 * * * *'")&&str_contains($monitor,'openssl s_client')&&str_contains($monitor,'time_namelookup'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA launch readiness contract failed: {$label}\n");exit(1);}}
echo "PWA LAUNCH READINESS CONTRACT PASSED\n";
