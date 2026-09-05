(function(){
'use strict';
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'../api'};
const apiBase=String(cfg.apiBase||'../api').replace(/\/$/,'');
const token=()=>localStorage.getItem('kapouch_customer_auth_token')||'';
const profile=document.getElementById('profileUser');
if(!profile)return;

const card=document.createElement('div');
card.className='profile-card';
card.id='receiptEmailCard';
card.innerHTML='<div class="eyebrow-client">ЭЛЕКТРОННАЯ ПОЧТА</div><h2>Чеки и оплата по СБП</h2><p>Укажи email — ЮKassa отправит на него фискальный чек после оплаты.</p><form id="receiptEmailForm" class="auth-form"><input id="receiptEmail" type="email" required maxlength="254" autocomplete="email" placeholder="name@example.com"><div class="form-error" id="receiptEmailError"></div><button class="accent-button" id="receiptEmailButton">Сохранить email</button></form><p class="auth-hint" id="receiptEmailHint">Для «Оплаты по СБП» email обязателен.</p>';
const balance=profile.querySelector('.profile-balance');
if(balance)balance.insertAdjacentElement('afterend',card);else profile.prepend(card);

const input=document.getElementById('receiptEmail');
const form=document.getElementById('receiptEmailForm');
const error=document.getElementById('receiptEmailError');
const hint=document.getElementById('receiptEmailHint');
const button=document.getElementById('receiptEmailButton');

async function call(method,body){
  const t=token();if(!t)throw new Error('Сначала войдите в профиль.');
  const r=await fetch(apiBase+'/customer_profile.php',{method,cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-Customer-Token':t},...(body?{body:JSON.stringify(body)}:{})});
  const d=await r.json().catch(()=>null);if(!r.ok||!d||!d.ok)throw new Error(d?.error||'Не удалось сохранить email.');return d;
}
async function load(){
  if(!token())return;
  try{const d=await call('GET');const email=String(d.profile?.customer?.email||'');input.value=email;hint.textContent=email?'Чеки будут приходить на '+email+'.':'Для «Оплаты по СБП» email обязателен.';}catch(e){}
}
form.addEventListener('submit',async e=>{
  e.preventDefault();error.classList.remove('show');button.disabled=true;button.textContent='Сохраняем…';
  try{const email=input.value.trim();const d=await call('POST',{email});const saved=String(d.profile?.customer?.email||email);input.value=saved;hint.textContent='Сохранено. Чеки будут приходить на '+saved+'.';}
  catch(err){error.textContent=err.message||'Не удалось сохранить email.';error.classList.add('show');}
  finally{button.disabled=false;button.textContent='Сохранить email';}
});

const observer=new MutationObserver(()=>{if(!profile.hidden)load();});observer.observe(profile,{attributes:true,attributeFilter:['hidden']});
if(!profile.hidden)load();
})();
