(function(){
'use strict';
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'../api'};
const apiBase=String(cfg.apiBase||'../api').replace(/\/$/,'');
const $=id=>document.getElementById(id);
const money=v=>Number(v||0).toLocaleString('ru-RU',{maximumFractionDigits:2})+' ₽';
const esc=v=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
let token=localStorage.getItem('kapouch_customer_auth_token')||'';
let phone='';

async function api(path,options={}){
  const headers={'Accept':'application/json',...(options.body?{'Content-Type':'application/json'}:{}),...(token?{'Authorization':'Bearer '+token}:{}),...(options.headers||{})};
  const r=await fetch(apiBase+'/'+path,{cache:'no-store',...options,headers});
  const data=await r.json().catch(()=>({ok:false,error:'Некорректный ответ сервера.'}));
  if(!r.ok||!data.ok)throw new Error(data.error||'Ошибка запроса.');
  return data;
}
function showError(id,message){$(id).textContent=message;$(id).classList.add('show');}
function hideError(id){$(id).classList.remove('show');}
function showLogin(){
  $('loginCard').hidden=false;$('profileSection').hidden=true;$('codeForm').hidden=true;$('phoneForm').hidden=false;
}
function showProfile(){ $('loginCard').hidden=true;$('profileSection').hidden=false; }

$('phoneForm').addEventListener('submit',async e=>{
  e.preventDefault();hideError('phoneError');
  const button=$('sendCodeButton');button.disabled=true;button.textContent='Отправляем…';
  try{
    phone=$('authPhone').value.trim();
    const data=await api('customer_auth_request.php',{method:'POST',body:JSON.stringify({phone})});
    phone=data.auth.phone;$('phoneForm').hidden=true;$('codeForm').hidden=false;$('authCode').focus();
    $('codeHint').textContent='Код отправлен на '+phone+'. Он действует 5 минут.';
  }catch(err){showError('phoneError',err.message);}finally{button.disabled=false;button.textContent='Получить код';}
});

$('codeForm').addEventListener('submit',async e=>{
  e.preventDefault();hideError('codeError');
  const button=$('verifyCodeButton');button.disabled=true;button.textContent='Проверяем…';
  try{
    const data=await api('customer_auth_verify.php',{method:'POST',body:JSON.stringify({phone,code:$('authCode').value.trim()})});
    token=data.auth.token;localStorage.setItem('kapouch_customer_auth_token',token);await loadProfile();
  }catch(err){showError('codeError',err.message);}finally{button.disabled=false;button.textContent='Войти';}
});

$('changePhoneButton').addEventListener('click',()=>{$('codeForm').hidden=true;$('phoneForm').hidden=false;$('authCode').value='';});
$('logoutButton').addEventListener('click',async()=>{
  try{await api('customer_logout.php',{method:'POST'});}catch(e){}
  token='';localStorage.removeItem('kapouch_customer_auth_token');showLogin();
});

function renderOrders(orders){
  $('ordersList').innerHTML=orders.length?orders.map(o=>'<a class="account-row" href="index.html"><div><strong>#'+esc(o.order_number)+'</strong><span>'+esc(o.status_label)+' · '+esc(String(o.external_created_at||o.created_at||'').slice(0,16).replace('T',' '))+'</span></div><b>'+money(o.total_amount)+'</b></a>').join(''):'<div class="empty">Заказов по этому номеру пока нет.</div>';
}
function renderLoyalty(rows){
  $('loyaltyList').innerHTML=rows.length?rows.map(r=>'<div class="account-row"><div><strong>'+(r.operation_type==='earn'?'Начисление':'Операция')+'</strong><span>'+esc(r.note||'')+' · '+esc(String(r.created_at||'').slice(0,16))+'</span></div><b>'+((Number(r.amount)>=0)?'+':'')+money(r.amount)+'</b></div>').join(''):'<div class="empty">Пока нет операций с бонусами.</div>';
}
async function loadProfile(){
  if(!token){showLogin();return;}
  try{
    const data=await api('customer_profile.php');const p=data.profile;showProfile();
    $('profileBalance').textContent=money(p.customer.loyalty_balance);$('profilePhone').textContent=p.customer.phone;$('profileName').textContent=p.customer.name||'Гость';
    renderOrders(p.orders||[]);renderLoyalty(p.loyalty||[]);
  }catch(err){token='';localStorage.removeItem('kapouch_customer_auth_token');showLogin();}
}
loadProfile();
})();
