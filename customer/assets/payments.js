(function(){
'use strict';
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'../api'};
const apiBase=String(cfg.apiBase||'../api').replace(/\/$/,'');
const form=document.getElementById('checkoutForm');
const box=document.getElementById('paymentMethods');
const error=document.getElementById('checkoutError');
const button=document.getElementById('checkoutButton');
if(!form||!box||!button)return;
let methods=[];
const esc=v=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
function requestId(){return window.crypto?.randomUUID?crypto.randomUUID():Date.now().toString(36)+'-'+Math.random().toString(36).slice(2)}
function cart(){try{const raw=JSON.parse(localStorage.getItem('kapouch_customer_cart')||'[]');if(Array.isArray(raw))return raw.map(x=>({product_id:Number(x.product_id||0),quantity:Math.max(1,Number(x.quantity||1)),modifiers:Array.isArray(x.modifiers)?x.modifiers.map(Number).filter(Boolean):[]})).filter(x=>x.product_id>0);if(raw&&typeof raw==='object')return Object.entries(raw).map(([id,q])=>({product_id:Number(id),quantity:Math.max(1,Number(q||1)),modifiers:[]})).filter(x=>x.product_id>0)}catch(e){}return []}
function selected(){return form.querySelector('input[name="payment_method"]:checked')?.value||''}
function render(){
  if(!methods.length){box.innerHTML='<div class="payment-method-empty">Сейчас нет доступных способов оплаты.</div>';button.disabled=true;return}
  box.innerHTML='<div class="payment-methods-title">Способ оплаты</div><div class="payment-method-list">'+methods.map((m,i)=>'<label class="payment-method"><input type="radio" name="payment_method" value="'+esc(m.id)+'" '+(i===0?'checked':'')+'><span class="payment-method-copy"><strong>'+esc(m.label)+'</strong><span>'+(m.id==='sbp'?'Оплата онлайн через приложение вашего банка':'Оплата при получении заказа')+'</span></span></label>').join('')+'</div>'+(methods.some(m=>m.id==='sbp')?'<div class="payment-online-note">Для СБП заказ попадёт бариста только после подтверждения оплаты банком.</div>':'');
}
async function loadMethods(){try{const r=await fetch(apiBase+'/customer_catalog.php?_='+Date.now(),{cache:'no-store',headers:{Accept:'application/json'}});const d=await r.json();methods=(d&&d.ok&&d.shop&&Array.isArray(d.shop.payment_methods))?d.shop.payment_methods:[];render()}catch(e){methods=[];render()}}
function showError(message){error.textContent=message;error.classList.add('show')}
async function submit(e){
  e.preventDefault();e.stopImmediatePropagation();
  const rows=cart();if(!rows.length){showError('Корзина пустая.');return}
  const method=selected();if(!method){showError('Выберите способ оплаты.');return}
  let checkoutId=localStorage.getItem('kapouch_checkout_request_id')||'';if(!checkoutId){checkoutId=requestId();localStorage.setItem('kapouch_checkout_request_id',checkoutId)}
  const f=new FormData(form);const payload={client_order_id:checkoutId,name:String(f.get('name')||''),phone:String(f.get('phone')||''),comment:String(f.get('comment')||''),fulfillment_type:'pickup',payment_method:method,items:rows.map(x=>({product_id:x.product_id,quantity:x.quantity,modifiers:x.modifiers.map(option_id=>({option_id}))}))};
  error.classList.remove('show');button.disabled=true;button.textContent=method==='sbp'?'Создаём оплату СБП…':'Оформляем…';
  try{
    const r=await fetch(apiBase+'/customer_order.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify(payload)});const d=await r.json().catch(()=>null);if(!r.ok||!d||!d.ok)throw new Error(d?.error||'Не удалось оформить заказ.');
    const order=d.order||{};if(order.tracking_token){localStorage.setItem('kapouch_tracking_token',order.tracking_token)}
    if(method==='sbp'){
      if(!order.payment_url)throw new Error('Сбер не вернул ссылку для оплаты.');
      location.assign(order.payment_url);return;
    }
    localStorage.removeItem('kapouch_customer_cart');localStorage.removeItem('kapouch_checkout_request_id');location.replace('./#home');location.reload();
  }catch(err){if(method==='sbp')localStorage.removeItem('kapouch_checkout_request_id');showError(err.message||'Не удалось оформить заказ.');button.disabled=false;button.textContent='Оформить заказ'}
}
form.addEventListener('submit',submit,true);
loadMethods();
})();
