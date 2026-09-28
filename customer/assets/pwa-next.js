(function(){
'use strict';
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const CART_KEY='kapouch_customer_cart';
const SHADOW_KEY='kapouch_cart_shadow_v1';
const HELD_KEY='kapouch_cart_held_v1';
const BASELINE_KEY='kapouch_cart_price_baseline_v1';
const REVIEW_KEY='kapouch_cart_review_v1';
const $=id=>document.getElementById(id);
const money=v=>Number(v||0).toLocaleString('ru-RU',{maximumFractionDigits:2})+' ₽';
let catalogMap=new Map();
let catalogReady=false;
let effectiveOffline=!navigator.onLine;
let slowMeasured=false;
let slowConnection=false;
let updateRegistration=null;
let refreshingForUpdate=false;
let reconnectRefresh=false;

function readJson(key,fallback){try{const value=JSON.parse(localStorage.getItem(key)||'null');return value??fallback}catch(e){return fallback}}
function writeJson(key,value){try{localStorage.setItem(key,JSON.stringify(value))}catch(e){}}
function cart(){const rows=readJson(CART_KEY,[]);return Array.isArray(rows)?rows:[]}
function lineKey(line){return String(line?.key||Number(line?.product_id||0)+':'+(Array.isArray(line?.modifiers)?line.modifiers.map(Number).filter(Boolean).sort((a,b)=>a-b).join(','):''))}
function sameLine(a,b){return lineKey(a)===lineKey(b)}
function connectionSaysSlow(){const c=navigator.connection||navigator.mozConnection||navigator.webkitConnection;if(!c)return false;return Boolean(c.saveData)||['slow-2g','2g','3g'].includes(String(c.effectiveType||''))}
function isSlow(){return slowConnection||slowMeasured||connectionSaysSlow()}

function injectStyle(){
  if($('kapouchPwaNextStyle'))return;
  const style=document.createElement('style');style.id='kapouchPwaNextStyle';style.textContent=`
.kapouch-cart-review{margin:0 0 14px;padding:14px 15px;border-radius:18px;background:#fff6dc;border:1px solid rgba(112,72,22,.14);color:#432d18}.kapouch-cart-review[hidden]{display:none!important}.kapouch-cart-review strong{display:block;font-size:13px}.kapouch-cart-review p{margin:5px 0 0;font-size:10px;line-height:1.45;color:#765637}.kapouch-cart-review ul{margin:8px 0 0;padding-left:17px;font-size:10px;line-height:1.45;color:#5d4329}.kapouch-cart-review-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.kapouch-cart-review button{border:0;border-radius:11px;padding:9px 11px;background:#3b2415;color:#fff7e9;font-size:10px;font-weight:900}.kapouch-cart-review button.secondary{background:rgba(59,36,21,.08);color:#4c321c;border:1px solid rgba(59,36,21,.12)}
.kapouch-menu-network-hint{display:none;margin:8px 0 12px;padding:9px 11px;border-radius:12px;background:rgba(245,185,63,.11);border:1px solid rgba(245,185,63,.16);color:#725330;font-size:10px;line-height:1.4}.kapouch-menu-network-hint.show{display:block}
.kapouch-update-banner{position:fixed;left:50%;bottom:86px;z-index:9996;transform:translateX(-50%);width:min(520px,calc(100% - 24px));display:none;align-items:center;gap:12px;padding:13px 14px;border-radius:18px;background:#2c1c11;color:#fff9ee;box-shadow:0 18px 45px rgba(35,21,10,.32);border:1px solid rgba(255,213,91,.22)}.kapouch-update-banner.show{display:flex}.kapouch-update-banner div{min-width:0;flex:1}.kapouch-update-banner strong{display:block;font-size:12px}.kapouch-update-banner span{display:block;margin-top:3px;color:#dacdbc;font-size:9px;line-height:1.35}.kapouch-update-banner button{border:0;border-radius:11px;padding:9px 11px;font-size:10px;font-weight:900}.kapouch-update-banner .apply{background:#ffd84f;color:#33200f}.kapouch-update-banner .later{background:transparent;color:#d8c7b2;padding:7px}
.kapouch-slow-network .product-photo{content-visibility:auto}
`;document.head.appendChild(style);
}

function buildCatalogMap(data){
  const map=new Map();
  for(const product of Array.isArray(data?.products)?data.products:[]){
    const variants=Array.isArray(product?.variants)&&product.variants.length?product.variants:[{id:product?.id,label:'Стандарт',price:product?.price,product_name:product?.name}];
    const groupsByProduct=product?.modifier_groups_by_product||{};
    for(const variant of variants){
      const options=new Map();
      for(const group of Array.isArray(groupsByProduct[String(Number(variant?.id))])?groupsByProduct[String(Number(variant?.id))]:[]){
        for(const option of Array.isArray(group?.options)?group.options:[])options.set(Number(option?.id),{price:Number(option?.price||0),label:String(option?.label||'')});
      }
      map.set(Number(variant?.id),{product_id:Number(variant?.id),name:String(product?.name||variant?.product_name||'Позиция'),variant:String(variant?.label||''),base:Number(variant?.price||product?.price||0),options});
    }
  }
  return map;
}
function unitInfo(line,map=catalogMap){
  const row=map.get(Number(line?.product_id||0));if(!row)return {available:false,unit:0,name:'Позиция',missing:[]};
  let unit=row.base;const missing=[];
  for(const id of Array.isArray(line?.modifiers)?line.modifiers:[]){const option=row.options.get(Number(id));if(option)unit+=Number(option.price||0);else missing.push(Number(id));}
  return {available:missing.length===0,unit,name:row.name+(row.variant&&row.variant!=='Стандарт'?' · '+row.variant:''),missing};
}
function baseline(){const row=readJson(BASELINE_KEY,{items:{}});if(!row||typeof row!=='object')return {items:{}};if(!row.items||typeof row.items!=='object')row.items={};return row}
function seedMissingBaselines(rows){
  const base=baseline();let changed=false;
  for(const line of rows){const key=lineKey(line);if(base.items[key])continue;const info=unitInfo(line);if(!info.available)continue;base.items[key]={product_id:Number(line.product_id),unit:Number(info.unit),name:info.name,saved_at:Date.now()};changed=true;}
  if(changed){base.updated_at=Date.now();writeJson(BASELINE_KEY,base)}
}
function acceptCurrentPrices(){const rows=cart(),base={items:{},updated_at:Date.now()};for(const line of rows){const info=unitInfo(line);if(info.available)base.items[lineKey(line)]={product_id:Number(line.product_id),unit:Number(info.unit),name:info.name,saved_at:Date.now()}}writeJson(BASELINE_KEY,base);localStorage.removeItem(REVIEW_KEY);renderCartReview();}
function comparePrices(rows,needsReload=false){
  const base=baseline(),changes=[];
  for(const line of rows){const old=base.items[lineKey(line)],now=unitInfo(line);if(!old||!now.available)continue;if(Math.abs(Number(old.unit)-Number(now.unit))>=0.01)changes.push({key:lineKey(line),name:now.name,old:Number(old.unit),now:Number(now.unit)});}
  if(changes.length)writeJson(REVIEW_KEY,{changes:changes.slice(0,8),created_at:Date.now(),needs_reload:Boolean(needsReload)});
  return changes;
}
function heldLines(){const rows=readJson(HELD_KEY,[]);return Array.isArray(rows)?rows:[]}
function saveHeld(rows){writeJson(HELD_KEY,rows.slice(0,12))}
function preserveDropped(before,after){
  const oldHeld=heldLines(),afterKeys=new Set(after.map(lineKey)),heldKeys=new Set(oldHeld.map(x=>lineKey(x.line||x))),next=[...oldHeld];
  for(const line of before){const key=lineKey(line);if(afterKeys.has(key)||heldKeys.has(key))continue;const info=unitInfo(line);if(info.available)continue;next.push({line:{...line},saved_at:Date.now(),name:info.name||'Позиция'});heldKeys.add(key);}
  if(next.length!==oldHeld.length)saveHeld(next);
}
function restoreAvailableHeld(){
  const held=heldLines(),ready=held.filter(x=>unitInfo(x.line||x).available);if(!ready.length)return false;
  const rows=cart();for(const item of ready){const line=item.line||item;if(!rows.some(x=>sameLine(x,line)))rows.push(line)}writeJson(CART_KEY,rows);saveHeld(held.filter(x=>!ready.includes(x)));return true;
}
function syncShadow(){if(!catalogReady)return;writeJson(SHADOW_KEY,cart())}

function ensureCartReview(){let el=$('kapouchCartReview');if(el)return el;el=document.createElement('section');el.id='kapouchCartReview';el.className='kapouch-cart-review';el.hidden=true;const checkout=$('checkoutCard');if(checkout)checkout.insertAdjacentElement('beforebegin',el);return el}
function renderCartReview(){
  const el=ensureCartReview();if(!el)return;
  const review=readJson(REVIEW_KEY,null),held=heldLines(),restorable=held.filter(x=>unitInfo(x.line||x).available),offline=effectiveOffline||document.body.classList.contains('kapouch-offline'),slow=isSlow();
  if(!review&&!held.length&&!offline&&!slow){el.hidden=true;el.innerHTML='';return}
  let title='Корзина под контролем',text='',list='',actions='';
  if(offline){title='Корзина сохранена';text='Показываем сохранённые цены и меню. Перед оформлением всё перепроверим онлайн.';}
  else if(review?.changes?.length){title='Цены в корзине изменились';text='Мы ничего не удалили молча — показываем, что изменилось после обновления меню.';list='<ul>'+review.changes.slice(0,4).map(x=>'<li>'+String(x.name).replace(/[<>&]/g,'')+': '+money(x.old)+' → '+money(x.now)+'</li>').join('')+'</ul>';actions+='<button type="button" data-cart-accept>'+(review.needs_reload?'Обновить корзину':'Понятно')+'</button>';}
  else if(held.length){title='Позиция сохранена';text=held.length===1?'Одна позиция временно пропала из меню. Мы сохранили её отдельно, чтобы она не потерялась.':held.length+' позиции временно недоступны и сохранены отдельно.';}
  else if(slow){title='Медленное соединение';text='Меню и корзина работают из локальных данных, изображения догружаются по мере возможности.';}
  if(restorable.length)actions+='<button type="button" class="secondary" data-cart-restore>Вернуть доступные позиции</button>';
  el.innerHTML='<strong>'+title+'</strong><p>'+text+'</p>'+list+(actions?'<div class="kapouch-cart-review-actions">'+actions+'</div>':'');el.hidden=false;
  el.querySelector('[data-cart-accept]')?.addEventListener('click',()=>{const reload=Boolean(readJson(REVIEW_KEY,null)?.needs_reload);acceptCurrentPrices();if(reload)location.reload()});
  el.querySelector('[data-cart-restore]')?.addEventListener('click',()=>{if(restoreAvailableHeld())location.reload()});
}

function ensureMenuHint(){let el=$('kapouchMenuNetworkHint');if(el)return el;el=document.createElement('div');el.id='kapouchMenuNetworkHint';el.className='kapouch-menu-network-hint';el.dataset.offlineSearchReady='1';const box=$('searchBox');if(box)box.insertAdjacentElement('afterend',el);return el}
function renderNetworkState(){
  document.body.classList.toggle('kapouch-slow-network',isSlow());
  const hint=ensureMenuHint();if(hint){if(effectiveOffline){hint.textContent='Нет подключения · поиск работает по сохранённому меню.';hint.classList.add('show')}else if(isSlow()){hint.textContent='Медленное соединение · поиск работает сразу, изображения догружаются.';hint.classList.add('show')}else hint.classList.remove('show')}
  document.querySelectorAll('.product-photo').forEach(img=>{img.loading='lazy';img.decoding='async';if(isSlow()&&'fetchPriority'in img)img.fetchPriority='low'});
  renderCartReview();
}
function setOffline(value){effectiveOffline=Boolean(value);renderNetworkState()}
function setSlow(value,measured=false){if(measured)slowMeasured=Boolean(value);else slowConnection=Boolean(value);renderNetworkState()}
async function measureLatency(){
  if(!navigator.onLine)return;const started=performance.now();
  try{const r=await fetch(new URL('config.js?connectivity_probe=1&latency_probe='+Date.now(),location.href).href,{cache:'no-store'});if(!r.ok)return;const ms=performance.now()-started;setSlow(ms>1800,true);}
  catch(e){}
}
function watchConnection(){const c=navigator.connection||navigator.mozConnection||navigator.webkitConnection;if(c){const apply=()=>setSlow(connectionSaysSlow(),false);apply();c.addEventListener?.('change',apply)}setTimeout(measureLatency,1200)}

function ensureUpdateBanner(){let el=$('kapouchUpdateBanner');if(el)return el;el=document.createElement('div');el.id='kapouchUpdateBanner';el.className='kapouch-update-banner';el.innerHTML='<div><strong>Доступно обновление Kapouch</strong><span>Обновим приложение без потери корзины и сохранённых данных.</span></div><button type="button" class="apply">Обновить</button><button type="button" class="later">Позже</button>';document.body.appendChild(el);el.querySelector('.apply').onclick=()=>{const waiting=updateRegistration?.waiting;if(!waiting)return;refreshingForUpdate=true;waiting.postMessage({type:'SKIP_WAITING'})};el.querySelector('.later').onclick=()=>el.classList.remove('show');return el}
function showUpdate(reg){updateRegistration=reg;ensureUpdateBanner().classList.add('show')}
async function monitorUpdates(){
  if(!('serviceWorker'in navigator))return;ensureUpdateBanner();
  navigator.serviceWorker.addEventListener('controllerchange',()=>{if(refreshingForUpdate)location.reload()});
  const wire=reg=>{if(!reg)return;if(reg.waiting&&navigator.serviceWorker.controller)showUpdate(reg);reg.addEventListener('updatefound',()=>{const worker=reg.installing;if(!worker)return;worker.addEventListener('statechange',()=>{if(worker.state==='installed'&&navigator.serviceWorker.controller)showUpdate(reg)})})};
  try{let reg=await navigator.serviceWorker.getRegistration();if(!reg)reg=await navigator.serviceWorker.ready;wire(reg);if(navigator.onLine)setTimeout(()=>reg.update().catch(()=>{}),1800)}catch(e){}
}

function applyBadge(order){if(!('setAppBadge'in navigator))return;const status=String(order?.status||'');if(status==='ready')navigator.setAppBadge(1).catch(()=>{});else if(['completed','cancelled','new','preparing'].includes(status)&&'clearAppBadge'in navigator)navigator.clearAppBadge().catch(()=>{})}
function processCatalog(data,source){
  if(!data?.ok)return;const before=readJson(SHADOW_KEY,cart());catalogMap=buildCatalogMap(data);const offline=Boolean(data.offline)||effectiveOffline||!navigator.onLine;if(offline)setOffline(true);
  setTimeout(()=>{
    const after=cart();if(!offline)preserveDropped(Array.isArray(before)?before:[],after);const changes=comparePrices(after,reconnectRefresh&&!offline);seedMissingBaselines(after);catalogReady=true;syncShadow();reconnectRefresh=false;renderCartReview();renderNetworkState();
    if(changes.length)window.dispatchEvent(new CustomEvent('kapouch:cart-revalidated',{detail:{changes,source}}));
  },80);
}
async function refreshCatalog(source){
  const before=cart();if(!readJson(SHADOW_KEY,null))writeJson(SHADOW_KEY,before);
  try{const r=await fetch(apiBase+'/customer_catalog.php?pwa_next='+encodeURIComponent(source)+'&_='+Date.now(),{cache:'no-store',headers:{Accept:'application/json'}});const data=await r.json().catch(()=>null);if(r.ok&&data?.ok)processCatalog(data,source)}catch(e){}
}
function installCartObserver(){const list=$('cartList');if(list)new MutationObserver(()=>setTimeout(syncShadow,0)).observe(list,{childList:true,subtree:true});document.addEventListener('click',e=>{if(e.target.closest('[data-add-key],#detailAdd,[data-line],.repeat-order-btn,#quickRepeatCard'))setTimeout(syncShadow,250)},true)}
function loadManifestShortcutsHint(){/* marker for contract: manifest shortcuts are server-generated */}
function start(){
  injectStyle();ensureCartReview();ensureMenuHint();ensureUpdateBanner();installCartObserver();watchConnection();monitorUpdates();loadManifestShortcutsHint();
  window.addEventListener('kapouch:catalog',e=>processCatalog(e.detail||{},'event'));
  window.addEventListener('kapouch:network-offline',()=>setOffline(true));
  window.addEventListener('kapouch:network-online',()=>{if(navigator.onLine)setOffline(false)});
  window.addEventListener('kapouch:connection-restored',()=>{setOffline(false);reconnectRefresh=true;measureLatency();refreshCatalog('reconnect')});
  window.addEventListener('offline',()=>setOffline(true));window.addEventListener('online',()=>{setOffline(false);reconnectRefresh=true;setTimeout(()=>refreshCatalog('online'),500)});
  window.addEventListener('kapouch-order-status',e=>applyBadge(e.detail||{}));
  window.addEventListener('hashchange',()=>requestAnimationFrame(()=>{ensureCartReview();ensureMenuHint();renderNetworkState()}));
  new MutationObserver(renderNetworkState).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
  if(!readJson(SHADOW_KEY,null))writeJson(SHADOW_KEY,cart());
  renderNetworkState();setTimeout(()=>refreshCatalog('startup'),300);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
