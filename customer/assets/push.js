(function(){
'use strict';
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'../api'};const apiBase=String(cfg.apiBase||'../api').replace(/\/$/,'');
const button=document.getElementById('pushToggleButton'),text=document.getElementById('pushStatusText'),error=document.getElementById('pushError');if(!button||!text)return;
function authToken(){return localStorage.getItem('kapouch_customer_auth_token')||'';}
function b64ToBytes(value){const pad='='.repeat((4-value.length%4)%4),base64=(value+pad).replace(/-/g,'+').replace(/_/g,'/'),raw=atob(base64),out=new Uint8Array(raw.length);for(let i=0;i<raw.length;i++)out[i]=raw.charCodeAt(i);return out;}
async function api(path,options={}){const token=authToken(),headers={'Accept':'application/json',...(options.body?{'Content-Type':'application/json'}:{}),...(token?{'X-Customer-Token':token}:{}),...(options.headers||{})};const r=await fetch(apiBase+'/'+path,{cache:'no-store',...options,headers});const data=await r.json().catch(()=>({ok:false,error:'Некорректный ответ сервера.'}));if(!r.ok||!data.ok)throw new Error(data.error||'Ошибка push-сервиса.');return data;}
function showError(message){error.textContent=message;error.classList.toggle('show',!!message);}
async function registration(){if(!('serviceWorker'in navigator))throw new Error('Этот браузер не поддерживает Service Worker.');return navigator.serviceWorker.ready;}
async function currentSubscription(){const reg=await registration();return reg.pushManager.getSubscription();}
async function render(){
  showError('');if(!('PushManager'in window)||!('Notification'in window)){button.disabled=true;button.textContent='Push не поддерживается';text.textContent='На этом устройстве Web Push недоступен.';return;}
  if(Notification.permission==='denied'){button.disabled=true;button.textContent='Уведомления запрещены';text.textContent='Разрешите уведомления для сайта в настройках браузера.';return;}
  try{const sub=await currentSubscription();if(sub){button.disabled=false;button.textContent='Отключить уведомления';text.textContent='Уведомления включены: готовность заказа, бонусы и важные новости.';}else{button.disabled=false;button.textContent='Включить уведомления';text.textContent='Получай уведомления, когда заказ готов и когда начислены бонусы.';}}catch(e){button.disabled=false;button.textContent='Включить уведомления';}
}
async function enable(){
  if(!authToken())throw new Error('Сначала войдите в профиль по номеру телефона.');
  let permission=Notification.permission;if(permission!=='granted')permission=await Notification.requestPermission();if(permission!=='granted')throw new Error('Разрешение на уведомления не выдано.');
  const reg=await registration();let sub=await reg.pushManager.getSubscription();if(!sub){const conf=await api('customer_push_config.php');sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:b64ToBytes(conf.push.public_key)});}
  await api('customer_push_subscribe.php',{method:'POST',body:JSON.stringify({subscription:sub.toJSON()})});
}
async function disable(){const sub=await currentSubscription();if(!sub)return;if(authToken()){try{await api('customer_push_unsubscribe.php',{method:'POST',body:JSON.stringify({endpoint:sub.endpoint})});}catch(e){}}await sub.unsubscribe();}
button.addEventListener('click',async()=>{button.disabled=true;showError('');try{const sub=await currentSubscription();if(sub)await disable();else await enable();}catch(e){showError(e.message);}finally{await render();}});
window.addEventListener('focus',render);document.addEventListener('visibilitychange',()=>{if(!document.hidden)render();});render();
})();
