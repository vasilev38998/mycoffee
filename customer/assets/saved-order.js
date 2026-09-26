(function(){
'use strict';

const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const CART_KEY='kapouch_customer_cart';
const PRESET_KEY='kapouch_saved_order_v1';
const NOTICE_KEY='kapouch_saved_order_notice';
const $=id=>document.getElementById(id);
const esc=v=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));

function readJson(key,fallback){try{const value=JSON.parse(localStorage.getItem(key)||'');return value??fallback}catch(e){return fallback}}
function readCart(){
  const raw=readJson(CART_KEY,[]);
  if(Array.isArray(raw))return raw.map(x=>({key:String(x.key||''),product_id:Number(x.product_id||0),quantity:Math.max(1,Math.min(20,Number(x.quantity||1))),modifiers:Array.isArray(x.modifiers)?x.modifiers.map(Number).filter(Boolean):[]})).filter(x=>x.product_id>0);
  if(raw&&typeof raw==='object')return Object.entries(raw).map(([id,q])=>({key:'legacy-'+id,product_id:Number(id),quantity:Math.max(1,Math.min(20,Number(q||1))),modifiers:[]})).filter(x=>x.product_id>0);
  return [];
}
function readPreset(){const preset=readJson(PRESET_KEY,null);return preset&&preset.version===1&&Array.isArray(preset.lines)&&preset.lines.length?preset:null}
function lineKey(productId,modifiers){return Number(productId)+':'+[...(modifiers||[])].map(Number).filter(Boolean).sort((a,b)=>a-b).join(',')}
function itemCount(lines){return (lines||[]).reduce((sum,line)=>sum+Math.max(1,Number(line.quantity||1)),0)}
function itemWord(n){const x=Math.abs(Number(n)||0),n100=x%100,n10=x%10;if(n100>=11&&n100<=14)return'товаров';if(n10===1)return'товар';if(n10>=2&&n10<=4)return'товара';return'товаров'}

function injectStyle(){
  if($('savedOrderStyle'))return;
  const style=document.createElement('style');style.id='savedOrderStyle';style.textContent=`
#savedOrderCard{order:45!important;margin:9px 0 11px;padding:12px 13px;border:1px solid rgba(88,51,30,.08);border-radius:21px;background:linear-gradient(145deg,#fffdf9,#f7ede4);box-shadow:0 8px 22px rgba(72,39,22,.055)}
.saved-order-home{display:grid;grid-template-columns:38px minmax(0,1fr) auto;align-items:center;gap:10px}.saved-order-bean{width:38px;height:38px;display:grid;place-items:center;border-radius:14px;background:#f3dfcf;color:#673b27}.saved-order-bean svg{width:23px;height:23px;fill:currentColor}.saved-order-copy{min-width:0}.saved-order-copy small{display:block;color:#9b6547;font-size:8px;font-weight:900;letter-spacing:.14em;text-transform:uppercase}.saved-order-copy strong{display:block;margin-top:2px;color:#2b1810;font-size:13px;font-weight:950;line-height:1.2}.saved-order-copy span{display:block;margin-top:2px;color:#7f675a;font-size:9px;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.saved-order-use{border:0;border-radius:13px;padding:9px 11px;background:#ffc21c;color:#2a170e;font-size:9px;font-weight:950;white-space:nowrap}
.saved-order-tools{margin:10px 0 12px;padding:13px 14px;border:1px solid rgba(88,51,30,.08);border-radius:19px;background:#fffaf5}.saved-order-tools-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.saved-order-tools-copy strong{display:block;color:#2b1810;font-size:13px;font-weight:950}.saved-order-tools-copy span{display:block;margin-top:3px;color:#7f675a;font-size:9px;line-height:1.35}.saved-order-tools-actions{display:flex;gap:7px;margin-top:10px;flex-wrap:wrap}.saved-order-tools button{border:0;border-radius:12px;padding:9px 11px;font-size:9px;font-weight:900}.saved-order-save,.saved-order-load{background:#ffc21c;color:#2a170e}.saved-order-delete{background:#f2e5d9;color:#76503b}.saved-order-toast{position:fixed;left:50%;bottom:86px;z-index:220;max-width:min(88vw,390px);transform:translate(-50%,14px);padding:10px 13px;border-radius:14px;background:#2c190f;color:#fff8ef;font-size:10px;font-weight:800;box-shadow:0 12px 30px rgba(44,25,15,.2);opacity:0;pointer-events:none;transition:opacity .16s ease,transform .16s ease}.saved-order-toast.show{opacity:1;transform:translate(-50%,0)}
@media(max-width:380px){.saved-order-home{grid-template-columns:34px minmax(0,1fr) auto}.saved-order-bean{width:34px;height:34px}.saved-order-use{padding:8px 9px}.saved-order-copy span{max-width:150px}}
`;
  document.head.appendChild(style);
}

