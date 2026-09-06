(function(){
'use strict';

function nationalDigits(value){
  const raw=String(value||'').trim();
  let digits=raw.replace(/\D+/g,'');
  if(raw.startsWith('+7')&&digits.startsWith('7'))digits=digits.slice(1);
  else if(digits.length>10&&(digits.startsWith('7')||digits.startsWith('8')))digits=digits.slice(1);
  return digits.slice(0,10);
}
function formatPhone(value){
  const d=nationalDigits(value);if(!d)return '';
  let out='+7';
  if(d.length>0)out+=' ('+d.slice(0,3);
  if(d.length>=3)out+=')';
  if(d.length>3)out+=' '+d.slice(3,6);
  if(d.length>6)out+='-'+d.slice(6,8);
  if(d.length>8)out+='-'+d.slice(8,10);
  return out;
}
function canonicalPhone(value){const d=nationalDigits(value);return d.length===10?'+7'+d:''}
function bind(input){
  if(!input||input.dataset.kapouchPhoneMask==='1')return;
  input.dataset.kapouchPhoneMask='1';input.inputMode='tel';input.autocomplete='tel';input.maxLength=18;input.placeholder='+7 (999) 999-99-99';
  const apply=()=>{const before=input.value,formatted=formatPhone(before);if(before!==formatted)input.value=formatted};
  input.addEventListener('focus',()=>{if(!input.value){input.value='+7 ';requestAnimationFrame(()=>input.setSelectionRange(input.value.length,input.value.length));}else apply()});
  input.addEventListener('input',()=>{if(input.value==='+7'||input.value==='+7 ')return;apply()});
  input.addEventListener('paste',()=>setTimeout(apply,0));
  input.addEventListener('blur',()=>{const canonical=canonicalPhone(input.value);const hasDigits=nationalDigits(input.value).length>0;input.setCustomValidity(hasDigits&&canonical===''?'Введите 10 цифр номера после +7.':'');if(canonical)input.value=formatPhone(canonical);else if(!hasDigits)input.value=''});
  if(input.value)apply();
}
function bindAll(){bind(document.getElementById('authPhone'));bind(document.querySelector('#checkoutForm [name="phone"]'))}
window.KAPOUCH_PHONE={format:formatPhone,canonical:canonicalPhone};
bindAll();
const observer=new MutationObserver(bindAll);observer.observe(document.documentElement,{childList:true,subtree:true});
})();
