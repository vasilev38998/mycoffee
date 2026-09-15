(function(){
'use strict';
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const TOKEN_KEY='kapouch_customer_auth_token';
const CART_KEY='kapouch_customer_cart';
const SPEND_KEY='kapouch_loyalty_spend';
const cartList=document.getElementById('cartList');
const totalEl=document.getElementById('cartTotal');
const loyaltyHint=document.getElementById('loyaltyHint');
if(!cartList||!totalEl)return;
const money=v=>Number(v||0).toLocaleString('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:2})+' ₽';
const points=v=>Number(v||0).toLocaleString('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:2});
const percent=v=>Number(v||0).toLocaleString('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:2});
let timer=0,requestSeq=0,lastQuote=null;
function token(){return String(localStorage.getItem(TOKEN_KEY)||'')}
function requestedSpend(){const n=Number(localStorage.getItem(SPEND_KEY)||0);return Number.isFinite(n)?Math.max(0,Math.round(n*100)/100):0}
function saveSpend(value){const n=Math.max(0,Math.round(Number(value||0)*100)/100);if(n>0)localStorage.setItem(SPEND_KEY,String(n));else localStorage.removeItem(SPEND_KEY);return n}
function cart(){try{const raw=JSON.parse(localStorage.getItem(CART_KEY)||'[]');if(!Array.isArray(raw))return [];return raw.map(x=>({product_id:Number(x.product_id||0),quantity:Math.max(1,Number(x.quantity||1)),modifiers:Array.isArray(x.modifiers)?x.modifiers.map(option_id=>({option_id:Number(option_id)})).filter(x=>x.option_id>0):[]})).filter(x=>x.product_id>0)}catch(e){return []}}
function ensureBox(){let box=document.getElementById('sixthDrinkCheckout');if(box)return box;box=document.createElement('section');box.id='sixthDrinkCheckout';box.className='sixth-drink-checkout';box.hidden=true;const hint=loyaltyHint||document.getElementById('checkoutError');if(hint)hint.insertAdjacentElement('beforebegin',box);return box}
function clear(){lastQuote=null;const box=ensureBox();box.hidden=true;box.innerHTML=''}
function renderCashback(q){if(!loyaltyHint)return;const total=Math.max(0,Number(q?.total||0)),rate=Math.max(0,Number(q?.loyalty_percent||0)),expected=Math.max(0,Number(q?.loyalty_expected||0));if(rate<=0){loyaltyHint.textContent='Бонусы за этот заказ не начисляются.';return}if(total<=0){loyaltyHint.textContent='К оплате 0 ₽ — бонусы за этот заказ не начисляются.';return}loyaltyHint.textContent='После выдачи начислим примерно '+money(expected)+' бонусами ('+percent(rate)+'% от суммы к оплате).'}
function bindSpendControls(q){
  const input=document.getElementById('loyaltySpendInput'),all=document.getElementById('loyaltySpendAll'),reset=document.getElementById('loyaltySpendReset');
  const max=Math.max(0,Number(q?.loyalty_spend_max||0));
  if(input){
    const commit=()=>{let n=Number(String(input.value||'').replace(',','.'));if(!Number.isFinite(n))n=0;n=Math.max(0,Math.min(max,Math.round(n*100)/100));saveSpend(n);input.value=n?String(n):'';schedule()};
    input.addEventListener('change',commit);input.addEventListener('blur',commit);input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();commit();input.blur()}});
  }
  if(all)all.onclick=()=>{saveSpend(max);schedule()};
  if(reset)reset.onclick=()=>{saveSpend(0);schedule()};
}
function render(q){
  lastQuote=q;const box=ensureBox(),reward=q?.reward||{},gift=q?.gift||null,discount=Math.max(0,Number(q?.discount||0));
  const balance=Math.max(0,Number(q?.loyalty_balance||0)),maxSpend=Math.max(0,Number(q?.loyalty_spend_max||0)),spent=Math.max(0,Number(q?.loyalty_spend||0));
  if(Number.isFinite(Number(q?.total)))totalEl.textContent=money(q.total);renderCashback(q);
  const blocks=[];
  if(discount>0&&gift){blocks.push('<div class="sixth-drink-checkout-row gift-row"><span>Подарок «6-й напиток»</span><strong>−'+money(discount)+'</strong></div><small>'+String(gift.product_name||'Напиток')+' — скидка до '+money(gift.gift_cap||discount)+'. Если напиток дороже, оплачивается только разница; добавки оплачиваются отдельно.</small>')}
  else if(Number(reward.available_rewards||0)>0){blocks.push('<div class="sixth-drink-checkout-row gift-row"><span>Подарок доступен</span><strong>🎁</strong></div><small>Добавьте в корзину напиток из программы — скидка применится автоматически.</small>')}
  if(balance>0&&maxSpend>0){
    const requested=Math.min(maxSpend,requestedSpend());
    blocks.push('<div class="loyalty-spend"><div class="loyalty-spend-head"><div><strong>Списать бонусы</strong><span>Доступно '+points(balance)+' ★ · 1 бонус = 1 ₽</span></div>'+(spent>0?'<b>−'+money(spent)+'</b>':'')+'</div><div class="loyalty-spend-controls"><input id="loyaltySpendInput" type="number" inputmode="decimal" min="0" max="'+maxSpend.toFixed(2)+'" step="0.01" value="'+(requested>0?requested:'')+'" placeholder="0"><button type="button" id="loyaltySpendAll">Списать все</button>'+(requested>0?'<button type="button" class="reset" id="loyaltySpendReset">Не списывать</button>':'')+'</div><small>Бонусы применяются после скидки на 6-й напиток. Списать можно не больше суммы к оплате.</small></div>');
  }else if(requestedSpend()>0){saveSpend(0)}
  if(!blocks.length){box.hidden=true;box.innerHTML='';return}
  box.innerHTML=blocks.join('<div class="loyalty-divider"></div>');box.hidden=false;bindSpendControls(q);
}
async function refresh(){clearTimeout(timer);const rows=cart(),t=token();if(!rows.length||!/^[a-f0-9]{64}$/.test(t)){saveSpend(0);clear();return}const seq=++requestSeq;try{const r=await fetch(apiBase+'/customer_order_quote.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-Customer-Token':t},body:JSON.stringify({items:rows,loyalty_spend:requestedSpend()})});const d=await r.json().catch(()=>null);if(seq!==requestSeq)return;if(!r.ok||!d?.ok){clear();return}render(d.quote||{})}catch(e){if(seq===requestSeq)clear()}}
function schedule(){clearTimeout(timer);timer=setTimeout(refresh,120)}
new MutationObserver(schedule).observe(cartList,{childList:true,subtree:true,characterData:true});
window.addEventListener('kapouch-order-status',e=>{if(['completed','cancelled'].includes(String(e.detail?.status||'')))setTimeout(refresh,80)});
window.addEventListener('focus',refresh);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh()});
window.addEventListener('storage',e=>{if(e.key===TOKEN_KEY||e.key===CART_KEY||e.key===SPEND_KEY)refresh()});
window.addEventListener('kapouch-loyalty-spend-reset',()=>{saveSpend(0);refresh()});
schedule();
})();
