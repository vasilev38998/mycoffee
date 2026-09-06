(function(){
'use strict';
const strip=document.getElementById('orderStatusStrip');
const home=document.querySelector('.view[data-view="home"]');
if(!strip||!home)return;
const STORAGE_KEY='kapouch_status_strip_seen_v1';
const DISPLAY_MS=8000;
let activeSignature='';
let hideTimer=null;
let internalChange=false;

function readSeen(){try{const raw=JSON.parse(localStorage.getItem(STORAGE_KEY)||'{}');return raw&&typeof raw==='object'?raw:{}}catch(e){return {}}}
function writeSeen(signature){const seen=readSeen();seen[signature]=Date.now();const keys=Object.keys(seen).sort((a,b)=>Number(seen[b]||0)-Number(seen[a]||0)).slice(0,40);const compact={};keys.forEach(key=>compact[key]=seen[key]);try{localStorage.setItem(STORAGE_KEY,JSON.stringify(compact))}catch(e){}}
function isHomeVisible(){return !document.hidden&&home.classList.contains('active')}
function notificationType(text){const value=String(text||'').trim();if(!value)return '';
  if(/бонусы начислены/i.test(value))return 'completed';
  if(/заказ\s+#?.*готов/i.test(value)||/готов\s+[—-]\s+можно забирать/i.test(value))return 'ready';
  return '';
}
function signature(text,type){const order=(String(text).match(/#([^\s·]+)/)||[])[1]||String(text).slice(0,80);return type+':'+order}
function setHidden(value){if(strip.hidden===value)return;internalChange=true;strip.hidden=value;queueMicrotask(()=>{internalChange=false})}
function clearActive(markSeen){if(hideTimer){clearTimeout(hideTimer);hideTimer=null}if(markSeen&&activeSignature)writeSeen(activeSignature);activeSignature='';}
function process(){if(internalChange)return;const text=String(strip.textContent||'').trim();const type=notificationType(text);if(!type)return;
  const sig=signature(text,type);const seen=readSeen();
  if(seen[sig]){if(activeSignature!==sig)setHidden(true);return;}
  if(!isHomeVisible()){setHidden(true);return;}
  if(activeSignature&&activeSignature!==sig)clearActive(true);
  if(activeSignature===sig){setHidden(false);return;}
  activeSignature=sig;setHidden(false);
  hideTimer=setTimeout(()=>{writeSeen(sig);if(activeSignature===sig)activeSignature='';hideTimer=null;setHidden(true)},DISPLAY_MS);
}

const stripObserver=new MutationObserver(process);stripObserver.observe(strip,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['hidden']});
const homeObserver=new MutationObserver(process);homeObserver.observe(home,{attributes:true,attributeFilter:['class']});
document.addEventListener('visibilitychange',process);window.addEventListener('focus',process);
process();
})();
