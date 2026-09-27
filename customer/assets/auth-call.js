(function(){
'use strict';

const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const TOKEN_KEY='kapouch_customer_auth_token';
const $=id=>document.getElementById(id);
let busy=false,pollTimer=0,selfChallenge='',selfExpiresAt=0;

function injectStyles(){
  if(document.getElementById('kapouchSelfCallStyles'))return;
  const style=document.createElement('style');style.id='kapouchSelfCallStyles';style.textContent=`
.self-call-panel{display:grid;gap:12px;margin-top:12px;padding:15px;border-radius:18px;background:linear-gradient(145deg,rgba(255,215,84,.15),rgba(255,255,255,.04));border:1px solid rgba(245,185,63,.32)}
.self-call-panel[hidden]{display:none}.self-call-top{display:flex;gap:11px;align-items:flex-start}.self-call-icon{flex:0 0 42px;height:42px;border-radius:14px;display:grid;place-items:center;background:var(--accent);color:#2f1a10;box-shadow:0 8px 18px rgba(245,185,63,.18)}.self-call-icon svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.self-call-copy strong{display:block;font-size:13px}.self-call-copy span{display:block;margin-top:3px;font-size:10px;line-height:1.4;color:var(--muted)}.self-call-number{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 12px;border-radius:14px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08)}.self-call-number b{font-size:15px;letter-spacing:.02em}.self-call-number small{display:block;margin-top:2px;color:var(--muted);font-size:9px}.self-call-action{display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;border-radius:14px;padding:12px 14px;background:var(--accent);color:#2e190e;font-size:12px;font-weight:900}.self-call-action svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2}.self-call-status{display:flex;align-items:center;gap:7px;font-size:10px;color:var(--muted)}.self-call-status i{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 5px rgba(245,185,63,.12);animation:selfCallPulse 1.5s ease-in-out infinite}.self-call-tools{display:flex;gap:8px;flex-wrap:wrap}.self-call-tools button{border:0;background:transparent;color:var(--accent);font:inherit;font-size:10px;font-weight:850;padding:4px 0}.auth-alt-hint{margin:8px 0 0;text-align:center;color:var(--muted);font-size:9px;line-height:1.35}@keyframes selfCallPulse{50%{transform:scale(1.35);opacity:.55}}@media(prefers-reduced-motion:reduce){.self-call-status i{animation:none}}
`;
  document.head.appendChild(style);
}
function setError(message,inCodeForm=false){
  const phoneError=$('phoneError'),codeError=$('codeError');
  if(phoneError){phoneError.classList.remove('show');phoneError.textContent='';}
  if(codeError){codeError.classList.remove('show');codeError.textContent='';}
  const selfError=$('selfCallError');if(selfError){selfError.textContent='';selfError.classList.remove('show');}
  if(!message)return;
  const target=selfChallenge?selfError:(inCodeForm?codeError:phoneError);
  if(target){target.textContent=String(message);target.classList.add('show');}
}
function stopPolling(){clearTimeout(pollTimer);pollTimer=0;}
function resetSelfCall(){stopPolling();selfChallenge='';selfExpiresAt=0;const panel=$('selfCallPanel');if(panel)panel.hidden=true;}
function ensureSelfCallPanel(){
  let panel=$('selfCallPanel');if(panel)return panel;
  const guest=$('profileGuest');if(!guest)return null;
  panel=document.createElement('section');panel.id='selfCallPanel';panel.className='self-call-panel';panel.hidden=true;panel.innerHTML='<div class="self-call-top"><div class="self-call-icon"><svg viewBox="0 0 24 24"><path d="M7.2 3.6 10 7.5 8.2 9.3c1.1 2.2 2.7 3.8 4.9 4.9l1.8-1.8 3.9 2.8-.8 4.2c-7.5.4-13.8-5.9-13.4-13.4z"/></svg></div><div class="self-call-copy"><strong>Позвоните сами для подтверждения</strong><span>Позвоните с того же номера, который указали при входе. SMS.ru автоматически сбросит звонок — деньги не спишутся.</span></div></div><div class="self-call-number"><div><b id="selfCallPhone">—</b><small>Номер действует около 5 минут</small></div><span>☎</span></div><a class="self-call-action" id="selfCallAction" href="#"><svg viewBox="0 0 24 24"><path d="M7.2 3.6 10 7.5 8.2 9.3c1.1 2.2 2.7 3.8 4.9 4.9l1.8-1.8 3.9 2.8-.8 4.2c-7.5.4-13.8-5.9-13.4-13.4z"/></svg>Позвонить и подтвердить</a><div class="self-call-status" id="selfCallStatus"><i></i><span>Ждём звонок… После него вход выполнится автоматически.</span></div><div class="form-error" id="selfCallError"></div><div class="self-call-tools"><button type="button" id="selfCallCheck">Проверить сейчас</button><button type="button" id="selfCallCancel">Изменить номер</button></div>';
  const codeForm=$('codeForm');if(codeForm)codeForm.insertAdjacentElement('afterend',panel);else guest.appendChild(panel);
  $('selfCallCheck').onclick=()=>pollSelfCall(true);$('selfCallCancel').onclick=()=>{resetSelfCall();if($('phoneForm'))$('phoneForm').hidden=false;if($('codeForm'))$('codeForm').hidden=true;$('authPhone')?.focus();};
  return panel;
}
async function requestIncomingCall(inCodeForm=false){
  if(busy)return;const phone=String($('authPhone')?.value||'').trim();if(!phone){setError('Введите номер телефона.',inCodeForm);return;}
  const primary=$('sendCodeButton');busy=true;setError('',inCodeForm);if(primary){primary.disabled=true;primary.textContent='Звоним…';}
  try{
    const response=await fetch(apiBase+'/customer_auth_request.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({phone,method:'call'})});
    const raw=await response.text();let data=null;try{data=JSON.parse(raw)}catch(e){}if(!response.ok||!data?.ok)throw new Error(data?.error||('Сервис временно недоступен (HTTP '+response.status+').'));
    resetSelfCall();const auth=data.auth||{},phoneForm=$('phoneForm'),codeForm=$('codeForm'),input=$('authCode'),hint=$('codeHint');if(phoneForm)phoneForm.hidden=true;if(codeForm)codeForm.hidden=false;if(input){input.value=auth.test_code?String(auth.test_code):'';input.maxLength=4;input.placeholder='Последние 4 цифры номера';input.focus();}if(hint)hint.textContent=auth.test_code?'Тестовый звонок: код '+auth.test_code:'Сейчас вам поступит звонок. Отвечать не нужно — введите последние 4 цифры номера, с которого звонят.';setError('',true);
  }catch(error){setError(error?.message||'Не удалось выполнить звонок.',inCodeForm);}finally{busy=false;if(primary){primary.disabled=false;primary.textContent='Получить звонок';}}
}
async function requestSelfCall(inCodeForm=false){
  if(busy)return;const phone=String($('authPhone')?.value||'').trim();if(!phone){setError('Введите номер телефона.',inCodeForm);return;}
  const start=$('selfCallStartButton'),fallback=$('selfCallFallbackButton'),pressed=inCodeForm?fallback:start;busy=true;setError('',inCodeForm);if(pressed){pressed.disabled=true;pressed.textContent='Готовим номер…';}
  try{
    const response=await fetch(apiBase+'/customer_auth_request.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({phone,method:'self_call'})});const raw=await response.text();let data=null;try{data=JSON.parse(raw)}catch(e){}if(!response.ok||!data?.ok)throw new Error(data?.error||('Сервис временно недоступен (HTTP '+response.status+').'));
    const auth=data.auth||{},panel=ensureSelfCallPanel();selfChallenge=String(auth.challenge||'');selfExpiresAt=Date.now()+Number(auth.expires_in||300)*1000;if(!selfChallenge)throw new Error('Не удалось создать подтверждение звонком.');if($('phoneForm'))$('phoneForm').hidden=true;if($('codeForm'))$('codeForm').hidden=true;if(panel)panel.hidden=false;if($('selfCallPhone'))$('selfCallPhone').textContent=String(auth.call_phone_pretty||auth.call_phone||'');if($('selfCallAction'))$('selfCallAction').href=String(auth.call_href||('tel:+'+String(auth.call_phone||'')));if($('selfCallStatus'))$('selfCallStatus').querySelector('span').textContent='Ждём звонок… После него вход выполнится автоматически.';schedulePoll(900);
  }catch(error){setError(error?.message||'Не удалось подготовить подтверждение звонком.',inCodeForm);}finally{busy=false;if(start){start.disabled=false;start.textContent='Позвонить самому';}if(fallback){fallback.disabled=false;fallback.textContent='Не пришёл звонок? Позвонить самому';}}
}
function schedulePoll(delay=2200){stopPolling();if(!selfChallenge)return;pollTimer=setTimeout(()=>pollSelfCall(false),delay);}
async function pollSelfCall(manual=false){
  if(!selfChallenge||busy)return;if(Date.now()>selfExpiresAt){setError('Время подтверждения истекло. Запросите новый номер.');stopPolling();return;}busy=true;if(manual&&$('selfCallStatus'))$('selfCallStatus').querySelector('span').textContent='Проверяем звонок…';
  try{
    const response=await fetch(apiBase+'/customer_auth_verify.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({challenge:selfChallenge})});const raw=await response.text();let data=null;try{data=JSON.parse(raw)}catch(e){}if(!response.ok||!data?.ok)throw new Error(data?.error||('Не удалось проверить звонок (HTTP '+response.status+').'));const auth=data.auth||{};
    if(auth.pending){if($('selfCallStatus'))$('selfCallStatus').querySelector('span').textContent='Звонок пока не зафиксирован. Позвоните с указанного номера.';schedulePoll(Number(auth.poll_after||2)*1000);return;}
    if(auth.verified&&auth.token){stopPolling();localStorage.setItem(TOKEN_KEY,String(auth.token));if($('selfCallStatus'))$('selfCallStatus').querySelector('span').textContent='Номер подтверждён. Открываем профиль…';location.hash='profile';setTimeout(()=>location.reload(),280);return;}
    schedulePoll();
  }catch(error){setError(error?.message||'Не удалось проверить звонок.');if(!manual)schedulePoll(3500);}finally{busy=false;}
}
function install(){
  injectStyles();const phoneForm=$('phoneForm'),codeForm=$('codeForm'),primary=$('sendCodeButton'),input=$('authCode'),hint=$('codeHint'),guest=$('profileGuest');if(!phoneForm||!codeForm||!primary||!input||!hint)return;ensureSelfCallPanel();
  const title=guest?.querySelector('h2'),intro=guest?.querySelector('p');if(title)title.textContent='Войди по номеру телефона';if(intro)intro.textContent='Основной способ — короткий входящий звонок. Если он не проходит, можно бесплатно позвонить самому на номер подтверждения.';primary.textContent='Получить звонок';input.maxLength=4;input.placeholder='Последние 4 цифры номера';hint.textContent='После звонка введите последние 4 цифры номера звонящего.';
  let selfStart=$('selfCallStartButton');if(!selfStart){selfStart=document.createElement('button');selfStart.type='button';selfStart.id='selfCallStartButton';selfStart.className='text-button';selfStart.textContent='Позвонить самому';primary.insertAdjacentElement('afterend',selfStart);const note=document.createElement('p');note.className='auth-alt-hint';note.textContent='Резервный способ без SMS: вы звоните сами, звонок автоматически сбрасывается.';selfStart.insertAdjacentElement('afterend',note);}
  let selfFallback=$('selfCallFallbackButton');if(!selfFallback){selfFallback=document.createElement('button');selfFallback.type='button';selfFallback.id='selfCallFallbackButton';selfFallback.className='text-button';selfFallback.textContent='Не пришёл звонок? Позвонить самому';const verify=$('verifyCodeButton');if(verify)verify.insertAdjacentElement('afterend',selfFallback);}
  phoneForm.onsubmit=e=>{e.preventDefault();requestIncomingCall(false);};selfStart.onclick=()=>requestSelfCall(false);selfFallback.onclick=()=>requestSelfCall(true);
  const change=$('changePhoneButton');if(change)change.addEventListener('click',()=>{resetSelfCall();input.maxLength=4;input.placeholder='Последние 4 цифры номера';hint.textContent='После звонка введите последние 4 цифры номера звонящего.';});
  window.addEventListener('focus',()=>{if(selfChallenge)schedulePoll(250)});document.addEventListener('visibilitychange',()=>{if(!document.hidden&&selfChallenge)schedulePoll(250)});window.KAPOUCH_AUTH_CALL_READY=true;
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});else install();
})();