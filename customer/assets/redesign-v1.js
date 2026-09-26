(function(){
'use strict';

const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'../api'};
const apiBase=String(cfg.apiBase||'../api').replace(/\/$/,'');
const AUTH_KEY='kapouch_customer_auth_token';
const $=id=>document.getElementById(id);
const themeMeta=document.querySelector('meta[name="theme-color"]');
const quick=$('loyaltyQuickButton');
const profileButton=document.querySelector('.bottom-nav [data-nav="profile"]');
const wordForm=(value,one,few,many)=>{const n=Math.abs(Number(value)||0),n100=n%100,n10=n%10;if(n100>=11&&n100<=14)return many;if(n10===1)return one;if(n10>=2&&n10<=4)return few;return many};
let loyaltyLoading=false,lastLoyaltyLoad=0;

function ensureStyle(id,href){
  if(document.getElementById(id))return;
  const link=document.createElement('link');
  link.id=id;
  link.rel='stylesheet';
  link.href=href;
  document.head.appendChild(link);
}

function ensureScript(id,src){
  if(document.getElementById(id))return;
  const script=document.createElement('script');
  script.id=id;
  script.src=src;
  script.defer=true;
  document.body.appendChild(script);
}

function ensureHomeOrderStyle(){
  if(document.getElementById('kapouchHomeOrderFix'))return;
  const style=document.createElement('style');
  style.id='kapouchHomeOrderFix';
  style.textContent=`
    .k-redesign-v2 .view[data-view="home"].active{display:flex!important;flex-direction:column!important}
    .k-redesign-v2 .view[data-view="home"]>*{order:200}
    .k-redesign-v2 .view[data-view="home"]>.home-greeting{order:10}
    .k-redesign-v2 .view[data-view="home"]>.hero-v2{order:20}
    .k-redesign-v2 .view[data-view="home"]>#balanceCard{order:30}
    .k-redesign-v2 .view[data-view="home"]>.loyalty-teaser{order:40}
    .k-redesign-v2 .view[data-view="home"]>#growthPromo{order:50}
    .k-redesign-v2 .view[data-view="home"]>#personalOffer{order:60}
    .k-redesign-v2 .view[data-view="home"]>#orderStatusStrip{order:70}
    .k-redesign-v2 .view[data-view="home"]>#currentOrderCard{order:80}
    .k-redesign-v2 .view[data-view="home"]>#quickRepeatCard{order:90}
    .k-redesign-v2 .view[data-view="home"]>.home-perks{order:100}
    .k-redesign-v2 .view[data-view="home"]>#favoriteSection{order:110}
    .k-redesign-v2 .view[data-view="home"]>.section-title{order:120}
    .k-redesign-v2 .view[data-view="home"]>#popularList{order:130}
    .k-redesign-v2 .view[data-view="home"]>.about-card{order:140}
    .k-redesign-v2 .view[data-view="home"]>#homeLegalFooter{order:150}
  `;
  document.head.appendChild(style);
}

ensureStyle('kapouchRedesignV2','assets/redesign-v2.css?v=2');
ensureStyle('kapouchRedesignV2Modules','assets/redesign-v2-modules.css?v=2');
ensureStyle('kapouchRedesignV3','assets/redesign-v3-fixes.css?v=1');
document.body.classList.add('k-redesign-v2');
ensureHomeOrderStyle();
ensureScript('kapouchRedesignV3Script','assets/redesign-v3-fixes.js?v=1');

function syncTheme(){
  if(themeMeta&&themeMeta.getAttribute('content')!=='#f7f1e8')themeMeta.setAttribute('content','#f7f1e8');
}

function ensureHeroFacts(){
  const copy=document.querySelector('.hero-v2-copy');
  if(!copy||copy.querySelector('.hero-facts'))return;
  const facts=document.createElement('div');
  facts.className='hero-facts';
  facts.innerHTML='<span class="hero-fact">Без очереди</span><span class="hero-fact">Бонусы с заказов</span><span class="hero-fact">6-й в подарок</span>';
  copy.appendChild(facts);
}

function updateAvatar(){
  const avatar=document.querySelector('.home-avatar'),title=$('welcomeTitle');
  if(!avatar||!title)return;
  const text=String(title.textContent||'').trim();
  const match=text.match(/^Привет(?:,\s*([^!]+))?/i);
  const name=(match&&match[1]?match[1]:'').trim();
  const letter=name?name.charAt(0).toLocaleUpperCase('ru-RU'):'K';
  if(avatar.textContent!==letter)avatar.textContent=letter;
}

function refreshPresentation(){
  ensureHeroFacts();
  updateAvatar();
}

function renderLoyaltyProgress(drink){
  const teaser=document.querySelector('.loyalty-teaser');
  if(!teaser)return;
  if(drink&&drink.enabled===false){teaser.hidden=true;return}
  teaser.hidden=false;
  const copy=teaser.querySelector('.loyalty-teaser-copy');
  const cups=teaser.querySelector('.loyalty-cups');
  if(!copy||!cups)return;

  const required=Math.max(1,Math.min(8,Number(drink?.required_paid||5)));
  const progress=Math.max(0,Math.min(required,Number(drink?.progress||0)));
  const available=Math.max(0,Number(drink?.available_rewards||0));
  const next=Math.max(1,Number(drink?.next_in||required-progress||1));

  cups.innerHTML='';
  for(let i=0;i<required;i++){
    const cup=document.createElement('i');
    cup.className='loyalty-cup'+(i<progress||available>0?' done':'');
    cup.textContent=String(i+1);
    cups.appendChild(cup);
  }
  const gift=document.createElement('i');
  gift.className='loyalty-cup gift'+(available>0?' available':'');
  gift.textContent='★';
  cups.appendChild(gift);

  const strong=copy.querySelector('strong'),span=copy.querySelector('span');
  if(available>0){
    if(strong)strong.textContent=available===1?'Подарок уже доступен':'Доступно '+available+' '+wordForm(available,'подарок','подарка','подарков');
    if(span)span.textContent='Покажите QR-карту бариста перед оплатой — бесплатный напиток уже ждёт.';
  }else{
    if(strong)strong.textContent='Каждый 6-й напиток — в подарок';
    if(span)span.textContent='Сейчас '+progress+' из '+required+'. До подарка '+next+' '+wordForm(next,'напиток','напитка','напитков')+'.';
  }
}

async function loadLoyalty(force=false){
  const token=localStorage.getItem(AUTH_KEY)||'';
  if(!token){renderLoyaltyProgress(null);return}
  const now=Date.now();
  if(loyaltyLoading||(!force&&now-lastLoyaltyLoad<45000))return;
  loyaltyLoading=true;
  try{
    const r=await fetch(apiBase+'/customer_loyalty_card.php?_='+now,{cache:'no-store',headers:{Accept:'application/json','X-Customer-Token':token}});
    const d=await r.json().catch(()=>null);
    if(!r.ok||!d?.ok)return;
    lastLoyaltyLoad=Date.now();
    renderLoyaltyProgress(d.card?.drink_loyalty||null);
  }catch(e){}finally{loyaltyLoading=false}
}

function openBonuses(){
  if(profileButton)profileButton.click();
  window.setTimeout(()=>{
    const target=document.querySelector('#personalLoyaltyCard:not([hidden]), #profileUser:not([hidden]) .profile-balance, #profileGuest:not([hidden])');
    if(target)target.scrollIntoView({behavior:'smooth',block:'start'});
  },220);
}

if(quick)quick.addEventListener('click',openBonuses);

const welcome=$('welcomeTitle');
if(welcome)new MutationObserver(updateAvatar).observe(welcome,{childList:true,subtree:true,characterData:true});

if(themeMeta)new MutationObserver(syncTheme).observe(themeMeta,{attributes:true,attributeFilter:['content']});

syncTheme();
refreshPresentation();
loadLoyalty(false);

window.addEventListener('load',()=>{syncTheme();refreshPresentation();window.setTimeout(()=>{syncTheme();refreshPresentation();loadLoyalty(false)},650)});
window.addEventListener('focus',()=>{syncTheme();refreshPresentation();loadLoyalty(false)});
window.addEventListener('hashchange',()=>{refreshPresentation();if(location.hash==='#home')loadLoyalty(false)});
window.addEventListener('storage',e=>{if(e.key===AUTH_KEY){lastLoyaltyLoad=0;loadLoyalty(true);refreshPresentation()}});
window.addEventListener('kapouch-order-status',e=>{const status=String(e.detail?.status||'');if(status==='completed'||status==='cancelled'){lastLoyaltyLoad=0;window.setTimeout(()=>loadLoyalty(true),250)}});
document.addEventListener('visibilitychange',()=>{if(!document.hidden){syncTheme();refreshPresentation();loadLoyalty(false)}});
})();
