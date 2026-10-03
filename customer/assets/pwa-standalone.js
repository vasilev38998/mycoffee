(function(){
'use strict';
const standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;

function markStandalone(){
  if(!standalone)return;
  document.documentElement.classList.add('kapouch-standalone');
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

markStandalone();
window.addEventListener('kapouch:catalog',acceptCatalog);
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',observeLinks,{once:true});
else observeLinks();
})();
