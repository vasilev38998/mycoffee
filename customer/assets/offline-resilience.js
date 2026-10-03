(function(){
'use strict';
if(window.__KAPOUCH_OFFLINE_BOOTSTRAPPED)return;
window.__KAPOUCH_OFFLINE_BOOTSTRAPPED=true;
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const $=id=>document.getElementById(id);
let offline=!navigator.onLine;
let probing=false;
let lastReason='';
let lastFallbackAt=0;
let uiQueued=false;

function injectStyle(){
  if($('kapouchOfflineStyle'))return;
  const style=document.createElement('style');
  style.id='kapouchOfflineStyle';
  style.textContent=`
.kapouch-offline-panel{display:none;margin:0 0 14px;padding:16px 17px;border-radius:20px;background:linear-gradient(145deg,#fff3cf,#ffe6a0);border:1px solid rgba(112,72,22,.14);box-shadow:0 12px 30px rgba(83,49,17,.08);color:#3c2816}.kapouch-offline-panel.show{display:block}.kapouch-offline-panel strong{display:block;font-size:16px;line-height:1.15}.kapouch-offline-panel p{margin:6px 0 0;font-size:11px;line-height:1.48;color:#6e5335}.kapouch-offline-panel button{margin-top:12px;border:0;border-radius:12px;padding:10px 14px;background:#3b2415;color:#fff8ed;font-size:11px;font-weight:900}.kapouch-offline-panel button:disabled{opacity:.55}.kapouch-offline-panel small{display:block;margin-top:8px;color:#8a6d4b;font-size:9px;line-height:1.35}.kapouch-offline .payment-online-note{color:#8b5a33}.kapouch-offline .checkout-card{position:relative}.kapouch-offline .checkout-card:before{content:'Для оформления заказа нужен интернет';display:block;margin:0 0 10px;padding:10px 12px;border-radius:12px;background:#fff2cd;color:#6a451f;font-size:10px;font-weight:850}.offline-data-note{display:inline-flex;align-items:center;gap:5px;margin-left:6px;color:#8a6b48;font-size:9px;font-weight:750}.offline-data-note:before{content:'•';font-size:13px}
body.k-redesign-v2 .view[data-view="home"]>.kapouch-offline-panel{order:15}
`;
  document.head.appendChild(style);
}
function panel(){
  let el=$('kapouchOfflinePanel');
  if(el)return el;
  el=document.createElement('section');
  el.id='kapouchOfflinePanel';
  el.className='kapouch-offline-panel';
  el.setAttribute('role','status');
  el.innerHTML='<strong>Нет подключения</strong><p>Меню и сохранённые данные всё ещё доступны.<br>Заказ требует подключения к интернету.</p><button type="button" id="kapouchOfflineRetry">Повторить</button><small id="kapouchOfflineReason"></small>';
  const retry=el.querySelector('#kapouchOfflineRetry');if(retry)retry.onclick=()=>probe(true);
  return el;
}
function activeView(){return document.querySelector('.view.active')||document.querySelector('.view[data-view="home"]')}
function placePanel(){const el=panel(),view=activeView();if(!view)return;if(el.parentNode!==view){const head=view.querySelector('.page-head');if(head)head.insertAdjacentElement('afterend',el);else view.insertBefore(el,view.firstChild)}}
function updateCheckout(){
  const btn=$('checkoutButton');
  if(!btn)return;
  if(offline){
    if(btn.dataset.offlineLocked!=='1'){
      btn.dataset.offlineLocked='1';
      btn.dataset.offlineWasDisabled=btn.disabled?'1':'0';
      btn.dataset.offlineOldText=btn.textContent||'Оформить заказ';
    }
    btn.disabled=true;btn.textContent='Нужен интернет для заказа';
  }else if(btn.dataset.offlineLocked==='1'){
    const wasDisabled=btn.dataset.offlineWasDisabled==='1';
    const oldText=btn.dataset.offlineOldText||'Оформить заказ';
    delete btn.dataset.offlineLocked;delete btn.dataset.offlineWasDisabled;delete btn.dataset.offlineOldText;
    btn.textContent=oldText;if(!wasDisabled)btn.disabled=false;
  }
}
function render(){
  injectStyle();placePanel();document.body.classList.toggle('kapouch-offline',offline);
  const el=panel();el.classList.toggle('show',offline);
  const reason=$('kapouchOfflineReason');if(reason)reason.textContent=offline&&lastReason?'Показываем последнюю сохранённую версию.':'';
  updateCheckout();
}
function setOffline(value,reason=''){
  offline=Boolean(value);
  if(offline){if(reason)lastReason=reason;}else{lastReason='';lastFallbackAt=0;}
  try{sessionStorage.setItem('kapouch_effective_offline',offline?'1':'0')}catch(e){}
  render();
}
function clearPrivateOfflineState(){
  try{
    localStorage.removeItem('kapouch_offline_profile_v1');
    localStorage.removeItem('kapouch_offline_loyalty_v1');
    localStorage.removeItem('kapouch_current_order_snapshot_v1');
  }catch(e){}
}
async function rawProbe(url){
  const r=await fetch(url+(url.includes('?')?'&':'?')+'connectivity_probe=1&_='+Date.now(),{cache:'no-store',headers:{Accept:'*/*'}});
  if(!r.ok)throw new Error('HTTP '+r.status);
  return true;
}
async function probe(reloadOnSuccess){
  if(probing)return false;probing=true;
  const btn=$('kapouchOfflineRetry');if(btn){btn.disabled=true;btn.textContent='Проверяем…'}
  try{
    await Promise.all([
      rawProbe(new URL('config.js',window.location.href).href),
      rawProbe(apiBase+'/customer_catalog.php')
    ]);
    setOffline(false,'');
    try{window.dispatchEvent(new CustomEvent('kapouch:connection-restored'))}catch(e){}
    if(reloadOnSuccess)location.reload();
    return true;
  }catch(e){lastFallbackAt=Date.now();setOffline(true,'probe');return false}
  finally{probing=false;if(btn){btn.disabled=false;btn.textContent='Повторить'}}
}
function decorateCachedData(event){
  const detail=event?.detail||{};
  if(!detail.offline)return;
  lastFallbackAt=Date.now();setOffline(true,'cached-data');
  const target=event.type==='kapouch:profile'?$('profileOrders'):null;
  if(target&&!target.parentElement?.querySelector('.offline-data-note')){
    const title=target.previousElementSibling?.querySelector('h2');
    if(title){const note=document.createElement('span');note.className='offline-data-note';note.textContent='сохранено';title.insertAdjacentElement('afterend',note)}
  }
}
function installCheckoutGuard(){
  const form=$('checkoutForm');if(!form||form.dataset.offlineGuard==='1')return;form.dataset.offlineGuard='1';
  form.addEventListener('submit',e=>{
    if(!offline)return;
    e.preventDefault();e.stopImmediatePropagation();
    const err=$('checkoutError');if(err){err.textContent='Нет подключения. Корзина сохранена — оформите заказ, когда интернет восстановится.';err.classList.add('show')}
    lastFallbackAt=Date.now();setOffline(true,'checkout');
  },true);
}
function installLogoutCleanup(){
  const button=$('logoutButton');if(!button||button.dataset.offlineCleanup==='1')return;
  button.dataset.offlineCleanup='1';
  button.addEventListener('click',clearPrivateOfflineState,true);
}
function acceptNetworkOk(){if(!navigator.onLine)return;setOffline(false,'')}
function installServiceWorkerMessages(){
  if(!('serviceWorker'in navigator))return;
  navigator.serviceWorker.addEventListener('message',event=>{
    const data=event.data||{};
    if(data.type==='KAPOUCH_OFFLINE_FALLBACK'){lastFallbackAt=Date.now();setOffline(true,String(data.resource||'cache'))}
    if(data.type==='KAPOUCH_NETWORK_OK')acceptNetworkOk();
  });
}
function scheduleUi(){if(uiQueued)return;uiQueued=true;requestAnimationFrame(()=>{uiQueued=false;placePanel();updateCheckout();installLogoutCleanup();});}
function start(){
  injectStyle();installCheckoutGuard();installLogoutCleanup();installServiceWorkerMessages();
  if(navigator.onLine){
    try{sessionStorage.removeItem('kapouch_effective_offline')}catch(e){}
    offline=false;
  }
  window.addEventListener('offline',()=>{lastFallbackAt=Date.now();setOffline(true,'browser')});
  window.addEventListener('online',()=>setTimeout(()=>probe(false),350));
  window.addEventListener('kapouch:network-offline',e=>{lastFallbackAt=Date.now();setOffline(true,String(e.detail?.resource||'network'))});
  window.addEventListener('kapouch:network-online',acceptNetworkOk);
  window.addEventListener('kapouch:profile',decorateCachedData);
  window.addEventListener('hashchange',scheduleUi);
  new MutationObserver(scheduleUi).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class','hidden']});
  render();
  if(navigator.onLine)setTimeout(()=>probe(false),900);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
