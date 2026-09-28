const CACHE='kapouch-pwa-v58';
const DATA_CACHE='kapouch-data-v1';
const IMAGE_CACHE='kapouch-images-v1';
const THIRD_PARTY_CACHE='kapouch-third-party-v1';
const LEGACY_ROOT_SCOPE_CACHE='kapouch-pwa-v28';
const CURRENT_CACHES=new Set([CACHE,DATA_CACHE,IMAGE_CACHE,THIRD_PARTY_CACHE]);
const SHELL=['./','./index.html','./legal.html','./payment-return.html','./config.js?v=13','./assets/app.css?v=5','./assets/variants.css?v=1','./assets/pwa-v2.css?v=1','./assets/pwa-v3.css?v=1','./assets/contrast-fix.css?v=1','./assets/pwa-polish.css?v=2','./assets/modifiers.css?v=3','./assets/payments.css?v=1','./assets/legal.css?v=2','./assets/redesign-v1.css?v=1','./assets/redesign-v2.css?v=2','./assets/redesign-v2-modules.css?v=2','./assets/redesign-v3-fixes.css?v=1','./assets/redesign-v4-polish.css?v=1','./assets/redesign-v4-polish.css?v=2','./assets/auth-required.css?v=1','./assets/sixth-drink-checkout.css?v=4','./assets/product-disclaimer.css?v=1','./assets/app.js?v=11','./assets/profile-plus.js?v=2','./assets/profile-compact.js?v=2','./assets/profile-avatar.js?v=2','./assets/loyalty-card.js?v=6','./assets/offline-resilience.js?v=1','./assets/status-once.js?v=1','./assets/phone-mask.js?v=1','./assets/auth-required.js?v=1','./assets/sixth-drink-checkout.js?v=7','./assets/product-disclaimer.js?v=1','./assets/pwa-polish.js?v=3','./assets/pwa-standalone.js?v=1','./assets/hero-cup.svg?v=2','./assets/hero-cup.svg?v=3','./assets/modifier-price-ui.js?v=1','./assets/payments.js?v=9','./assets/personalization.js?v=2','./assets/current-order.js?v=2','./assets/growth-suite.js?v=3','./assets/push.js?v=2','./assets/legal.js?v=4','./assets/redesign-v1.js?v=3','./assets/saved-order.js?v=1','./assets/pickup-countdown.js?v=1','./assets/wheel.js?v=1','./assets/wheel-polish.js?v=1','./assets/icon.svg','./assets/icon.svg?v=2'];
const SCOPE_PATH=new URL(self.registration.scope).pathname;
const CATALOG_KEY=new URL('./__offline/catalog.json',self.registration.scope).href;
const inAppScope=url=>url.origin===self.location.origin&&url.pathname.startsWith(SCOPE_PATH);
const navShell=url=>url.pathname.endsWith('/legal.html')?'./legal.html':url.pathname.endsWith('/payment-return.html')?'./payment-return.html':'./index.html';
const isProductImage=url=>url.pathname.includes('/customer/uploads/products/')||url.pathname.includes('/uploads/products/');
const isQrLibrary=url=>url.hostname==='cdnjs.cloudflare.com'&&url.pathname.endsWith('/qrcodejs/1.0.0/qrcode.min.js');
const isCatalog=url=>url.pathname.endsWith('/api/customer_catalog.php');
const isPublicData=url=>url.pathname.endsWith('/api/customer_maps_public.php')||url.pathname.endsWith('/api/customer_legal.php');
const isPrivateApi=url=>/\/api\/(?:customer_profile|customer_loyalty_card|customer_order_quote|customer_order_status|customer_reorder|customer_order_detail|customer_favorites|customer_wheel_api|customer_push_[^/]+)\.php$/.test(url.pathname);

