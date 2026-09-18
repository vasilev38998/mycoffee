(function(){
'use strict';
const standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;

function lockStandaloneZoom(){
  if(!standalone)return;
  const viewport=document.querySelector('meta[name="viewport"]');
  if(viewport)viewport.setAttribute('content','width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover');
  document.documentElement.classList.add('kapouch-no-zoom');
  if(!document.getElementById('kapouchNoZoomStyle')){
    const style=document.createElement('style');
    style.id='kapouchNoZoomStyle';
    style.textContent='html.kapouch-no-zoom,html.kapouch-no-zoom body{touch-action:pan-x pan-y}html.kapouch-no-zoom button,html.kapouch-no-zoom a,html.kapouch-no-zoom input,html.kapouch-no-zoom textarea,html.kapouch-no-zoom select{touch-action:manipulation}';
    document.head.appendChild(style);
  }
  ['gesturestart','gesturechange','gestureend'].forEach(type=>document.addEventListener(type,event=>event.preventDefault(),{passive:false}));
}

let maxUrl=String(window.KAPOUCH_CATALOG_SHOP?.max_url||'').trim();
function ensureMaxLink(){
  const box=document.getElementById('externalLinks');
  if(!box||!maxUrl)return;
  const existing=box.querySelector('[data-kapouch-max]');
  if(existing){if(existing.getAttribute('href')!==maxUrl)existing.setAttribute('href',maxUrl);return;}
  const link=document.createElement('a');
  link.href=maxUrl;link.target='_blank';link.rel='noopener';link.dataset.kapouchMax='1';link.textContent='MAX →';
  box.appendChild(link);box.hidden=false;
}
function acceptCatalog(event){
  maxUrl=String(event?.detail?.shop?.max_url||window.KAPOUCH_CATALOG_SHOP?.max_url||'').trim();
  ensureMaxLink();
}
function observeLinks(){
  const box=document.getElementById('externalLinks');
  if(!box)return;
  new MutationObserver(ensureMaxLink).observe(box,{childList:true});
  ensureMaxLink();
}

lockStandaloneZoom();
window.addEventListener('kapouch:catalog',acceptCatalog);
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',observeLinks,{once:true});
else observeLinks();
})();
