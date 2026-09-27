(function(){
'use strict';

const SVG_NS='http://www.w3.org/2000/svg';
let scheduled=false;

function esc(value){return String(value??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
function hex(value){const v=String(value||'').trim();return /^#[0-9a-f]{6}$/i.test(v)?v:'#c7803f'}
function titleType(title){
  const t=String(title||'').toLowerCase();
  const n=Number((t.match(/\d+/)||['0'])[0]);
  if(t.includes('напит')||t.includes('кофе'))return 'cup';
  if(t.includes('прогресс')||t.includes('штамп'))return 'stamp';
  if(t.includes('%')||t.includes('скид'))return 'discount';
  if(t.includes('бонус'))return n>=100?'crown':(n>=50?'sparkle':'star');
  return 'bean';
}
function labelLines(title){
  let text=String(title||'Подарок').trim().replace(/\s+/g,' ');
  const replacements=[['Напиток в подарок','Напиток|в подарок'],['к прогрессу','к прогрессу'],[' бонусов','|бонусов'],[' на напиток','|на напиток']];
  replacements.forEach(([from,to])=>{if(text.includes(from))text=text.replace(from,to)});
  if(!text.includes('|')&&text.length>16){const words=text.split(' ');let a='',b='';words.forEach(word=>{if(!b&&(a+' '+word).trim().length<=13)a=(a+' '+word).trim();else b=(b+' '+word).trim()});text=a+(b?'|'+b:'')}
  return text.split('|').slice(0,2);
}
function premiumIcon(type){
  const common='fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"';
  if(type==='cup')return '<g '+common+' stroke-width="1.8"><path d="M15 12.7h18l-1.9 23.4H16.9z" fill="currentColor" fill-opacity=".15"/><path d="M13.7 9.3h20.6v4.8H13.7z" fill="currentColor" fill-opacity=".2"/><path d="M17.7 6.1h12.6l2.3 3.2H15.4z"/><path d="M19.2 21.6h9.6v8.7h-9.6z" fill="currentColor" fill-opacity=".18"/><path d="M22 25.3c1.7-2.6 3.5-2.6 5.1 0-1.6 1.1-2.4 2.6-2.6 4.4-1.8-1-2.7-2.5-2.5-4.4z" fill="currentColor" stroke="none"/><path d="M20.6 4.1c-.5-1.7.5-2.8 1.6-3.5M26.8 4.1c-.4-1.8.8-2.8 1.9-3.6" opacity=".72"/></g>';
  if(type==='discount')return '<g '+common+' stroke-width="1.8"><path d="M10.6 12.7c0-2 1.6-3.6 3.6-3.6h12.1L38 20.8 22.7 36.1a3.6 3.6 0 01-5.1 0L10 28.5a3.6 3.6 0 010-5.1z" fill="currentColor" fill-opacity=".14"/><circle cx="17.3" cy="16.2" r="2.5" fill="currentColor" stroke="none"/><path d="M19 31.5L31.6 18.9" stroke-width="2.6"/><circle cx="29.5" cy="30.2" r="2.8"/><circle cx="21.2" cy="21.7" r="2.8"/></g>';
  if(type==='stamp')return '<g '+common+' stroke-width="1.8"><path d="M14 8.5h20v8H14z" rx="3" fill="currentColor" fill-opacity=".14"/><path d="M17.4 16.5h13.2l2.3 15.4H15.1z" fill="currentColor" fill-opacity=".1"/><path d="M12 33.1h24v6.2H12z" rx="3" fill="currentColor" fill-opacity=".18"/><circle cx="20" cy="25" r="2.2" fill="currentColor" stroke="none"/><circle cx="28" cy="25" r="2.2" fill="currentColor" stroke="none"/><path d="M24 20v10M19 25h10" stroke-width="2.2"/></g>';
  if(type==='crown')return '<g '+common+' stroke-width="1.8"><path d="M8.8 14l8.1 6.4L24 8l7.1 12.4 8.1-6.4-4 21H12.8z" fill="currentColor" fill-opacity=".17"/><path d="M14.2 34.8h19.6M16.4 28.5h15.2"/><circle cx="8.8" cy="13.8" r="2" fill="currentColor" stroke="none"/><circle cx="24" cy="7.5" r="2" fill="currentColor" stroke="none"/><circle cx="39.2" cy="13.8" r="2" fill="currentColor" stroke="none"/></g>';
  if(type==='sparkle')return '<g '+common+' stroke-width="1.6"><path d="M23.8 5.5c1.5 8 4.6 11.4 12.6 12.9-8 1.5-11.1 4.9-12.6 12.9-1.5-8-4.6-11.4-12.6-12.9 8-1.5 11.1-4.9 12.6-12.9z" fill="currentColor" fill-opacity=".15"/><path d="M36.5 27.4c.8 4.4 2.4 6.1 6.8 7-4.4.8-6 2.6-6.8 7-.8-4.4-2.4-6.2-6.8-7 4.4-.9 6-2.6 6.8-7z"/><circle cx="11.4" cy="33.7" r="3.1" fill="currentColor" stroke="none" opacity=".75"/></g>';
  if(type==='bean')return '<g '+common+' stroke-width="1.8"><path d="M28.5 6.2c-9.6-1.7-18 5.4-19.6 15.6-1.4 9.4 3.7 17 11.7 18.3 8.6 1.4 17.2-5.2 19.3-15 2.1-10-3.1-17.5-11.4-18.9z" fill="currentColor" fill-opacity=".14"/><path d="M30 9.8c3 5.4 1.6 10.4-2.5 14.4-3.7 3.5-5 7.4-3.1 12.1" stroke-width="2.3"/></g>';
  return '<g '+common+' stroke-width="1.8"><path d="M24 6.3l5 10.1 11.2 1.7-8.1 7.8 1.9 11.1-10-5.3-10 5.3 1.9-11.1-8.1-7.8L19 16.4z" fill="currentColor" fill-opacity=".16"/><path d="M24 11.7l3.2 6.5 7.2 1-5.2 5.1 1.3 7.1-6.5-3.4-6.5 3.4 1.3-7.1-5.2-5.1 7.2-1z"/></g>';
}
function shadeColor(c,amount){
  const n=parseInt(c.slice(1),16),r=Math.max(0,Math.min(255,(n>>16)+amount)),g=Math.max(0,Math.min(255,((n>>8)&255)+amount)),b=Math.max(0,Math.min(255,(n&255)+amount));
  return '#'+[r,g,b].map(v=>v.toString(16).padStart(2,'0')).join('');
}
function goldStuds(){let out='';for(let i=0;i<30;i++){const a=(i/30)*Math.PI*2-Math.PI/2,x=160+145*Math.cos(a),y=160+145*Math.sin(a);out+='<circle cx="'+x.toFixed(2)+'" cy="'+y.toFixed(2)+'" r="2.25" fill="url(#premiumStud)" filter="url(#premiumTinyShadow)"/>'}return out}
function readSegments(svg){
  const paths=Array.from(svg.children).filter(node=>node.tagName&&node.tagName.toLowerCase()==='path');
  const groups=Array.from(svg.children).filter(node=>node.tagName&&node.tagName.toLowerCase()==='g');
  return groups.map((group,index)=>({
    d:paths[index]?.getAttribute('d')||'',
    color:hex(paths[index]?.getAttribute('fill')),
    title:Array.from(group.querySelectorAll('tspan')).map(n=>n.textContent||'').join(' ').trim()||group.querySelector('text')?.textContent?.trim()||'Подарок',
    transform:group.getAttribute('transform')||''
  })).filter(segment=>segment.d);
}
function buildPremiumSvg(segments){
  const defs=['<defs>',
    '<radialGradient id="premiumRim" cx="34%" cy="22%" r="78%"><stop offset="0" stop-color="#fff9df"/><stop offset=".26" stop-color="#f9d676"/><stop offset=".58" stop-color="#bb7830"/><stop offset=".82" stop-color="#ffe7a0"/><stop offset="1" stop-color="#8a4a20"/></radialGradient>',
    '<linearGradient id="premiumInnerRing" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff6d7"/><stop offset=".42" stop-color="#e7b95b"/><stop offset="1" stop-color="#7f421f"/></linearGradient>',
    '<radialGradient id="premiumStud" cx="35%" cy="25%" r="70%"><stop stop-color="#fffceb"/><stop offset=".32" stop-color="#ffe49b"/><stop offset=".72" stop-color="#d69a32"/><stop offset="1" stop-color="#86501f"/></radialGradient>',
    '<filter id="premiumShadow" x="-25%" y="-25%" width="150%" height="150%"><feDropShadow dx="0" dy="5" stdDeviation="6" flood-color="#32150b" flood-opacity=".28"/></filter>',
    '<filter id="premiumTinyShadow" x="-100%" y="-100%" width="300%" height="300%"><feDropShadow dx="0" dy="1" stdDeviation="1" flood-color="#4c2512" flood-opacity=".45"/></filter>',
    '<filter id="premiumGlow" x="-80%" y="-80%" width="260%" height="260%"><feGaussianBlur stdDeviation="4" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>'
  ];
  segments.forEach((s,i)=>{defs.push('<linearGradient id="seg'+i+'" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'+shadeColor(s.color,32)+'"/><stop offset=".42" stop-color="'+s.color+'"/><stop offset="1" stop-color="'+shadeColor(s.color,-32)+'"/></linearGradient>')});
  defs.push('</defs>');
  let body='<circle cx="160" cy="160" r="157" fill="url(#premiumRim)" filter="url(#premiumShadow)"/><circle cx="160" cy="160" r="149" fill="#3f2116"/>';
  segments.forEach((s,i)=>{
    const lines=labelLines(s.title),type=titleType(s.title);
    body+='<path d="'+s.d+'" fill="url(#seg'+i+')" stroke="#fff0c4" stroke-width="1.35"/>';
    body+='<path d="'+s.d+'" fill="none" stroke="rgba(255,255,255,.26)" stroke-width="3.2" opacity=".34"/>';
    body+='<g transform="'+esc(s.transform)+'" color="#fff9e9">';
    body+='<circle cx="160" cy="62" r="21" fill="rgba(56,25,13,.19)" stroke="rgba(255,255,255,.52)" stroke-width="1.1"/>';
    body+='<g transform="translate(136 38) scale(1)">'+premiumIcon(type)+'</g>';
    body+='<rect x="119" y="87" width="82" height="'+(lines.length>1?31:24)+'" rx="12" fill="rgba(44,20,11,.20)" stroke="rgba(255,255,255,.20)" stroke-width=".8"/>';
    body+='<text x="160" y="'+(lines.length>1?'99':'102')+'" text-anchor="middle" fill="#fffaf0" font-family="system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif" font-size="'+(segments.length>7?'7.4':'8.4')+'" font-weight="850" letter-spacing=".1">'+lines.map((line,n)=>'<tspan x="160" dy="'+(n?9.2:0)+'">'+esc(line)+'</tspan>').join('')+'</text></g>';
  });
  body+='<circle cx="160" cy="160" r="149" fill="none" stroke="rgba(255,255,255,.52)" stroke-width="2.2"/>'+goldStuds();
  body+='<circle cx="160" cy="160" r="52" fill="url(#premiumInnerRing)" opacity=".98"/><circle cx="160" cy="160" r="44" fill="#3d2014" stroke="#fff2c4" stroke-width="1.4"/>';
  body+='<g transform="translate(136 136)" color="#ffd24a" opacity=".78">'+premiumIcon('bean')+'</g>';
  return '<svg data-kapouch-premium="1" viewBox="0 0 320 320" role="img" aria-label="Колесо призов Kapouch">'+defs.join('')+body+'</svg>';
}
function enhanceWheel(){
  const disc=document.getElementById('wheelDisc');if(!disc)return;
  const svg=disc.querySelector('svg');if(!svg||svg.dataset.kapouchPremium==='1')return;
  const segments=readSegments(svg);if(!segments.length)return;
  disc.innerHTML=buildPremiumSvg(segments);
}
function enhancePointer(){
  const pointer=document.querySelector('.wheel-pointer');if(!pointer||pointer.dataset.premium==='1')return;pointer.dataset.premium='1';
  pointer.innerHTML='<svg viewBox="0 0 64 74" aria-hidden="true"><defs><linearGradient id="pointerGold" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff2a8"/><stop offset=".35" stop-color="#ffd038"/><stop offset="1" stop-color="#b76a16"/></linearGradient><filter id="pointerShadow"><feDropShadow dx="0" dy="4" stdDeviation="3" flood-color="#3c1c0e" flood-opacity=".42"/></filter></defs><g filter="url(#pointerShadow)"><circle cx="32" cy="18" r="15" fill="#3a1d12" stroke="url(#pointerGold)" stroke-width="5"/><path d="M32 70L17 25h30z" fill="url(#pointerGold)" stroke="#fff1bd" stroke-width="1.4"/><circle cx="32" cy="18" r="6" fill="#fff7dc"/><circle cx="30" cy="16" r="2.2" fill="#fff" opacity=".9"/></g></svg>';
}
function enhanceHub(){
  const hub=document.querySelector('.wheel-hub');if(!hub||hub.dataset.premium==='1')return;hub.dataset.premium='1';const button=hub.querySelector('button');if(!button)return;
  button.innerHTML='<span class="wheel-premium-hubmark"><svg viewBox="0 0 44 44" aria-hidden="true"><path d="M13 13h18l-2 19H15z" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round"/><path d="M11 10h22v5H11z" fill="currentColor" opacity=".18" stroke="currentColor" stroke-width="2"/><path d="M18 5h8l4 5H14z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M19 21c2-3 4-3 6 0-2 1.5-3 3.3-3 5.7-2-1.4-3-3.2-3-5.7z" fill="currentColor"/><path d="M18 35h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span><b>КРУТИТЬ</b>';
}
function enhanceHome(){
  const art=document.querySelector('.wheel-home-art');if(!art||art.dataset.premium==='1')return;art.dataset.premium='1';
  art.innerHTML='<svg class="wheel-home-premium-svg" viewBox="0 0 120 120" aria-hidden="true"><defs><radialGradient id="homeRim" cx="32%" cy="25%" r="75%"><stop stop-color="#fff9df"/><stop offset=".3" stop-color="#ffd65d"/><stop offset=".7" stop-color="#c17824"/><stop offset="1" stop-color="#fff0a8"/></radialGradient><filter id="homeShadow"><feDropShadow dx="0" dy="7" stdDeviation="6" flood-color="#160803" flood-opacity=".38"/></filter></defs><g filter="url(#homeShadow)"><circle cx="60" cy="62" r="49" fill="url(#homeRim)"/><circle cx="60" cy="62" r="43" fill="#482318"/><path d="M60 19A43 43 0 0022.8 40.5L60 62z" fill="#ffd347"/><path d="M22.8 40.5A43 43 0 0022.8 83.5L60 62z" fill="#e48a43"/><path d="M22.8 83.5A43 43 0 0060 105L60 62z" fill="#fff0b1"/><path d="M60 105A43 43 0 0097.2 83.5L60 62z" fill="#c45b3e"/><path d="M97.2 83.5A43 43 0 0097.2 40.5L60 62z" fill="#8b4b32"/><path d="M97.2 40.5A43 43 0 0060 19L60 62z" fill="#f5ac42"/><circle cx="60" cy="62" r="18" fill="#33180f" stroke="#ffe29a" stroke-width="3"/><path d="M53 54h14l-1.5 15H54.5zM51.5 51h17v4H51.5z" fill="none" stroke="#ffd348" stroke-width="2" stroke-linejoin="round"/><path d="M57 61c1.3-2 2.7-2 4 0-1.2 1-1.8 2-2 3.6-1.4-.9-2-2-2-3.6z" fill="#ffd348"/></g><path d="M60 3l8 18H52z" fill="#fff8dc" stroke="#ffd75c" stroke-width="2"/></svg>';
}
function enhanceResult(){
  const result=document.getElementById('wheelResult');if(!result||!result.classList.contains('show'))return;const icon=result.querySelector('.wheel-result-icon');if(!icon||icon.dataset.premium==='1')return;icon.dataset.premium='1';const type=titleType(result.querySelector('h3')?.textContent||'');icon.innerHTML='<svg viewBox="0 0 48 48" width="44" height="44" aria-hidden="true" style="color:#fff9ed">'+premiumIcon(type)+'</svg>';
}
function injectStyle(){if(document.getElementById('kapouchWheelPremiumStyle'))return;const style=document.createElement('style');style.id='kapouchWheelPremiumStyle';style.textContent=`
#kapouchWheelHome{background:radial-gradient(circle at 88% 15%,rgba(255,218,92,.22),transparent 32%),linear-gradient(142deg,#24120c 0%,#4b2518 48%,#7b3e26 100%)!important;border:1px solid rgba(255,221,132,.16);box-shadow:0 18px 44px rgba(54,24,11,.22),inset 0 1px 0 rgba(255,255,255,.08)!important}.wheel-home-premium-svg{width:100%;height:100%;overflow:visible;animation:wheelPremiumFloat 5.8s ease-in-out infinite}.wheel-home-action{background:linear-gradient(135deg,#ffe271,#ffc224)!important;box-shadow:0 8px 22px rgba(255,185,20,.28),inset 0 1px 0 rgba(255,255,255,.5)!important}.kapouch-wheel-modal{background:radial-gradient(circle at 50% -8%,#fff8d6 0,#fffaf2 27%,#f6e9dc 100%)!important;border:1px solid rgba(103,59,32,.08);box-shadow:0 -26px 80px rgba(34,16,8,.36),inset 0 1px 0 rgba(255,255,255,.75)!important}.wheel-stage{width:min(89vw,382px)!important;margin-top:20px!important}.wheel-aura{inset:-10%!important;background:radial-gradient(circle,rgba(255,202,54,.42) 0,rgba(255,202,54,.16) 40%,transparent 70%)!important;filter:blur(12px)!important}.wheel-shell{inset:1%!important;padding:10px!important;background:radial-gradient(circle at 35% 20%,#fffceb 0,#f7d274 24%,#ad6929 58%,#fff1bc 77%,#7d421d 100%)!important;box-shadow:0 24px 52px rgba(74,33,15,.3),0 0 0 1px rgba(255,255,255,.7) inset,0 -5px 12px rgba(103,52,18,.18) inset!important}.wheel-disc{filter:drop-shadow(0 8px 11px rgba(62,27,12,.25))!important}.wheel-stage.spinning .wheel-disc{filter:drop-shadow(0 12px 14px rgba(62,27,12,.28)) blur(.12px)!important}.wheel-pointer{top:-9px!important;width:58px!important;height:68px!important}.wheel-hub{width:102px!important;height:102px!important;border:0!important;padding:5px!important;background:radial-gradient(circle at 32% 25%,#fff6bb 0,#ffd347 28%,#e99c18 64%,#7f4218 100%)!important;box-shadow:0 11px 28px rgba(71,30,12,.38),0 0 0 4px #fff3c2 inset,0 0 0 8px rgba(86,42,20,.17) inset!important}.wheel-hub:before{content:"";position:absolute;inset:9px;border-radius:50%;background:linear-gradient(145deg,#3b1d12,#5a2d1c);box-shadow:inset 0 1px 0 rgba(255,255,255,.1)}.wheel-hub button{position:relative;z-index:1;color:#ffd64b!important;display:grid!important;place-items:center!important;align-content:center!important;gap:2px!important;font-size:8px!important;letter-spacing:.16em!important}.wheel-premium-hubmark{width:35px;height:35px;display:block!important;margin:0 auto -1px!important}.wheel-premium-hubmark svg{width:100%;height:100%}.wheel-hub button b{font:950 8px/1 system-ui;letter-spacing:.14em}.wheel-prize-chip{background:rgba(255,253,245,.82)!important;border-color:rgba(101,56,30,.09)!important;box-shadow:0 5px 12px rgba(81,42,22,.06)}.wheel-result{border:1px solid rgba(111,62,34,.1)!important;background:linear-gradient(180deg,rgba(255,253,245,.985),rgba(255,246,226,.985))!important}.wheel-result-icon{border-radius:50%!important;border:3px solid rgba(255,249,224,.92);box-shadow:0 12px 28px rgba(78,39,19,.28),0 0 0 4px rgba(255,203,49,.14)!important;transform:none!important}.wheel-modal-head h2{letter-spacing:-.035em}.wheel-close{background:rgba(95,51,29,.08)!important;border:1px solid rgba(95,51,29,.06);box-shadow:inset 0 1px 0 rgba(255,255,255,.55)}@keyframes wheelPremiumFloat{0%,100%{transform:rotate(-4deg) translateY(2px)}50%{transform:rotate(6deg) translateY(-4px)}}@media(prefers-reduced-motion:reduce){.wheel-home-premium-svg{animation:none!important}}
`;document.head.appendChild(style)}
function run(){scheduled=false;injectStyle();enhanceHome();enhanceWheel();enhancePointer();enhanceHub();enhanceResult()}
function schedule(){if(scheduled)return;scheduled=true;requestAnimationFrame(run)}

injectStyle();schedule();
new MutationObserver(schedule).observe(document.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:['class']});
window.addEventListener('kapouch:wheel',schedule);
window.addEventListener('kapouch:profile',schedule);
})();
