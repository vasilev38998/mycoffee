(function(){
'use strict';
const $=id=>document.getElementById(id);
const svg={
  home:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 10.7 12 3l8.5 7.7v9.1a1.2 1.2 0 0 1-1.2 1.2h-5.2v-6.1H9.9V21H4.7a1.2 1.2 0 0 1-1.2-1.2z"/></svg>',
  menu:'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/></svg>',
  cart:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.2 7.8h13.6l-1 12H6.2z"/><path d="M8.4 8V6.4A3.6 3.6 0 0 1 12 2.8a3.6 3.6 0 0 1 3.6 3.6V8"/></svg>',
  profile:'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.2 21a7.8 7.8 0 0 1 15.6 0"/></svg>'
};
function installNavIcons(){
  document.querySelectorAll('.bottom-nav [data-nav]').forEach(button=>{
    const key=button.dataset.nav||'';const holder=button.querySelector('span');
    if(holder&&svg[key]&&holder.dataset.svgReady!=='1'){holder.innerHTML=svg[key];holder.dataset.svgReady='1';}
  });
  const cart=$('headerCart');if(cart){const holder=cart.querySelector('span');if(holder&&holder.dataset.svgReady!=='1'){holder.innerHTML=svg.cart;holder.dataset.svgReady='1';}}
}
function installHeroCup(){
  const art=document.querySelector('.hero-art');if(!art)return;
  if(art.dataset.heroSvgReady==='1')return;
  art.innerHTML='<div class="hero-drink-frame"><img id="heroDrinkImage" class="hero-drink-image" src="assets/hero-cup.svg?v=2" alt="Стакан кофе Kapouch" width="360" height="420" decoding="async"></div>';
  art.dataset.heroSvgReady='1';
}
function normalizePickupLabels(){
  const select=$('pickupDelay');if(!select)return;
  for(const option of select.options){
    const next=String(option.textContent||'')
      .replace(/\s*[·•]\s*свободно\s*\d+\s*$/iu,'')
      .replace(/\s*\(\s*свободно\s*\d+\s*\)\s*$/iu,'')
      .trim();
    if(next&&option.textContent!==next)option.textContent=next;
  }
}
function refresh(){installHeroCup();normalizePickupLabels();installNavIcons();}
function observe(){
  refresh();
  const pickup=$('pickupDelay');
  if(pickup)new MutationObserver(normalizePickupLabels).observe(pickup,{childList:true,subtree:true,characterData:true});
  setTimeout(refresh,250);setTimeout(refresh,1200);
}
installNavIcons();if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',observe,{once:true});else observe();
})();
