(function(){
'use strict';

const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const $=id=>document.getElementById(id);
let busy=false;

function setError(message,inCodeForm=false){
  const phoneError=$('phoneError'),codeError=$('codeError');
  if(phoneError){phoneError.classList.remove('show');phoneError.textContent='';}
  if(codeError){codeError.classList.remove('show');codeError.textContent='';}
  if(!message)return;
  const target=inCodeForm?codeError:phoneError;
  if(target){target.textContent=String(message);target.classList.add('show');}
}

async function requestAuth(method,inCodeForm=false){
  if(busy)return;
  const phone=String($('authPhone')?.value||'').trim();
  if(!phone){setError('Введите номер телефона.',inCodeForm);return;}
  const primary=$('sendCodeButton'),smsStart=$('smsStartButton'),smsFallback=$('smsFallbackButton');
  const pressed=method==='sms'?(inCodeForm?smsFallback:smsStart):primary;
  busy=true;
  setError('',inCodeForm);
  if(pressed){pressed.disabled=true;pressed.textContent=method==='call'?'Звоним…':'Отправляем SMS…';}
  try{
    const response=await fetch(apiBase+'/customer_auth_request.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({phone,method})});
    const raw=await response.text();let data=null;try{data=JSON.parse(raw)}catch(e){}
    if(!response.ok||!data?.ok)throw new Error(data?.error||('Сервис временно недоступен (HTTP '+response.status+').'));
    const auth=data.auth||{},delivery=String(auth.delivery||method);
    const phoneForm=$('phoneForm'),codeForm=$('codeForm'),input=$('authCode'),hint=$('codeHint');
    if(phoneForm)phoneForm.hidden=true;if(codeForm)codeForm.hidden=false;
    if(input){input.value=auth.test_code?String(auth.test_code):'';input.maxLength=delivery==='call'?4:6;input.placeholder=delivery==='call'?'Последние 4 цифры номера':'Код из SMS';input.focus();}
    if(hint){
      if(auth.test_code)hint.textContent=(delivery==='call'?'Тестовый звонок: код ':'Тестовое SMS: код ')+auth.test_code;
      else if(delivery==='call')hint.textContent='Сейчас вам поступит звонок. Отвечать не нужно — введите последние 4 цифры номера, с которого звонят.';
      else hint.textContent='SMS с кодом отправлено на '+String(auth.phone||phone)+'. Код действует 5 минут.';
    }
    if(smsFallback)smsFallback.hidden=delivery!=='call';
    setError('',true);
  }catch(error){setError(error?.message||'Не удалось подтвердить номер.',inCodeForm);}
  finally{
    busy=false;
    if(primary){primary.disabled=false;primary.textContent='Получить звонок';}
    if(smsStart){smsStart.disabled=false;smsStart.textContent='Получить код по SMS';}
    if(smsFallback){smsFallback.disabled=false;smsFallback.textContent='Не пришёл звонок? Получить код по SMS';}
  }
}

function install(){
  const phoneForm=$('phoneForm'),codeForm=$('codeForm'),primary=$('sendCodeButton'),input=$('authCode'),hint=$('codeHint'),guest=$('profileGuest');
  if(!phoneForm||!codeForm||!primary||!input||!hint)return;

  const title=guest?.querySelector('h2'),intro=guest?.querySelector('p');
  if(title)title.textContent='Войди по номеру телефона';
  if(intro)intro.textContent='Мы позвоним на номер. Введите последние 4 цифры входящего номера — отвечать на звонок не нужно.';
  primary.textContent='Получить звонок';
  input.maxLength=4;input.placeholder='Последние 4 цифры номера';
  hint.textContent='После звонка введите последние 4 цифры номера звонящего.';

  let smsStart=$('smsStartButton');
  if(!smsStart){smsStart=document.createElement('button');smsStart.type='button';smsStart.id='smsStartButton';smsStart.className='text-button';smsStart.textContent='Получить код по SMS';primary.insertAdjacentElement('afterend',smsStart);}
  let smsFallback=$('smsFallbackButton');
  if(!smsFallback){smsFallback=document.createElement('button');smsFallback.type='button';smsFallback.id='smsFallbackButton';smsFallback.className='text-button';smsFallback.textContent='Не пришёл звонок? Получить код по SMS';const verify=$('verifyCodeButton');if(verify)verify.insertAdjacentElement('afterend',smsFallback);}

  phoneForm.onsubmit=function(event){event.preventDefault();requestAuth('call',false);};
  smsStart.onclick=function(){requestAuth('sms',false);};
  smsFallback.onclick=function(){requestAuth('sms',true);};
  const change=$('changePhoneButton');
  if(change)change.addEventListener('click',function(){smsFallback.hidden=false;input.maxLength=4;input.placeholder='Последние 4 цифры номера';hint.textContent='После звонка введите последние 4 цифры номера звонящего.';});
  window.KAPOUCH_AUTH_CALL_READY=true;
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});else install();
})();
