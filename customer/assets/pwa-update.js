(function(){
'use strict';
if(window.__KAPOUCH_PWA_UPDATE_BOOTSTRAPPED)return;
window.__KAPOUCH_PWA_UPDATE_BOOTSTRAPPED=true;
if(!('serviceWorker' in navigator))return;
let refreshing=false;
function style(){
  if(document.getElementById('kapouchUpdateStyle'))return;
  const el=document.createElement('style');el.id='kapouchUpdateStyle';el.textContent=`
.kapouch-update{position:fixed;left:14px;right:14px;bottom:calc(88px + env(safe-area-inset-bottom));z-index:9998;display:flex;align-items:center;gap:12px;max-width:620px;margin:auto;padding:13px 14px;border:1px solid rgba(72,42,24,.12);border-radius:18px;background:rgba(255,252,247,.97);color:#2b1a13;box-shadow:0 16px 44px rgba(55,31,18,.18);backdrop-filter:blur(18px)}.kapouch-update[hidden]{display:none}.kapouch-update-copy{min-width:0;flex:1}.kapouch-update-copy strong{display:block;font-size:13px;line-height:1.2}.kapouch-update-copy span{display:block;margin-top:3px;color:#78655a;font-size:10px;line-height:1.35}.kapouch-update button{flex:0 0 auto;border:0;border-radius:12px;padding:10px 12px;background:#3b2216;color:#fff8ee;font:inherit;font-size:11px;font-weight:900;cursor:pointer}.kapouch-update button:disabled{opacity:.55}@media(max-width:380px){.kapouch-update{bottom:calc(82px + env(safe-area-inset-bottom));padding:11px 12px}.kapouch-update-copy span{display:none}}
`;
  document.head.appendChild(el);
}
function panel(){
  let el=document.getElementById('kapouchUpdate');if(el)return el;
  style();el=document.createElement('aside');el.id='kapouchUpdate';el.className='kapouch-update';el.hidden=true;el.setAttribute('role','status');el.innerHTML='<div class="kapouch-update-copy"><strong>Доступно обновление Kapouch</strong><span>Обновим приложение без смешивания старого и нового дизайна.</span></div><button type="button" id="kapouchUpdateButton">Обновить</button>';
  document.body.appendChild(el);return el;
}
function show(worker){
  if(!worker)return;const el=panel(),btn=el.querySelector('#kapouchUpdateButton');el.hidden=false;
  btn.onclick=()=>{btn.disabled=true;btn.textContent='Обновляем…';worker.postMessage({type:'SKIP_WAITING'});};
}
function watch(reg){
  if(reg.waiting&&navigator.serviceWorker.controller)show(reg.waiting);
  reg.addEventListener('updatefound',()=>{
    const worker=reg.installing;if(!worker)return;
    worker.addEventListener('statechange',()=>{if(worker.state==='installed'&&navigator.serviceWorker.controller)show(worker);});
  });
}
navigator.serviceWorker.addEventListener('controllerchange',()=>{if(refreshing)return;refreshing=true;location.reload();});
window.addEventListener('load',async()=>{try{const reg=await navigator.serviceWorker.ready;watch(reg);reg.update().catch(()=>{});}catch(e){}});
})();
