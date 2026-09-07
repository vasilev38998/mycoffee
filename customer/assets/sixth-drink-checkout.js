(function(){
'use strict';
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const TOKEN_KEY='kapouch_customer_auth_token';
const cartList=document.getElementById('cartList');
const totalEl=document.getElementById('cartTotal');
const loyaltyHint=document.getElementById('loyaltyHint');
if(!cartList||!totalEl)return;
const money=v=>Number(v||0).toLocaleString('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:2})+' ₽';
const percent=v=>Number(v||0).toLocaleString('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:2});
let timer=0,requestSeq=0;
function token(){return String(localStorage.getItem(TOKEN_KEY)||'')}
function cart(){try{const raw=JSON.parse(localStorage.getItem('kapouch_customer_cart')||'[]');if(!Array.isArray(raw))return [];return raw.map(x=>({product_id:Number(x.product_id||0),quantity:Math.max(1,Number(x.quantity||1)),modifiers:Array.isArray(x.modifiers)?x.modifiers.map(option_id=>({option_id:Number(option_id)})).filter(x=>x.option_id>0):[]})).filter(x=>x.product_id>0)}catch(e){return []}}
function ensureBox(){let box=document.getElementById('sixthDrinkCheckout');if(box)return box;box=document.createElement('section');box.id='sixthDrinkCheckout';box.className='sixth-drink-checkout';box.hidden=true;const hint=loyaltyHint||document.getElementById('checkoutError');if(hint)hint.insertAdjacentElement('beforebegin',box);return box}
function clear(){const box=ensureBox();box.hidden=true;box.innerHTML=''}
function renderCashback(q){if(!loyaltyHint)return;const total=Math.max(0,Number(q?.total||0)),rate=Math.max(0,Number(q?.loyalty_percent||0)),expected=Math.max(0,Number(q?.loyalty_expected||0));if(rate<=0){loyaltyHint.textContent='Бонусы за этот заказ не начисляются.';return}if(total<=0){loyaltyHint.textContent='К оплате 0 ₽ — бонусы за этот заказ не начисляются.';return}loyaltyHint.textContent='После выдачи начислим примерно '+money(expected)+' бонусами ('+percent(rate)+'% от суммы к оплате).'}
function render(q){const box=ensureBox(),reward=q?.reward||{},gift=q?.gift||null,discount=Number(q?.discount||0);if(Number.isFinite(Number(q?.total)))totalEl.textContent=money(q.total);renderCashback(q);if(discount>0&&gift){box.innerHTML='<div class="sixth-drink-checkout-row"><span>Подарок «6-й напиток»</span><strong>−'+money(discount)+'</strong></div><small>'+String(gift.product_name||'Напиток')+' — скидка до '+money(gift.gift_cap||discount)+'. Если напиток дороже, оплачивается только разница; добавки оплачиваются отдельно.</small>';box.hidden=false;return}if(Number(reward.available_rewards||0)>0){box.innerHTML='<div class="sixth-drink-checkout-row"><span>Подарок доступен</span><strong>🎁</strong></div><small>Добавьте в корзину напиток из программы — скидка применится автоматически.</small>';box.hidden=false;return}clear()}
async function refresh(){clearTimeout(timer);const rows=cart(),t=token();if(!rows.length||!/^[a-f0-9]{64}$/.test(t)){clear();return}const seq=++requestSeq;try{const r=await fetch(apiBase+'/customer_order_quote.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-Customer-Token':t},body:JSON.stringify({items:rows})});const d=await r.json().catch(()=>null);if(seq!==requestSeq)return;if(!r.ok||!d?.ok){clear();return}render(d.quote||{})}catch(e){if(seq===requestSeq)clear()}}
function schedule(){clearTimeout(timer);timer=setTimeout(refresh,120)}
new MutationObserver(schedule).observe(cartList,{childList:true,subtree:true,characterData:true});
window.addEventListener('kapouch-order-status',e=>{if(['completed','cancelled'].includes(String(e.detail?.status||'')))setTimeout(refresh,80)});
window.addEventListener('focus',refresh);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh()});
window.addEventListener('storage',e=>{if(e.key===TOKEN_KEY||e.key==='kapouch_customer_cart')refresh()});
schedule();
})();