function beanIcon(){return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.1 2.4C8.2 1.5 4 5 2.7 9.7c-1.4 5 .7 10 4.9 11.6 4.4 1.7 9.5-.4 12.1-4.8 2.8-4.8 1.3-10.6-2.6-12.8-1.2-.7-2.5-1.1-4-1.3Zm2.6 2.7c1.9 3.1 1.8 6-.4 8.5-1.9 2.2-3.1 4.3-2.7 6.4-1.1.2-2.2.1-3.2-.3-.7-2.9.4-5.4 2.8-7.8 2.2-2.3 3.2-4.4 3.5-6.8Z"/></svg>'}
function toast(message){
  let node=$('savedOrderToast');if(!node){node=document.createElement('div');node.id='savedOrderToast';node.className='saved-order-toast';node.setAttribute('role','status');node.setAttribute('aria-live','polite');document.body.appendChild(node)}
  node.textContent=message;node.classList.remove('show');requestAnimationFrame(()=>node.classList.add('show'));clearTimeout(toast.timer);toast.timer=setTimeout(()=>node.classList.remove('show'),2500);
}

function cartSnapshot(lines){
  const cards=[...document.querySelectorAll('#cartList .cart-item')];
  const names=cards.slice(0,3).map(card=>{const title=String(card.querySelector('h3')?.textContent||'').trim(),variant=String(card.querySelector('.cart-variant')?.textContent||'').trim(),qty=String(card.querySelector('.qty-control strong')?.textContent||'1').trim();return (title+(variant?' · '+variant:'')+(Number(qty)>1?' × '+qty:'')).trim()}).filter(Boolean);
  const count=itemCount(lines),total=String($('cartTotal')?.textContent||'').trim();
  return {summary:names.join(' · ')||(count+' '+itemWord(count)),total};
}

function saveCurrent(){
  const lines=readCart();
  if(!lines.length){toast('Сначала добавьте что-нибудь в корзину.');return}
  const snapshot=cartSnapshot(lines);
  localStorage.setItem(PRESET_KEY,JSON.stringify({version:1,lines,summary:snapshot.summary,total:snapshot.total,saved_at:new Date().toISOString()}));
  render();toast('«Мой обычный» сохранён.');
}
function deletePreset(){
  localStorage.removeItem(PRESET_KEY);render();toast('Сохранённый заказ удалён.');
}

function catalogRules(data){
  const rules=new Map();
  for(const product of data?.products||[]){
    const groupsByProduct=product?.modifier_groups_by_product||{};
    for(const variant of product?.variants||[]){
      const id=Number(variant?.id||0);if(id<=0)continue;
      const options=new Set();
      for(const group of groupsByProduct[String(id)]||[])for(const option of group?.options||[]){const oid=Number(option?.id||0);if(oid>0)options.add(oid)}
      rules.set(id,options);
    }
  }
  return rules;
}
async function validatedLines(lines){
  try{
    const response=await fetch(apiBase+'/customer_catalog.php?saved_order='+Date.now(),{cache:'no-store',headers:{Accept:'application/json'}});
    const data=await response.json().catch(()=>null);if(!response.ok||!data?.ok)throw new Error('catalog');
    const rules=catalogRules(data),valid=[];let changed=false;
    for(const line of lines){
      const productId=Number(line.product_id||0),allowed=rules.get(productId);if(!allowed){changed=true;continue}
      const modifiers=(line.modifiers||[]).map(Number).filter(id=>allowed.has(id));if(modifiers.length!==(line.modifiers||[]).length)changed=true;
      valid.push({key:lineKey(productId,modifiers),product_id:productId,quantity:Math.max(1,Math.min(20,Number(line.quantity||1))),modifiers});
    }
    return {lines:valid,changed};
  }catch(e){return {lines:lines.map(line=>({key:lineKey(line.product_id,line.modifiers),product_id:Number(line.product_id),quantity:Math.max(1,Math.min(20,Number(line.quantity||1))),modifiers:(line.modifiers||[]).map(Number).filter(Boolean)})),changed:false,offline:true}}
}
async function loadPreset(){
  const preset=readPreset();if(!preset)return;
  const current=readCart();
  if(current.length&&!window.confirm('Заменить текущую корзину на «Мой обычный заказ»?'))return;
  const result=await validatedLines(preset.lines);
  if(!result.lines.length){toast('Позиции из сохранённого заказа сейчас недоступны.');return}
  localStorage.setItem(CART_KEY,JSON.stringify(result.lines));
  localStorage.removeItem('kapouch_checkout_request_id');
  const note=result.changed?'Заказ добавлен. Недоступные позиции или добавки пропущены.':(result.offline?'Заказ добавлен по сохранённым данным.':'«Мой обычный» добавлен в корзину.');
  localStorage.setItem(NOTICE_KEY,note);
  location.hash='cart';location.reload();
}

function ensureHomeCard(){
  const home=document.querySelector('.view[data-view="home"]');if(!home)return null;
  let card=$('savedOrderCard');if(!card){card=document.createElement('section');card.id='savedOrderCard';home.appendChild(card)}return card;
}
function renderHome(){
  const preset=readPreset(),card=ensureHomeCard();if(!card)return;
  if(!preset){card.hidden=true;card.innerHTML='';return}
  const count=itemCount(preset.lines),meta=(preset.total?esc(preset.total)+' · ':'')+count+' '+itemWord(count);
  card.innerHTML='<div class="saved-order-home"><div class="saved-order-bean">'+beanIcon()+'</div><div class="saved-order-copy"><small>БЫСТРЫЙ ЗАКАЗ</small><strong>Мой обычный</strong><span>'+esc(preset.summary||meta)+'</span></div><button class="saved-order-use" type="button">В корзину</button></div>';
  card.hidden=false;card.querySelector('.saved-order-use').onclick=loadPreset;
}
function ensureCartTools(){
  const checkout=$('checkoutCard'),view=document.querySelector('.view[data-view="cart"]');if(!view||!checkout)return null;
  let tools=$('savedOrderTools');if(!tools){tools=document.createElement('section');tools.id='savedOrderTools';tools.className='saved-order-tools';checkout.insertAdjacentElement('beforebegin',tools)}return tools;
}
function renderCartTools(){
  const tools=ensureCartTools();if(!tools)return;
  const preset=readPreset(),lines=readCart(),count=itemCount(lines);
  if(lines.length){
    tools.innerHTML='<div class="saved-order-tools-head"><div class="saved-order-tools-copy"><strong>'+(preset?'Обновить «Мой обычный»':'Сохранить любимый заказ')+'</strong><span>'+(preset?'Текущая корзина заменит сохранённый набор.':'Сохраните эту корзину и собирайте её потом одним нажатием.')+'</span></div></div><div class="saved-order-tools-actions"><button type="button" class="saved-order-save">'+(preset?'Обновить':'Сохранить')+'</button>'+(preset?'<button type="button" class="saved-order-delete">Удалить сохранённый</button>':'')+'</div>';
    tools.querySelector('.saved-order-save').onclick=saveCurrent;tools.querySelector('.saved-order-delete')?.addEventListener('click',deletePreset);
  }else if(preset){
    const savedCount=itemCount(preset.lines);tools.innerHTML='<div class="saved-order-tools-head"><div class="saved-order-tools-copy"><strong>Мой обычный заказ</strong><span>'+esc(preset.summary||savedCount+' '+itemWord(savedCount))+'</span></div></div><div class="saved-order-tools-actions"><button type="button" class="saved-order-load">Добавить в корзину</button><button type="button" class="saved-order-delete">Удалить</button></div>';
    tools.querySelector('.saved-order-load').onclick=loadPreset;tools.querySelector('.saved-order-delete').onclick=deletePreset;
  }else{
    tools.hidden=true;tools.innerHTML='';return;
  }
  tools.hidden=false;
}
function render(){renderHome();renderCartTools()}
function showPendingNotice(){const message=localStorage.getItem(NOTICE_KEY)||'';if(!message)return;localStorage.removeItem(NOTICE_KEY);setTimeout(()=>toast(message),180)}
function observeCart(){const list=$('cartList');if(!list)return;let pending=0;new MutationObserver(()=>{clearTimeout(pending);pending=setTimeout(renderCartTools,30)}).observe(list,{childList:true,subtree:true,characterData:true})}

injectStyle();
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{render();observeCart();showPendingNotice()},{once:true});else{render();observeCart();showPendingNotice()}
window.addEventListener('storage',event=>{if(event.key===PRESET_KEY||event.key===CART_KEY)render()});
window.addEventListener('kapouch:catalog',renderHome);
})();
