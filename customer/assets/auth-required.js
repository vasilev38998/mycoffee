(function(){
'use strict';

const TOKEN_KEY='kapouch_customer_auth_token';
const tokenValid=()=>/^[a-f0-9]{64}$/.test(String(localStorage.getItem(TOKEN_KEY)||''));
const form=document.getElementById('checkoutForm');
const button=document.getElementById('checkoutButton');
const error=document.getElementById('checkoutError');
if(!form||!button)return;

let notice=document.getElementById('checkoutAuthRequired');
if(!notice){
  notice=document.createElement('div');
  notice.id='checkoutAuthRequired';
  notice.className='checkout-auth-required';
  notice.innerHTML='<strong>Войдите, чтобы оформить заказ</strong><span>Заказы доступны только авторизованным клиентам — так история, бонусы и шестой напиток всегда привязаны к вашему профилю.</span>';
  form.prepend(notice);
}

function sync(){
  const auth=tokenValid();
  notice.hidden=auth;
  button.dataset.authRequired=auth?'0':'1';
  if(!auth&&button.textContent.trim()==='Оформить заказ')button.textContent='Войти, чтобы оформить';
  if(auth&&button.textContent.trim()==='Войти, чтобы оформить')button.textContent='Оформить заказ';
}
function goToLogin(){
  if(error){error.textContent='Чтобы оформить заказ, сначала войдите в профиль по номеру телефона.';error.classList.add('show');}
  location.hash='#profile';
  setTimeout(()=>document.getElementById('authPhone')?.focus(),120);
}
form.addEventListener('submit',function(event){
  if(tokenValid())return;
  event.preventDefault();
  event.stopImmediatePropagation();
  goToLogin();
},true);
button.addEventListener('click',function(event){if(!tokenValid()){event.preventDefault();goToLogin();}},true);
window.addEventListener('storage',sync);
window.addEventListener('focus',sync);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)sync()});
const profileUser=document.getElementById('profileUser');
if(profileUser)new MutationObserver(sync).observe(profileUser,{attributes:true,attributeFilter:['hidden']});
sync();
})();
