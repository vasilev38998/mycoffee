(function(){
'use strict';

const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'../api'};
const apiBase=String(cfg.apiBase||'../api').replace(/\/$/,'');
const AUTH_KEY='kapouch_customer_auth_token';
const $=id=>document.getElementById(id);
const themeMeta=document.querySelector('meta[name="theme-color"]');
const quick=$('loyaltyQuickButton');
const profileButton=document.querySelector('.bottom-nav [data-nav="profile"]');
let loyaltyLoading=false,lastLoyaltyLoad=0,arrangeQueued=false;

function ensureStyle(id,href){
  if(document.getElementById(id))return;
  const link=document.createElement('link');
  link.id=id;
  link.rel='stylesheet';
  link.href=href;
  document.head.appendChild(link);
}

ensureStyle('kapouchRedesignV2','assets/redesign-v2.css?v=2');
ensureStyle('kapouchRedesignV2Modules','assets/redesign-v2-modules.css?v=2');
document.body.classList.add('k-redesign-v2');

function syncTheme(){
  if(themeMeta&&themeMeta.getAttribute('content')!=='#f7f1e8')themeMeta.setAttribute('content','#f7f1e8');
}

function moveAfter(node,anchor){
  if(!node||!anchor||node===anchor||node.previousElementSibling===anchor)return anchor;
  anchor.insertAdjacentElement('afterend',node);
  return node;
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

function arrangeHome(){
  if(arrangeQueued)return;
  arrangeQueued=true;
  requestAnimationFrame(()=>{
    arrangeQueued=false;
    const home=document.querySelector('[data-view="home"]');
    if(!home)return;
    const greeting=home.querySelector('.home-greeting');
    const hero=home.querySelector('.hero-v2');
    const balance=$('balanceCard');
    const teaser=home.querySelector('.loyalty-teaser');
    if(!greeting||!hero||!balance||!teaser)return;

    let anchor=greeting;
    anchor=moveAfter(hero,anchor);
    anchor=moveAfter(balance,anchor);
    anchor=moveAfter(teaser,anchor);

    const growth=$('growthPromo');
    const personal=$('personalOffer');
    const status=$('orderStatusStrip');
    const current=$('currentOrderCard');
    const repeat=$('quickRepeatCard');
    const perks=home.querySelector('.home-perks');
    if(growth)anchor=moveAfter(growth,anchor);
    if(personal)anchor=moveAfter(personal,anchor);
    if(status)anchor=moveAfter(status,anchor);
    if(current)anchor=moveAfter(current,anchor);
    if(repeat)anchor=moveAfter(repeat,anchor);
    if(perks)moveAfter(perks,anchor);

    ensureHeroFacts();
    updateAvatar();
  });
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
    if(strong)strong.textContent=available>1?'Доступно подарков: '+available:'Подарок уже доступен';
    if(span)span.textContent='Покажите QR-карту бариста перед оплатой — бесплатный напиток уже ждёт.';
  }else{
    if(strong)strong.textContent='Каждый 6-й напиток — в подарок';
    if(span)span.textContent='Сейчас '+progress+' из '+required+'. До подарка '+next+' '+(next===1?'напиток':'напитка')+'.';
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
if(welcome)new MutationObserver(()=>{updateAvatar();arrangeHome()}).observe(welcome,{childList:true,subtree:true,characterData:true});

const home=document.querySelector('[data-view="home"]');
if(home)new MutationObserver(()=>arrangeHome()).observe(home,{childList:true,subtree:false});

if(themeMeta)new MutationObserver(syncTheme).observe(themeMeta,{attributes:true,attributeFilter:['content']});

syncTheme();
arrangeHome();
loadLoyalty(false);

window.addEventListener('load',()=>{syncTheme();arrangeHome();window.setTimeout(()=>{syncTheme();arrangeHome();loadLoyalty(false)},650)});
window.addEventListener('focus',()=>{syncTheme();arrangeHome();loadLoyalty(false)});
window.addEventListener('hashchange',()=>{arrangeHome();if(location.hash==='#home')loadLoyalty(false)});
window.addEventListener('storage',e=>{if(e.key===AUTH_KEY){lastLoyaltyLoad=0;loadLoyalty(true);arrangeHome()}});
window.addEventListener('kapouch-order-status',e=>{const status=String(e.detail?.status||'');arrangeHome();if(status==='completed'||status==='cancelled'){lastLoyaltyLoad=0;window.setTimeout(()=>loadLoyalty(true),250)}});
document.addEventListener('visibilitychange',()=>{if(!document.hidden){syncTheme();arrangeHome();loadLoyalty(false)}});
})();