function fetchWithTimeout(request,ms){
  return Promise.race([
    fetch(request),
    new Promise((_,reject)=>setTimeout(()=>reject(new TypeError('network timeout')),ms))
  ]);
}
async function notifyClients(resource,type='KAPOUCH_OFFLINE_FALLBACK'){
  const list=await clients.matchAll({type:'window',includeUncontrolled:true});
  for(const client of list)client.postMessage({type,resource});
}
async function trimCache(name,maxEntries){
  const cache=await caches.open(name),keys=await cache.keys();
  while(keys.length>maxEntries){const key=keys.shift();if(key)await cache.delete(key)}
}
async function putSafe(cacheName,key,response){
  if(!response||(!response.ok&&response.type!=='opaque'))return;
  try{const cache=await caches.open(cacheName);await cache.put(key,response.clone())}catch(e){}
}
async function popularImageUrls(response){
  try{
    const data=await response.json(),products=Array.isArray(data?.products)?data.products:[];
    const featured=products.filter(p=>p&&p.featured).slice(0,8),rows=featured.length?featured:products.slice(0,8),out=[];
    for(const p of rows){
      if(p?.image)out.push(String(p.image));
      for(const v of Array.isArray(p?.variants)?p.variants:[])if(v?.image)out.push(String(v.image));
      if(out.length>=16)break;
    }
    return [...new Set(out)].slice(0,16).map(src=>{try{return new URL(src,self.registration.scope).href}catch(e){return ''}}).filter(Boolean);
  }catch(e){return []}
}
async function prefetchPopularImages(response){
  const urls=await popularImageUrls(response.clone()),cache=await caches.open(IMAGE_CACHE);
  await Promise.all(urls.map(async url=>{
    try{
      if(await cache.match(url))return;
      const target=new URL(url),req=new Request(url,{mode:target.origin===self.location.origin?'same-origin':'no-cors',credentials:'omit',cache:'no-store'}),res=await fetch(req);
      if(res.ok||res.type==='opaque')await cache.put(url,res.clone());
    }catch(e){}
  }));
  await trimCache(IMAGE_CACHE,48);
}
async function catalogStrategy(request){
  const dataCache=await caches.open(DATA_CACHE);
  try{
    const response=await fetchWithTimeout(request,4500);
    if(response.ok){
      await dataCache.put(CATALOG_KEY,response.clone());
      prefetchPopularImages(response.clone()).catch(()=>{});
      notifyClients('catalog','KAPOUCH_NETWORK_OK').catch(()=>{});
      return response;
    }
    if(response.status>=500){
      const cached=await dataCache.match(CATALOG_KEY);
      if(cached){notifyClients('catalog').catch(()=>{});return cached}
    }
    return response;
  }catch(e){
    const cached=await dataCache.match(CATALOG_KEY);
    if(cached){notifyClients('catalog').catch(()=>{});return cached}
    throw e;
  }
}
async function navigationStrategy(request,url){
  const shell=navShell(url),cache=await caches.open(CACHE);
  try{
    const response=await fetchWithTimeout(new Request(request,{cache:'no-store'}),3500);
    if(response.ok){cache.put(shell,response.clone()).catch(()=>{});notifyClients('navigation','KAPOUCH_NETWORK_OK').catch(()=>{});return response}
    if(response.status>=500){const cached=await cache.match(shell)||await cache.match('./');if(cached){notifyClients('navigation').catch(()=>{});return cached}}
    return response;
  }catch(e){
    const cached=await cache.match(shell)||await cache.match('./');
    if(cached){notifyClients('navigation').catch(()=>{});return cached}
    throw e;
  }
}
async function staleWhileRevalidate(request,cacheName){
  const cache=await caches.open(cacheName),cached=await cache.match(request);
  const network=fetch(request).then(async response=>{if(response.ok||response.type==='opaque'){await cache.put(request,response.clone());if(cacheName===IMAGE_CACHE)trimCache(IMAGE_CACHE,48).catch(()=>{})}return response}).catch(()=>null);
  if(cached){network.catch(()=>{});return cached}
  const response=await network;if(response)return response;throw new TypeError('offline');
}
async function networkFirstPublic(request,url){
  const cache=await caches.open(DATA_CACHE),key=new Request(new URL('./__offline/public'+url.pathname,self.registration.scope).href);
  try{const response=await fetchWithTimeout(request,4500);if(response.ok)cache.put(key,response.clone()).catch(()=>{});return response}catch(e){const cached=await cache.match(key);if(cached){notifyClients('public-data').catch(()=>{});return cached}throw e}
}

self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(SHELL)).then(()=>self.skipWaiting()))});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('kapouch-')&&!CURRENT_CACHES.has(k)).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',event=>{
  const req=event.request;if(req.method!=='GET')return;
  const url=new URL(req.url);
  if(url.searchParams.get('connectivity_probe')==='1'){event.respondWith(fetch(new Request(req,{cache:'no-store'})));return}
  if(req.mode==='navigate'&&inAppScope(url)){event.respondWith(navigationStrategy(req,url));return}
  if(isCatalog(url)){event.respondWith(catalogStrategy(req));return}
  if(isPrivateApi(url))return;
  if(isPublicData(url)){event.respondWith(networkFirstPublic(req,url));return}
  if(isProductImage(url)){event.respondWith(staleWhileRevalidate(req,IMAGE_CACHE));return}
  if(isQrLibrary(url)){event.respondWith(staleWhileRevalidate(req,THIRD_PARTY_CACHE));return}
  if(inAppScope(url)){
    const networkFirst=url.pathname.endsWith('/config.js')||url.pathname.endsWith('/assets/payments.js')||url.pathname.endsWith('/assets/profile-plus.js')||url.pathname.endsWith('/assets/profile-avatar.js')||url.pathname.endsWith('/assets/offline-resilience.js')||url.pathname.endsWith('/assets/redesign-v4-polish.css')||url.pathname.endsWith('/assets/redesign-v3-fixes.js')||url.pathname.endsWith('/assets/pwa-polish.js')||url.pathname.endsWith('/assets/hero-cup.svg')||url.pathname.endsWith('/assets/saved-order.js')||url.pathname.endsWith('/assets/pickup-countdown.js')||url.pathname.endsWith('/assets/wheel.js')||url.pathname.endsWith('/assets/wheel-polish.js')||url.pathname.endsWith('/assets/sixth-drink-checkout.js')||url.pathname.endsWith('/assets/icon.svg');
    if(networkFirst){event.respondWith((async()=>{const cache=await caches.open(CACHE);try{const response=await fetchWithTimeout(new Request(req,{cache:'no-store'}),4000);if(response.ok)cache.put(req,response.clone()).catch(()=>{});return response}catch(e){const cached=await cache.match(req);if(cached){notifyClients('asset').catch(()=>{});return cached}throw e}})());return}
    event.respondWith(staleWhileRevalidate(req,CACHE));
  }
});
self.addEventListener('push',event=>{let data={title:'Kapouch',body:'У вас новое уведомление',url:'./'};try{if(event.data)data={...data,...event.data.json()}}catch(e){if(event.data)data.body=event.data.text()}event.waitUntil(self.registration.showNotification(data.title||'Kapouch',{body:data.body||'',icon:data.icon||'./assets/icon.svg?v=2',badge:data.badge||'./assets/icon.svg?v=2',tag:data.tag||'kapouch',renotify:true,data:{url:data.url||'./'},vibrate:[180,80,180]}))});
self.addEventListener('notificationclick',event=>{event.notification.close();const target=new URL(event.notification.data?.url||'./',self.registration.scope).href;event.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(list=>{for(const client of list){if('focus'in client){client.navigate(target);return client.focus()}}return clients.openWindow?clients.openWindow(target):undefined}))});
