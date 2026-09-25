(function(){
'use strict';

const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{};
const appOrigin=(()=>{try{return new URL(cfg.appBase||location.origin,location.href).origin}catch(e){return location.origin}})();
const apiOrigin=(()=>{try{return new URL(cfg.apiBase||location.origin,location.href).origin}catch(e){return location.origin}})();

function promoteFinalStyle(){
  const link=document.getElementById('kapouchRedesignV3');
  if(link&&link.parentNode===document.head)document.head.appendChild(link);
}

function cupSvg(){
  return '<svg viewBox="0 0 32 40" aria-hidden="true" focusable="false"><path class="cup-lid" d="M5 4.5h22c1.4 0 2.5 1.1 2.5 2.5v2H2.5V7C2.5 5.6 3.6 4.5 5 4.5Z"/><path class="cup-body" d="M5.5 9h21l-2.2 25.2c-.2 2.1-1.9 3.8-4 3.8h-8.6c-2.1 0-3.8-1.7-4-3.8L5.5 9Z"/><path class="cup-band" d="M7.3 17.2h17.4l-.9 10.4H8.2l-.9-10.4Z"/></svg>';
}

function upgradeCup(node){
  if(!(node instanceof HTMLElement)||!node.classList.contains('loyalty-cup')||node.dataset.cupV3==='1')return;
  const label=node.classList.contains('gift')?'★':String(node.textContent||'').trim();
  node.dataset.cupV3='1';
  node.innerHTML=cupSvg()+'<span>'+label+'</span>';
}

function upgradeLoyaltyCups(root=document){
  root.querySelectorAll?.('.loyalty-cup').forEach(upgradeCup);
}

function socialSvg(kind){
  if(kind==='vk')return '<svg viewBox="0 0 48 48" aria-hidden="true"><rect width="48" height="48" rx="14" fill="#2787F5"/><path d="M12.2 15.2h6.2c.5 0 .8.2 1 .7 1.5 4.2 3.8 7.8 5.1 7.8.7 0 .9-.6.9-2.2v-3.3c0-1.5-.3-2.2-1.3-2.5v-.5h7.8c.6 0 .9.3.9.8v5.2c0 1.1.4 1.6.9 1.6.8 0 2.1-1.8 3.9-5.7.4-.9.8-1.8 1.2-1.9h6.5c.9 0 1.2.5.8 1.4-1 2.4-3.2 5.5-5.2 7.7-.8.9-.8 1.4-.1 2.2 1.4 1.5 3.2 3.1 5.1 5.5.6.8.3 1.5-.7 1.5h-6.5c-.8 0-1.3-.3-1.8-.9-1.7-2-3.1-3.7-4-3.7-.7 0-1.1.6-1.1 2v1.8c0 .5-.3.8-.9.8h-2.8c-5.4 0-10.1-3.2-13.7-9-1.7-2.7-3.3-5.9-4.2-8.5-.2-.6.1-1 .8-1Z" fill="#fff" transform="scale(.8) translate(5.8 5.8)"/></svg>';
  if(kind==='max')return '<svg viewBox="0 0 48 48" aria-hidden="true"><defs><linearGradient id="maxg" x1="6" y1="5" x2="42" y2="43" gradientUnits="userSpaceOnUse"><stop stop-color="#6F7BFF"/><stop offset="1" stop-color="#3AB9F1"/></linearGradient></defs><rect width="48" height="48" rx="14" fill="url(#maxg)"/><path d="M13 31V17.6c0-1 .8-1.8 1.8-1.8h2.3c.7 0 1.3.4 1.6 1l5.3 9.1 5.3-9.1c.3-.6.9-1 1.6-1h2.3c1 0 1.8.8 1.8 1.8V31h-4.3v-7.8l-4.5 7.2c-.5.8-1.7.8-2.2 0l-4.5-7.2V31H13Z" fill="#fff"/></svg>';
  if(kind==='telegram')return '<svg viewBox="0 0 48 48" aria-hidden="true"><rect width="48" height="48" rx="14" fill="#2AABEE"/><path d="m12 23.3 22.7-8.8c1.1-.4 2 .3 1.6 1.9l-3.9 18.2c-.3 1.3-1 1.6-2.1 1l-6-4.4-2.9 2.8c-.3.3-.6.6-1.2.6l.4-6.1 11.1-10c.5-.4-.1-.7-.7-.3l-13.7 8.6-5.9-1.8c-1.3-.4-1.3-1.2.6-1.7Z" fill="#fff"/></svg>';
  return '';
}

function decorateSocialLinks(){
  const box=document.getElementById('externalLinks');
  if(!box)return;
  box.querySelectorAll('a').forEach(link=>{
    const href=String(link.getAttribute('href')||'').toLowerCase();
    const text=String(link.textContent||'').trim().toLowerCase();
    let kind='';
    if(link.dataset.kapouchMax==='1'||text.startsWith('max')||href.includes('max.ru'))kind='max';
    else if(text.startsWith('vk')||href.includes('vk.com'))kind='vk';
    else if(text.startsWith('telegram')||href.includes('t.me/'))kind='telegram';
    if(!kind)return;
    if(link.dataset.socialIcon===kind)return;
    link.dataset.socialIcon=kind;
    link.classList.add('social-icon-link');
    link.setAttribute('aria-label',kind==='vk'?'VK':kind==='max'?'MAX':'Telegram');
    link.setAttribute('title',kind==='vk'?'VK':kind==='max'?'MAX':'Telegram');
    link.innerHTML=socialSvg(kind);
  });
}

function imageFallbackCandidates(src){
  let url;try{url=new URL(src,location.href)}catch(e){return []}
  const filename=url.searchParams.get('f');
  if(!filename||!/^[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$/i.test(filename))return [];
  const f=encodeURIComponent(filename);
  return [
    appOrigin+'/uploads/products/'+f,
    apiOrigin+'/customer/uploads/products/'+f,
    apiOrigin+'/uploads/products/'+f
  ].filter((value,index,array)=>value!==url.href&&array.indexOf(value)===index);
}

function onImageError(event){
  const img=event.target;
  if(!(img instanceof HTMLImageElement))return;
  if(!img.matches('.product-photo,.product-detail-image,.mini-visual img'))return;
  let candidates=[];
  try{candidates=JSON.parse(img.dataset.kapouchImageFallbacks||'[]')}catch(e){}
  if(!candidates.length){
    candidates=imageFallbackCandidates(img.currentSrc||img.src);
    img.dataset.kapouchImageFallbacks=JSON.stringify(candidates);
    img.dataset.kapouchImageFallbackIndex='0';
  }
  const index=Number(img.dataset.kapouchImageFallbackIndex||0);
  if(index<candidates.length){
    img.dataset.kapouchImageFallbackIndex=String(index+1);
    img.src=candidates[index];
    return;
  }
  if(img.classList.contains('product-photo')){
    const visual=img.closest('.visual');
    if(visual&&!visual.querySelector('.cup')){
      const fallback=document.createElement('div');
      fallback.className='cup';fallback.innerHTML='<span>K</span>';
      visual.appendChild(fallback);
    }
    img.hidden=true;
  }
}

function watchDynamicUi(){
  const loyalty=document.querySelector('.loyalty-cups');
  if(loyalty){
    upgradeLoyaltyCups(loyalty);
    new MutationObserver(()=>upgradeLoyaltyCups(loyalty)).observe(loyalty,{childList:true,subtree:false});
  }
  const links=document.getElementById('externalLinks');
  if(links){
    decorateSocialLinks();
    new MutationObserver(decorateSocialLinks).observe(links,{childList:true,subtree:false});
  }
}

document.addEventListener('error',onImageError,true);
upgradeLoyaltyCups();
decorateSocialLinks();

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{
  promoteFinalStyle();
  watchDynamicUi();
  requestAnimationFrame(promoteFinalStyle);
},{once:true});
else{
  promoteFinalStyle();
  watchDynamicUi();
}

window.addEventListener('kapouch:catalog',()=>setTimeout(decorateSocialLinks,0));
window.addEventListener('load',()=>{promoteFinalStyle();upgradeLoyaltyCups();decorateSocialLinks()});
})();
