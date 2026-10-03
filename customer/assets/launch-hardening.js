(function(){
'use strict';
if(window.__KAPOUCH_LAUNCH_HARDENING)return;
window.__KAPOUCH_LAUNCH_HARDENING=true;

const $=id=>document.getElementById(id);
let toastTimer=0;

function liveRegion(){
  let el=$('kapouchLiveRegion');
  if(el)return el;
  el=document.createElement('div');
  el.id='kapouchLiveRegion';
  el.className='kapouch-live-region';
  el.setAttribute('aria-live','polite');
  el.setAttribute('aria-atomic','true');
  document.body.appendChild(el);
  return el;
}
function announce(message){
  const text=String(message||'').trim();if(!text)return;
  const el=liveRegion();el.textContent='';requestAnimationFrame(()=>{el.textContent=text});
}
function toast(message){
  const text=String(message||'').trim();if(!text)return;
  let el=$('kapouchToast');
  if(!el){el=document.createElement('div');el.id='kapouchToast';el.className='kapouch-toast';el.setAttribute('role','status');document.body.appendChild(el)}
  el.textContent=text;el.classList.add('show');announce(text);clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.classList.remove('show'),2200);
}
function improveSemantics(){
  document.querySelectorAll('button:not([type])').forEach(button=>{if(!button.closest('form'))button.type='button'});
  const search=$('searchInput');if(search){search.setAttribute('aria-label','Поиск по меню');search.setAttribute('enterkeyhint','search')}
  const modal=$('productModal');if(modal){modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-label','Карточка напитка')}
  ['orderStatusStrip','checkoutError','phoneError','codeError','pushError','modifierError'].forEach(id=>{const el=$(id);if(el){el.setAttribute('aria-live',id==='orderStatusStrip'?'polite':'assertive');el.setAttribute('aria-atomic','true')}});
}
function fallbackBrokenImage(img){
  if(!(img instanceof HTMLImageElement)||img.dataset.kapouchFallback==='1')return;
  if(!img.classList.contains('product-photo')&&!img.closest('.mini-visual'))return;
  img.dataset.kapouchFallback='1';
  const fallback=document.createElement('div');fallback.className='launch-image-fallback';fallback.setAttribute('aria-hidden','true');fallback.innerHTML='<span>K</span>';
  img.replaceWith(fallback);
}
function installImageFallback(){
  document.addEventListener('error',event=>fallbackBrokenImage(event.target),true);
  document.querySelectorAll('img.product-photo,.mini-visual img').forEach(img=>{if(img.complete&&img.naturalWidth===0)fallbackBrokenImage(img)});
}
function installCartFeedback(){
  const count=$('headerCartCount');if(!count)return;
  let previous=Number(count.textContent||0);
  new MutationObserver(()=>{
    const next=Number(count.textContent||0);
    if(next>previous)toast('Добавлено в корзину');
    previous=next;
  }).observe(count,{childList:true,characterData:true,subtree:true});
}
function updateBanner(){
  if($('kapouchUpdateBanner'))return;
  const el=document.createElement('div');el.id='kapouchUpdateBanner';el.className='kapouch-update-banner';el.innerHTML='<div><strong>Kapouch обновлён</strong><span>Доступна свежая версия интерфейса.</span></div><button type="button" data-update-reload>Обновить</button><button type="button" class="dismiss" aria-label="Закрыть" data-update-dismiss>×</button>';
  el.querySelector('[data-update-reload]').onclick=()=>location.reload();
  el.querySelector('[data-update-dismiss]').onclick=()=>el.remove();
  document.body.appendChild(el);announce('Доступна свежая версия Kapouch');
}
function installServiceWorkerRefresh(){
  if(!('serviceWorker'in navigator))return;
  const hadController=Boolean(navigator.serviceWorker.controller);
  let changed=false;
  navigator.serviceWorker.addEventListener('controllerchange',()=>{if(changed)return;changed=true;if(hadController)updateBanner()});
  window.addEventListener('load',()=>navigator.serviceWorker.ready.then(reg=>{if(navigator.onLine)reg.update().catch(()=>{})}).catch(()=>{}),{once:true});
}
function installKeyboardClose(){
  document.addEventListener('keydown',event=>{
    if(event.key!=='Escape')return;
    const modal=$('productModal');if(modal&&!modal.hidden){modal.querySelector('[data-close-product]')?.click();return}
    const overlay=$('loyaltyCardOverlay');if(overlay&&!overlay.hidden)$('loyaltyCardClose')?.click();
  });
}
function start(){
  improveSemantics();installImageFallback();installCartFeedback();installServiceWorkerRefresh();installKeyboardClose();liveRegion();
  window.addEventListener('kapouch:connection-restored',()=>toast('Подключение восстановлено'));
  window.addEventListener('pageshow',event=>{if(event.persisted){improveSemantics();navigator.serviceWorker?.ready?.then(reg=>reg.update().catch(()=>{})).catch(()=>{})}});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
