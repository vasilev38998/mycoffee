const CACHE='kapouch-pwa-v2';
const SHELL=['./','./index.html','./config.js?v=5','./assets/app.css?v=5','./assets/app.js?v=5','./assets/icon.svg'];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(SHELL)).then(()=>self.skipWaiting()))});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',event=>{
  const req=event.request;if(req.method!=='GET')return;
  const url=new URL(req.url);
  if(url.pathname.includes('/api/customer_profile.php')||url.pathname.includes('/api/customer_order_status.php'))return;
  if(url.pathname.includes('/api/customer_catalog.php')){
    event.respondWith(fetch(req).then(res=>{const copy=res.clone();caches.open(CACHE).then(c=>c.put(req,copy));return res}).catch(()=>caches.match(req)));
    return;
  }
  if(url.origin===location.origin&&url.pathname.endsWith('/customer/index.html')){
    event.respondWith(fetch(req).then(res=>{if(res.ok)caches.open(CACHE).then(c=>c.put(req,res.clone()));return res}).catch(()=>caches.match(req)));
    return;
  }
  if(url.origin===location.origin&&url.pathname.includes('/customer/'))event.respondWith(caches.match(req).then(cached=>cached||fetch(req).then(res=>{if(res.ok)caches.open(CACHE).then(c=>c.put(req,res.clone()));return res})));
});
