(function(){
'use strict';

const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const TOKEN_KEY='kapouch_customer_auth_token';
let wheelState=null,fetching=false,paintQueued=false,lastFetch=0;

const esc=value=>String(value??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
const token=()=>String(localStorage.getItem(TOKEN_KEY)||'');
const validToken=()=>/^[a-f0-9]{64}$/.test(token());
const safeColor=value=>/^#[0-9a-f]{6}$/i.test(String(value||''))?String(value):'#FFC928';
const uid=()=>Math.random().toString(36).slice(2,9);

function ensureCss(){
  if(document.querySelector('link[data-kapouch-wheel-premium]'))return;
  const link=document.createElement('link');link.rel='stylesheet';link.href='assets/wheel.css?v=2';link.dataset.kapouchWheelPremium='1';document.head.appendChild(link);
}

function mix(hex,amount){
  const h=safeColor(hex).slice(1);const r=parseInt(h.slice(0,2),16),g=parseInt(h.slice(2,4),16),b=parseInt(h.slice(4,6),16);
  const target=amount>=0?255:0,p=Math.abs(amount);const f=v=>Math.max(0,Math.min(255,Math.round(v+(target-v)*p))).toString(16).padStart(2,'0');return '#'+f(r)+f(g)+f(b);
}
function polar(cx,cy,r,deg){const rad=(deg-90)*Math.PI/180;return [cx+r*Math.cos(rad),cy+r*Math.sin(rad)]}
function wedgePath(index,count,r=166,cx=200,cy=200){
  if(count<=1)return `M${cx} ${cy-r} A${r} ${r} 0 1 1 ${cx-.1} ${cy-r} Z`;
  const step=360/count,start=index*step,end=start+step,a=polar(cx,cy,r,start),b=polar(cx,cy,r,end),large=step>180?1:0;
  return `M${cx} ${cy} L${a[0].toFixed(2)} ${a[1].toFixed(2)} A${r} ${r} 0 ${large} 1 ${b[0].toFixed(2)} ${b[1].toFixed(2)} Z`;
}
function splitLabel(title){
  const text=String(title||'Подарок').replace('Напиток в подарок','Напиток|в подарок').replace('к прогрессу','|к прогрессу').replace(' бонусов','|бонусов').replace(' на напиток','|на напиток');
  let parts=text.split('|').filter(Boolean);if(parts.length===1&&text.length>13){const words=text.split(' ');const mid=Math.ceil(words.length/2);parts=[words.slice(0,mid).join(' '),words.slice(mid).join(' ')].filter(Boolean)}
  return parts.slice(0,2).map(x=>x.length>16?x.slice(0,15)+'…':x);
}
function iconMarkup(name){
  const common='fill="none" stroke="currentColor" stroke-width="2.15" stroke-linecap="round" stroke-linejoin="round"';
  const icons={
    star:`<g ${common}><circle cx="24" cy="24" r="17"/><path d="M24 13.5l3.2 6.5 7.2 1-5.2 5 1.2 7.1-6.4-3.4-6.4 3.4 1.2-7.1-5.2-5 7.2-1z"/><path d="M15 8.5l-2.4-2.4M33 8.5l2.4-2.4" opacity=".72"/></g>`,
    sparkle:`<g ${common}><path d="M23.8 8c1.1 7 4.7 10.7 11.8 11.8-7.1 1.2-10.7 4.8-11.8 11.9-1.2-7.1-4.8-10.7-11.9-11.9C19 18.7 22.6 15 23.8 8z"/><path d="M36.5 27.5c.6 3.6 2.5 5.5 6 6-3.5.6-5.4 2.5-6 6-.6-3.5-2.5-5.4-6-6 3.5-.5 5.4-2.4 6-6z"/><path d="M11 29c.4 2.5 1.7 3.8 4.2 4.2-2.5.4-3.8 1.7-4.2 4.2-.4-2.5-1.7-3.8-4.2-4.2C9.3 32.8 10.6 31.5 11 29z"/></g>`,
    stamp:`<g ${common}><path d="M17 8h14v9H17z"/><path d="M19 17h10l2.7 13.2H16.3z"/><path d="M13.5 30.2h21v7H13.5z"/><path d="M18 41h12"/></g>`,
    discount:`<g ${common}><path d="M9.5 15.5A5.5 5.5 0 0115 10h13l11 11-18 18a5 5 0 01-7 0l-5-5a5 5 0 010-7z"/><circle cx="17.5" cy="18" r="2.5"/><path d="M18 34l13-13"/><circle cx="31.5" cy="32" r="2.5"/></g>`,
    crown:`<g ${common}><path d="M8 17l8 6 8-12 8 12 8-6-4 20H12z"/><path d="M13 37h22v5H13z"/><circle cx="8" cy="14" r="2"/><circle cx="24" cy="8" r="2"/><circle cx="40" cy="14" r="2"/></g>`,
    cup:`<g ${common}><path d="M13 15h22l-2 23H16z"/><path d="M10 10h28v6H10z"/><path d="M16 6h16l3 4H13z"/><path d="M20 25c3-4 6-4 9 0-3 2-4.5 5-4.5 9-3-2-4.5-5-4.5-9z"/><path d="M18 42h13"/></g>`,
    bean:`<g ${common}><path d="M28 7C17 4 8.5 12 7 24c-1.4 11 5 19 14 20 10 1.3 19-6 21-18 2-11-4-18-14-19z"/><path d="M29 10c4 7 2.5 13-3 18-5 4.5-6 9-3 14"/></g>`
  };return icons[name]||icons.star;
}
function iconSvg(name,size=30){return `<svg viewBox="0 0 48 48" width="${size}" height="${size}" aria-hidden="true">${iconMarkup(name)}</svg>`}

function defs(prizes,id){
  let gradients='';prizes.forEach((p,i)=>{const c=safeColor(p.accent);gradients+=`<linearGradient id="seg-${id}-${i}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${mix(c,.28)}"/><stop offset=".48" stop-color="${c}"/><stop offset="1" stop-color="${mix(c,-.2)}"/></linearGradient>`});
  return `<defs>${gradients}<radialGradient id="hub-${id}" cx="35%" cy="28%"><stop offset="0" stop-color="#fff8bb"/><stop offset=".42" stop-color="#ffd84f"/><stop offset="1" stop-color="#eaa315"/></radialGradient><linearGradient id="gold-${id}" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff5bd"/><stop offset=".28" stop-color="#efc45e"/><stop offset=".52" stop-color="#ad6c19"/><stop offset=".75" stop-color="#ffe58d"/><stop offset="1" stop-color="#d99624"/></linearGradient><filter id="shadow-${id}" x="-30%" y="-30%" width="160%" height="160%"><feDropShadow dx="0" dy="7" stdDeviation="7" flood-color="#32160b" flood-opacity=".28"/></filter><filter id="soft-${id}" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="4"/></filter></defs>`;
}
function rimLights(count=28){let out='';for(let i=0;i<count;i++){const [x,y]=polar(200,200,184,i*360/count);out+=`<circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="3.6" fill="${i%2?'#fff4b4':'#ffc52b'}" stroke="#8b531a" stroke-width="1"><animate attributeName="opacity" values=".55;1;.55" dur="${1.3+(i%5)*.12}s" begin="${-(i%7)*.1}s" repeatCount="indefinite"/></circle>`}return out}
function buildPremiumWheel(prizes){
  const list=prizes.length?prizes:[{title:'Подарок',icon:'star',accent:'#FFC928'}],count=list.length,step=360/count,id=uid();let body='';
  list.forEach((p,i)=>{const angle=i*step+step/2,lines=splitLabel(p.title),iconY=count>8?73:76,labelY=count>8?99:104;fontSize=count>8?9:10.5;body+=`<path d="${wedgePath(i,count)}" fill="url(#seg-${id}-${i})" stroke="#fff5dd" stroke-width="2.5"/>`;body+=`<g transform="rotate(${angle} 200 200)" color="#fffaf0"><circle cx="200" cy="${iconY}" r="21" fill="rgba(45,22,13,.19)" stroke="rgba(255,255,255,.42)" stroke-width="1.2"/>`+`<g transform="translate(181 ${iconY-19}) scale(.8)">${iconMarkup(p.icon)}</g><text x="200" y="${labelY}" text-anchor="middle" fill="#fffaf0" font-family="system-ui,-apple-system,sans-serif" font-size="${fontSize}" font-weight="900" letter-spacing=".1">${lines.map((line,n)=>`<tspan x="200" dy="${n?12:0}">${esc(line)}</tspan>`).join('')}</text></g>`;});
  return `<svg viewBox="0 0 400 400" role="img" aria-label="Колесо призов Kapouch">${defs(list,id)}<circle cx="200" cy="200" r="194" fill="url(#gold-${id})" filter="url(#shadow-${id})"/><circle cx="200" cy="200" r="175" fill="#5a2b1a"/>${body}<circle cx="200" cy="200" r="166" fill="none" stroke="rgba(255,255,255,.55)" stroke-width="3"/><circle cx="200" cy="200" r="170" fill="none" stroke="#8c541b" stroke-width="5" opacity=".45"/>${rimLights()}<circle cx="200" cy="200" r="55" fill="rgba(64,30,16,.17)"/><circle cx="200" cy="200" r="49" fill="none" stroke="rgba(255,255,255,.3)" stroke-width="2"/></svg>`;
}
function buildMiniWheel(){
  const id=uid();return `<svg class="wheel-home-mini" viewBox="0 0 140 140" aria-hidden="true"><defs><linearGradient id="mg-${id}" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff6bd"/><stop offset=".5" stop-color="#e6ad36"/><stop offset="1" stop-color="#9d5d1d"/></linearGradient><filter id="ms-${id}" x="-30%" y="-30%" width="160%" height="160%"><feDropShadow dx="0" dy="5" stdDeviation="5" flood-color="#160803" flood-opacity=".28"/></filter></defs><g filter="url(#ms-${id})"><circle cx="70" cy="72" r="58" fill="url(#mg-${id})"/><g stroke="#fff2d5" stroke-width="1.4"><path d="M70 72L70 19A53 53 0 0 1 115.9 45.5Z" fill="#ffc928"/><path d="M70 72L115.9 45.5A53 53 0 0 1 115.9 98.5Z" fill="#e78b3e"/><path d="M70 72L115.9 98.5A53 53 0 0 1 70 125Z" fill="#8c4c2f"/><path d="M70 72L70 125A53 53 0 0 1 24.1 98.5Z" fill="#ffe592"/><path d="M70 72L24.1 98.5A53 53 0 0 1 24.1 45.5Z" fill="#cf6547"/><path d="M70 72L24.1 45.5A53 53 0 0 1 70 19Z" fill="#fff1cf"/></g><circle cx="70" cy="72" r="18" fill="#361b11" stroke="#ffe183" stroke-width="4"/><g transform="translate(56 58) scale(.58)" color="#ffd53d">${iconMarkup('cup')}</g>${Array.from({length:18},(_,i)=>{const [x,y]=polar(70,72,62,i*20);return `<circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="2.1" fill="${i%2?'#fff4bb':'#ffc72c'}"/>`}).join('')}<path d="M70 4l-8 16h16z" fill="#fff6d7" stroke="#5b2d1c" stroke-width="2.2"/></g></svg>`;
}
function pointerSvg(){return '<svg viewBox="0 0 54 58" aria-hidden="true"><defs><linearGradient id="kapouch-pointer-gold" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#fff4a8"/><stop offset=".55" stop-color="#ffc72c"/><stop offset="1" stop-color="#df8d0d"/></linearGradient></defs><path d="M27 52L7 14C4 8 8 3 14 3h26c6 0 10 5 7 11z" fill="#3b1e12"/><path d="M27 43L13 11h28z" fill="url(#kapouch-pointer-gold)"/><circle cx="27" cy="10" r="4" fill="#fff3a4"/></svg>'}
function hubIcon(){return '<svg viewBox="0 0 48 48" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16h24l-2.3 24H14.3z"/><path d="M9 10h30v7H9z"/><path d="M16 5h16l3 5H13z"/><path d="M20 25c2.6-3.6 5.4-3.6 8 0-2.5 1.8-3.8 4.4-4 7.8-2.7-1.6-4-4.2-4-7.8z"/></g></svg>'}
function arrowSvg(){return '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8h9M8.5 4.5L12 8l-3.5 3.5"/></svg>'}
function ticketSvg(){return '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 2.5h10v3a2 2 0 000 4v4H3v-4a2 2 0 000-4z"/><path d="M8 4.5v1M8 7.5v1M8 10.5v1"/></svg>'}

async function loadState(force=false){
  if(!validToken()){wheelState=null;return null}if(fetching)return wheelState;if(!force&&wheelState&&Date.now()-lastFetch<12000)return wheelState;fetching=true;
  try{const r=await fetch(apiBase+'/customer_wheel.php',{cache:'no-store',headers:{Accept:'application/json','X-Customer-Token':token()}});const d=await r.json().catch(()=>null);if(r.ok&&d?.ok){wheelState=d.wheel||null;lastFetch=Date.now()}}catch(e){}finally{fetching=false}return wheelState;
}
function upgradeHome(){
  const art=document.querySelector('#kapouchWheelHome .wheel-home-art');if(art&&!art.querySelector('.wheel-home-mini'))art.innerHTML=buildMiniWheel();
  const action=document.querySelector('#kapouchWheelHome .wheel-home-action');if(action&&!action.querySelector('svg')){const text=action.textContent.replace('→','').trim();action.innerHTML=esc(text)+' '+arrowSvg()}
  const copy=document.querySelector('#kapouchWheelHome .wheel-home-copy');if(copy&&wheelState?.manual_attempts>0&&!copy.querySelector('.wheel-attempt-badge')){const badge=document.createElement('div');badge.className='wheel-attempt-badge';badge.innerHTML=ticketSvg()+' Подарочных вращений: '+Number(wheelState.manual_attempts);copy.appendChild(badge)}
}
function upgradeModal(){
  const disc=document.getElementById('wheelDisc');const prizes=Array.isArray(wheelState?.prizes)?wheelState.prizes:[];if(disc&&prizes.length){const signature=prizes.map(p=>[p.id,p.title,p.icon,p.accent].join(':')).join('|');if(disc.dataset.premiumSignature!==signature){disc.innerHTML=buildPremiumWheel(prizes);disc.dataset.premiumSignature=signature}}
  const pointer=document.querySelector('.wheel-pointer');if(pointer&&!pointer.dataset.premium){pointer.innerHTML=pointerSvg();pointer.dataset.premium='1'}
  const button=document.getElementById('wheelSpinButton');if(button&&!button.dataset.premium){const sync=()=>{const label=(button.textContent||'').includes('ЛЕТИМ')?'ЛЕТИМ…':'КРУТИТЬ';button.innerHTML=hubIcon()+esc(label);};sync();new MutationObserver(()=>{if(!button.querySelector('svg'))sync()}).observe(button,{childList:true,characterData:true,subtree:true});button.dataset.premium='1'}
  const foot=document.getElementById('wheelFoot');if(foot&&wheelState?.manual_attempts>0&&!foot.querySelector('.wheel-attempt-badge'))foot.insertAdjacentHTML('beforeend','<div class="wheel-attempt-badge">'+ticketSvg()+' Подарочных вращений: '+Number(wheelState.manual_attempts)+'</div>');
  document.querySelectorAll('.wheel-prize-chip i').forEach((node,index)=>{const p=prizes[index];if(p)node.innerHTML=iconSvg(p.icon,18)});
  const result=document.querySelector('.wheel-result-icon');if(result&&wheelState?.recent?.[0]){const latest=wheelState.recent[0],prize=prizes.find(p=>p.type===latest.type&&p.title===latest.title);if(prize&&!result.dataset.premium){result.innerHTML=iconSvg(prize.icon,42);result.dataset.premium='1'}}
}
function upgrade(){paintQueued=false;upgradeHome();upgradeModal()}
function queueUpgrade(){if(paintQueued)return;paintQueued=true;requestAnimationFrame(upgrade)}
function observe(){const observer=new MutationObserver(()=>queueUpgrade());observer.observe(document.documentElement,{childList:true,subtree:true});}

ensureCss();observe();
loadState(true).then(queueUpgrade);
window.addEventListener('kapouch:profile',()=>loadState(false).then(queueUpgrade));
window.addEventListener('kapouch:wheel',()=>loadState(true).then(queueUpgrade));
window.addEventListener('focus',()=>loadState(false).then(queueUpgrade));
document.addEventListener('visibilitychange',()=>{if(!document.hidden)loadState(false).then(queueUpgrade)});
window.addEventListener('storage',e=>{if(e.key===TOKEN_KEY)loadState(true).then(queueUpgrade)});
})();
