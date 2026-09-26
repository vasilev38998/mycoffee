(function(){
'use strict';

let currentOrder=null,timer=0;
const $=id=>document.getElementById(id);

function injectStyle(){
  if($('pickupCountdownStyle'))return;
  const style=document.createElement('style');style.id='pickupCountdownStyle';style.textContent=`
.pickup-countdown{display:flex;align-items:center;gap:9px;margin:0 14px 12px;padding:10px 12px;border-radius:15px;background:rgba(255,194,28,.10);border:1px solid rgba(255,194,28,.13)}.pickup-countdown-icon{width:31px;height:31px;flex:0 0 31px;display:grid;place-items:center;border-radius:11px;background:rgba(255,194,28,.14);color:#ffc21c}.pickup-countdown-icon svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.pickup-countdown-copy{min-width:0}.pickup-countdown-copy strong{display:block;color:#f3e7da;font-size:11px;font-weight:900}.pickup-countdown-copy span{display:block;margin-top:2px;color:#a9988b;font-size:9px;line-height:1.3}.current-order-card.ready .pickup-countdown{background:rgba(255,194,28,.16);border-color:rgba(255,194,28,.22)}.current-order-card.ready .pickup-countdown-copy strong{color:#ffc21c}
`;
  document.head.appendChild(style);
}
function clockIcon(){return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3.2 2"/></svg>'}
function parsePromised(value){
  const raw=String(value||'').trim();if(!raw)return null;
  const normalized=raw.includes('T')?raw:raw.replace(' ','T');
  const date=new Date(normalized);return Number.isNaN(date.getTime())?null:date;
}
function pluralMinutes(value){const n=Math.abs(Number(value)||0),n100=n%100,n10=n%10;if(n100>=11&&n100<=14)return'минут';if(n10===1)return'минуту';if(n10>=2&&n10<=4)return'минуты';return'минут'}
function copy(order){
  if(!order)return null;
  if(order.status==='ready')return ['Можно забирать сейчас','Заказ уже готов — приходите в кофейню.'];
  if(order.status==='completed'||order.status==='cancelled'||order.status==='awaiting_payment')return null;
  const promised=parsePromised(order.promised_at);if(!promised)return null;
  const diff=Math.round((promised.getTime()-Date.now())/60000),time=String(order.promised_display||promised.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'}));
  if(diff>1)return ['Примерно через '+diff+' '+pluralMinutes(diff),'Ориентировочное время готовности — '+time+'.'];
  if(diff>=0)return ['Уже почти готово','Ориентировочное время готовности — '+time+'.'];
  if(diff>=-10)return ['Плановое время наступило','Проверяем статус у кофейни. Обычно осталось совсем немного.'];
  return ['Готовность уточняется','Плановое время было '+time+'. Следим за актуальным статусом.'];
}
function render(){
  clearTimeout(timer);
  const card=$('currentOrderCard');if(!card||card.hidden){timer=setTimeout(render,30000);return}
  const text=copy(currentOrder),existing=$('pickupCountdown');
  if(!text){existing?.remove();return}
  let box=existing;if(!box){box=document.createElement('div');box.id='pickupCountdown';box.className='pickup-countdown';const head=card.querySelector('.current-order-head');if(head)head.insertAdjacentElement('afterend',box);else card.prepend(box)}
  box.innerHTML='<div class="pickup-countdown-icon">'+clockIcon()+'</div><div class="pickup-countdown-copy"><strong>'+text[0]+'</strong><span>'+text[1]+'</span></div>';
  timer=setTimeout(render,30000);
}

injectStyle();
window.addEventListener('kapouch-order-status',event=>{currentOrder=event.detail||null;render()});
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&currentOrder)render()});
})();
