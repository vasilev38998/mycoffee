(function(){
'use strict';
function normalizePrice(text){return String(text||'').replace(/\s+/g,' ').trim()}
function refresh(){
  document.querySelectorAll('#modifierPicker .modifier-group').forEach(group=>{
    group.classList.remove('uniform-price');
    group.querySelector('.modifier-group-price')?.remove();
    const prices=[...group.querySelectorAll('.modifier-option b')].map(el=>normalizePrice(el.textContent));
    if(!prices.length)return;
    const first=prices[0];
    if(!first||prices.some(p=>p!==first))return;
    group.classList.add('uniform-price');
    if(first==='0 ₽'||first==='+0 ₽')return;
    const title=group.querySelector('.modifier-group-head strong');
    if(!title)return;
    const price=document.createElement('em');
    price.className='modifier-group-price';
    price.textContent=first;
    title.appendChild(price);
  });
}
const picker=document.getElementById('modifierPicker');
if(!picker)return;
let queued=false;
new MutationObserver(()=>{if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;refresh()})}).observe(picker,{childList:true,subtree:true});
refresh();
})();
