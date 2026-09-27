(function(){
'use strict';
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const TOKEN_KEY='kapouch_customer_auth_token';
const CART_KEY='kapouch_customer_cart';
const SPEND_KEY='kapouch_loyalty_spend';
const MODE_KEY='kapouch_loyalty_mode';
const WHEEL_KEY='kapouch_wheel_reward_id';
const cartList=document.getElementById('cartList');
const totalEl=document.getElementById('cartTotal');
const loyaltyHint=document.getElementById('loyaltyHint');
if(!cartList||!totalEl)return;
const money=v=>Number(v||0).toLocaleString('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:2})+' ₽';
const points=v=>Number(v||0).toLocaleString('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:2});
const percent=v=>Number(v||0).toLocaleString('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:2});
const wordForm=(value,one,few,many)=>{const n=Math.abs(Number(value)||0);if(!Number.isInteger(n))return few;const n100=n%100,n10=n%10;if(n100>=11&&n100<=14)return many;if(n10===1)return one;if(n10>=2&&n10<=4)return few;return many};
let timer=0,requestSeq=0,lastQuote=null;
function token(){return String(localStorage.getItem(TOKEN_KEY)||'')}
function loyaltyMode(){const value=String(localStorage.getItem(MODE_KEY)||'gift');return ['gift','points','wheel','none'].includes(value)?value:'gift'}
function saveMode(value){const mode=['gift','points','wheel','none'].includes(value)?value:'gift';localStorage.setItem(MODE_KEY,mode);return mode}
function wheelRewardId(){const id=Number(localStorage.getItem(WHEEL_KEY)||0);return Number.isInteger(id)&&id>0?id:0}
function saveWheelReward(id){id=Number(id||0);if(Number.isInteger(id)&&id>0)localStorage.setItem(WHEEL_KEY,String(id));else localStorage.removeItem(WHEEL_KEY)}
function requestedSpend(){const n=Number(localStorage.getItem(SPEND_KEY)||0);return Number.isFinite(n)?Math.max(0,Math.round(n*100)/100):0}
function saveSpend(value){const n=Math.max(0,Math.round(Number(value||0)*100)/100);if(n>0)localStorage.setItem(SPEND_KEY,String(n));else localStorage.removeItem(SPEND_KEY);return n}
function cart(){try{const raw=JSON.parse(localStorage.getItem(CART_KEY)||'[]');if(!Array.isArray(raw))return [];return raw.map(x=>({product_id:Number(x.product_id||0),quantity:Math.max(1,Number(x.quantity||1)),modifiers:Array.isArray(x.modifiers)?x.modifiers.map(option_id=>({option_id:Number(option_id)})).filter(x=>x.option_id>0):[]})).filter(x=>x.product_id>0)}catch(e){return []}}
function ensureBox(){let box=document.getElementById('sixthDrinkCheckout');if(box)return box;box=document.createElement('section');box.id='sixthDrinkCheckout';box.className='sixth-drink-checkout';box.hidden=true;const hint=loyaltyHint||document.getElementById('checkoutError');if(hint)hint.insertAdjacentElement('beforebegin',box);return box}
function clear(){lastQuote=null;const box=ensureBox();box.hidden=true;box.innerHTML=''}
function renderCashback(q){if(!loyaltyHint)return;const total=Math.max(0,Number(q?.total||0)),rate=Math.max(0,Number(q?.loyalty_percent||0)),expected=Math.max(0,Number(q?.loyalty_expected||0));if(rate<=0){loyaltyHint.textContent='Бонусы за этот заказ не начисляются.';return}if(total<=0){loyaltyHint.textContent='К оплате 0 ₽ — бонусы за этот заказ не начисляются.';return}loyaltyHint.textContent='После выдачи начислим примерно '+points(expected)+' '+wordForm(expected,'бонус','бонуса','бонусов')+' ('+percent(rate)+'% от суммы к оплате).'}
function choiceMax(q){const balance=Math.max(0,Number(q?.loyalty_balance||0)),subtotal=Math.max(0,Number(q?.subtotal||0)),rate=Math.max(0,Math.min(100,Number(q?.loyalty_spend_percent??100)));return Math.max(0,Math.round(Math.min(balance,subtotal,subtotal*rate/100)*100)/100)}
function useWheel(wheelOffer){if(!wheelOffer)return;saveMode('wheel');saveSpend(0);if(wheelOffer.reward_id)saveWheelReward(wheelOffer.reward_id);schedule()}
function bindChoiceControls(q,giftOffer,wheelOffer){
  document.querySelectorAll('[data-loyalty-mode]').forEach(button=>button.onclick=()=>{
    const mode=String(button.dataset.loyaltyMode||'gift');
    if(mode==='gift'){saveMode('gift');saveSpend(0)}
    else if(mode==='points'){const max=choiceMax(q);saveMode('points');saveSpend(max)}
    else if(mode==='wheel')useWheel(wheelOffer);
    else{saveMode('none');saveSpend(0)}
    if(mode!=='wheel')schedule();
  });
  const wheelUse=document.getElementById('wheelRewardUse');
  if(wheelUse)wheelUse.onclick=()=>useWheel(wheelOffer);
  const wheelReset=document.getElementById('wheelRewardReset');
  if(wheelReset)wheelReset.onclick=()=>{saveMode(giftOffer?'gift':'none');saveSpend(0);schedule()};
  bindSpendControls(q,giftOffer,wheelOffer);
}
function bindSpendControls(q,giftOffer,wheelOffer){
  const input=document.getElementById('loyaltySpendInput'),all=document.getElementById('loyaltySpendAll'),reset=document.getElementById('loyaltySpendReset');
  const max=Math.max(0,Number(q?.loyalty_spend_max||0));
  if(input){
    const commit=()=>{let n=Number(String(input.value||'').replace(',','.'));if(!Number.isFinite(n))n=0;n=Math.max(0,Math.min(max,Math.round(n*100)/100));saveSpend(n);saveMode(n>0?'points':(giftOffer?'gift':(wheelOffer?'wheel':'none')));input.value=n?String(n):'';schedule()};
    input.addEventListener('change',commit);input.addEventListener('blur',commit);input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();commit();input.blur()}});
  }
  if(all)all.onclick=()=>{saveMode('points');saveSpend(max);schedule()};
  if(reset)reset.onclick=()=>{saveSpend(0);saveMode(giftOffer?'gift':(wheelOffer?'wheel':'none'));schedule()};
}
function render(q){
  lastQuote=q;const box=ensureBox(),reward=q?.reward||{},gift=q?.gift||null,giftOffer=q?.gift_offer||gift||null,wheelOffer=q?.wheel_offer||null,discount=Math.max(0,Number(q?.discount||0)),wheelDiscount=Math.max(0,Number(q?.wheel_discount||0));
  const balance=Math.max(0,Number(q?.loyalty_balance||0)),maxSpend=Math.max(0,Number(q?.loyalty_spend_max||0)),spent=Math.max(0,Number(q?.loyalty_spend||0)),spendPercent=Math.max(0,Math.min(100,Number(q?.loyalty_spend_percent??100))),selected=String(q?.loyalty_mode||loyaltyMode());
  const maxChoice=choiceMax(q),canPoints=balance>0&&maxChoice>0,benefitCount=(giftOffer?1:0)+(canPoints?1:0)+(wheelOffer?1:0),canChoose=benefitCount>1;
  if(selected!=='points'&&requestedSpend()>0)saveSpend(0);
  if(wheelOffer?.reward_id&&wheelRewardId()!==Number(wheelOffer.reward_id))saveWheelReward(wheelOffer.reward_id);
  if(Number.isFinite(Number(q?.total)))totalEl.textContent=money(q.total);renderCashback(q);
  const blocks=[];

  if(canChoose){
    const options=[];
    if(giftOffer)options.push('<button type="button" data-loyalty-mode="gift" class="loyalty-choice-option '+(selected==='gift'?'active':'')+'"><span class="choice-icon">🎁</span><span><strong>6-й напиток</strong><small>Скидка до '+money(giftOffer.gift_cap||giftOffer.discount||0)+'</small></span><b>'+(selected==='gift'?'✓':'')+'</b></button>');
    if(wheelOffer)options.push('<button type="button" data-loyalty-mode="wheel" class="loyalty-choice-option '+(selected==='wheel'?'active':'')+'"><span class="choice-icon">✦</span><span><strong>Приз колеса</strong><small>−'+percent(wheelOffer.percent)+'% на '+String(wheelOffer.product_name||'напиток')+'</small></span><b>'+(selected==='wheel'?'✓':'')+'</b></button>');
    if(canPoints)options.push('<button type="button" data-loyalty-mode="points" class="loyalty-choice-option '+(selected==='points'?'active':'')+'"><span class="choice-icon">★</span><span><strong>Списать бонусы</strong><small>До '+points(maxChoice)+' ★ на этот заказ</small></span><b>'+(selected==='points'?'✓':'')+'</b></button>');
    blocks.push('<div class="loyalty-choice"><div class="loyalty-choice-head"><strong>Как использовать лояльность?</strong><span>На один заказ можно выбрать только один вариант</span></div><div class="loyalty-choice-options">'+options.join('')+'</div></div>');
  }

  if(selected==='gift'&&discount>0&&gift){
    blocks.push('<div class="sixth-drink-checkout-row gift-row"><span>Подарок «6-й напиток»</span><strong>−'+money(discount)+'</strong></div><small>'+String(gift.product_name||'Напиток')+' — скидка до '+money(gift.gift_cap||discount)+'. Если напиток дороже, оплачивается только разница; добавки оплачиваются отдельно. Бонусы и приз колеса в этом заказе не списываются.</small>');
  }else if(selected!=='gift'&&giftOffer){
    blocks.push('<div class="sixth-drink-checkout-row gift-row saved"><span>Подарок «6-й напиток» сохранён</span><strong>🎁</strong></div><small>В этом заказе выбран другой вариант лояльности. Бесплатный напиток останется доступен.</small>');
  }else if(Number(reward.available_rewards||0)>0&&!giftOffer){
    blocks.push('<div class="sixth-drink-checkout-row gift-row saved"><span>Подарок доступен</span><strong>🎁</strong></div><small>В текущей корзине нет подходящего напитка. Подарок не сгорит и останется на следующий заказ.</small>');
  }

  if(selected==='wheel'&&wheelOffer){
    if(wheelDiscount>0)blocks.push('<div class="sixth-drink-checkout-row gift-row"><span>'+String(wheelOffer.title||'Приз колеса')+'</span><strong>−'+money(wheelDiscount)+'</strong></div><small>Скидка '+percent(wheelOffer.percent)+'% применяется к одному подходящему напитку «'+String(wheelOffer.product_name||'')+'». Добавки оплачиваются отдельно. <button type="button" class="loyalty-inline-reset" id="wheelRewardReset">Оставить приз на потом</button></small>');
    else blocks.push('<div class="sixth-drink-checkout-row gift-row saved"><span>Приз колеса сохранён</span><strong>✦</strong></div><small>В корзине пока нет напитка, к которому можно применить скидку.</small>');
  }else if(wheelOffer){
    blocks.push('<div class="sixth-drink-checkout-row gift-row saved"><span>'+String(wheelOffer.title||'Приз колеса')+' сохранён</span><strong>✦</strong></div><small>Можно применить −'+percent(wheelOffer.percent)+'% к подходящему напитку. <button type="button" class="loyalty-inline-reset" id="wheelRewardUse">Применить приз</button></small>');
  }

  const showPoints=canPoints&&((!giftOffer&&!wheelOffer)||selected==='points');
  if(showPoints){
    const requested=Math.min(maxSpend,requestedSpend());
    blocks.push('<div class="loyalty-spend"><div class="loyalty-spend-head"><div><strong>Списать бонусы</strong><span>Доступно '+points(balance)+' ★ · 1 бонус = 1 ₽</span></div>'+(spent>0?'<b>−'+money(spent)+'</b>':'')+'</div><div class="loyalty-spend-controls"><input id="loyaltySpendInput" type="number" inputmode="decimal" min="0" max="'+maxSpend.toFixed(2)+'" step="0.01" value="'+(requested>0?requested:'')+'" placeholder="0"><button type="button" id="loyaltySpendAll">Списать максимум</button>'+(requested>0?'<button type="button" class="reset" id="loyaltySpendReset">Не списывать</button>':'')+'</div><small>Можно списать до '+percent(spendPercent)+'% суммы заказа, но не больше доступного баланса. Другие награды лояльности останутся на потом.</small></div>');
  }else if(selected==='points'&&requestedSpend()>0){saveSpend(0)}

  if(!blocks.length){box.hidden=true;box.innerHTML='';return}
  box.innerHTML=blocks.join('<div class="loyalty-divider"></div>');box.hidden=false;bindChoiceControls(q,giftOffer,wheelOffer);
}
async function refresh(){clearTimeout(timer);const rows=cart(),t=token();if(!rows.length||!/^[a-f0-9]{64}$/.test(t)){saveSpend(0);saveMode('gift');clear();return}const seq=++requestSeq;try{const r=await fetch(apiBase+'/customer_order_quote.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-Customer-Token':t},body:JSON.stringify({items:rows,loyalty_spend:requestedSpend(),loyalty_mode:loyaltyMode(),wheel_reward_id:wheelRewardId()})});const d=await r.json().catch(()=>null);if(seq!==requestSeq)return;if(!r.ok||!d?.ok){clear();return}render(d.quote||{})}catch(e){if(seq===requestSeq)clear()}}
function schedule(){clearTimeout(timer);timer=setTimeout(refresh,120)}
new MutationObserver(schedule).observe(cartList,{childList:true,subtree:true,characterData:true});
window.addEventListener('kapouch-order-status',e=>{if(['completed','cancelled'].includes(String(e.detail?.status||'')))setTimeout(refresh,80)});
window.addEventListener('focus',refresh);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh()});
window.addEventListener('storage',e=>{if(e.key===TOKEN_KEY||e.key===CART_KEY||e.key===SPEND_KEY||e.key===MODE_KEY||e.key===WHEEL_KEY)refresh()});
window.addEventListener('kapouch-loyalty-spend-reset',()=>{saveSpend(0);saveMode('gift');refresh()});
window.addEventListener('kapouch:wheel',refresh);
schedule();
})();