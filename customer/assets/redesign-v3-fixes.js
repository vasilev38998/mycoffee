(function(){
'use strict';

const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{};
const appOrigin=(()=>{try{return new URL(cfg.appBase||location.origin,location.href).origin}catch(e){return location.origin}})();
const apiOrigin=(()=>{try{return new URL(cfg.apiBase||location.origin,location.href).origin}catch(e){return location.origin}})();

function ensureV4Style(){
  let link=document.getElementById('kapouchRedesignV4');
  if(!link){
    link=document.createElement('link');
    link.id='kapouchRedesignV4';
    link.rel='stylesheet';
    link.href='assets/redesign-v4-polish.css?v=1';
    document.head.appendChild(link);
  }
  return link;
}

function promoteFinalStyle(){
  const v3=document.getElementById('kapouchRedesignV3');
  const v4=ensureV4Style();
  if(v3&&v3.parentNode===document.head)document.head.appendChild(v3);
  if(v4&&v4.parentNode===document.head)document.head.appendChild(v4);
}

function cupSvg(){
  return '<svg viewBox="0 0 42 52" aria-hidden="true" focusable="false">'
    +'<ellipse class="cup-shadow" cx="21" cy="47.2" rx="11.5" ry="2.4"/>'
    +'<path class="cup-cap" d="M10 7.3c.4-2.1 2.1-3.5 4.2-3.5h13.6c2.1 0 3.8 1.4 4.2 3.5l.4 2.1H9.6l.4-2.1Z"/>'
    +'<rect class="cup-lid" x="6.5" y="8.8" width="29" height="6.1" rx="3.05"/>'
    +'<path class="cup-body" d="M9.2 14.2h23.6l-2.8 27c-.3 3-2.8 5.2-5.8 5.2h-6.4c-3 0-5.5-2.2-5.8-5.2l-2.8-27Z"/>'
    +'<path class="cup-highlight" d="M13.2 16.5h3.1l1.7 25.8h-1.1c-1.6 0-2.8-1.2-3-2.8l-2.2-21.3c-.1-.9.6-1.7 1.5-1.7Z"/>'
    +'<path class="cup-band" d="M11.6 25.1h18.8l-1.1 11.3H12.7l-1.1-11.3Z"/>'
    +'</svg>';
}

function upgradeCup(node){
  if(!(node instanceof HTMLElement)||!node.classList.contains('loyalty-cup')||node.dataset.cupV4==='1')return;
  const label=node.classList.contains('gift')?'★':String(node.textContent||'').trim();
  node.dataset.cupV4='1';
  node.innerHTML=cupSvg()+'<span>'+label+'</span>';
}

function upgradeLoyaltyCups(root=document){
  root.querySelectorAll?.('.loyalty-cup').forEach(upgradeCup);
}

function socialSvg(kind){
  if(kind==='vk')return '<svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="24" fill="#2787F5"/><path fill="#fff" d="M24.8 34C14.4 34 8.5 26.9 8.3 15.1h5.2c.2 8.7 4 12.4 7 13.2V15.1h4.9v7.5c3.8-.4 7.7-3.8 9-7.5h4.9c-1 4.6-5 8-7.8 9.4 2.8 1.1 7.3 4 9 9.5h-5.4c-1.5-3.6-5.1-6.4-9.7-6.9V34h-.6Z"/></svg>';
  if(kind==='max')return '<svg viewBox="0 0 48 48" aria-hidden="true"><defs><linearGradient id="kapouch-max-gradient" x1="5" y1="5" x2="43" y2="43" gradientUnits="userSpaceOnUse"><stop stop-color="#786BFF"/><stop offset=".52" stop-color="#4F8CFF"/><stop offset="1" stop-color="#35C4E8"/></linearGradient></defs><circle cx="24" cy="24" r="24" fill="url(#kapouch-max-gradient)"/><path fill="#fff" d="M12.6 32.7V15.3h5.2l6.2 9.3 6.2-9.3h5.2v17.4h-4.8V22.5L25.8 30h-3.6l-4.8-7.5v10.2h-4.8Z"/></svg>';
  if(kind==='telegram')return '<svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="24" fill="#2AABEE"/><path fill="#fff" d="M11.7 23.1 35.3 14c1.1-.4 2 .3 1.6 1.9l-4 18.8c-.3 1.3-1.1 1.6-2.2 1l-6.1-4.5-3 2.9c-.3.3-.6.6-1.2.6l.4-6.2 11.4-10.3c.5-.4-.1-.7-.8-.3l-14 8.8-6-1.9c-1.3-.4-1.4-1.2.3-1.7Z"/></svg>';
  return '';
}

function decorateSocialLinks(){
  const box=document.getElementById('externalLinks');
  if(!box)return;
  box.querySelectorAll('a').forEach(link=>{
    const href=String(link.getAttribute('href')||'').toLowerCase();
    const rawText=String(link.textContent||'').trim();
    const text=rawText.toLowerCase();
    let kind='';
    if(link.dataset.kapouchMax==='1'||text.startsWith('max')||href.includes('max.ru'))kind='max';
    else if(text.startsWith('vk')||href.includes('vk.com'))kind='vk';
    else if(text.startsWith('telegram')||href.includes('t.me/'))kind='telegram';
    if(!kind){link.classList.add('profile-external-link');return}
    if(link.dataset.socialIcon===kind)return;
    const label=kind==='vk'?'VK':kind==='max'?'MAX':'Telegram';
    link.dataset.socialIcon=kind;
    link.classList.add('social-icon-link');
    link.setAttribute('aria-label',label);
    link.setAttribute('title',label);
    link.innerHTML='<span class="social-mark">'+socialSvg(kind)+'</span><small>'+label+'</small>';
  });
}

function imageFilename(src){
  let url;try{url=new URL(src,location.href)}catch(e){return ''}
  const queryFile=url.searchParams.get('f');
  if(queryFile&&/^[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$/i.test(queryFile))return queryFile;
  const base=decodeURIComponent(url.pathname.split('/').pop()||'');
  return /^[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$/i.test(base)?base:'';
}

function imageFallbackCandidates(src){
  const filename=imageFilename(src);
  if(!filename)return [];
  const f=encodeURIComponent(filename);
  return [
    appOrigin+'/uploads/products/'+f,
    appOrigin+'/customer/uploads/products/'+f,
    apiOrigin+'/customer/uploads/products/'+f,
    apiOrigin+'/uploads/products/'+f,
    location.origin+'/uploads/products/'+f,
    location.origin+'/customer/uploads/products/'+f
  ].filter((value,index,array)=>value!==src&&array.indexOf(value)===index);
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
    img.removeAttribute('hidden');
    img.hidden=false;
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
ensureV4Style();
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

window.addEventListener('kapouch:catalog',()=>setTimeout(()=>{decorateSocialLinks();upgradeLoyaltyCups()},0));
window.addEventListener('load',()=>{promoteFinalStyle();upgradeLoyaltyCups();decorateSocialLinks()});
})();
