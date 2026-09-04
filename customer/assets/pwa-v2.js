(function(){
'use strict';
const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'../api'};const apiBase=String(cfg.apiBase||'../api').replace(/\/$/,'');
let products=[];let byKey=new Map();let byProductId=new Map();
const escAttr=v=>String(v||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;');
function indexProducts(){byKey=new Map();byProductId=new Map();for(const p of products){byKey.set(String(p.key||('p'+p.id)),p);for(const v of (p.variants||[]))byProductId.set(Number(v.id),{display:p,variant:v});}}
function imageFor(p,v){return (v&&v.image)||p?.image||'';}
function hydrateCards(root=document){
  root.querySelectorAll?.('.product-card[data-open]').forEach(card=>{
    const p=byKey.get(String(card.dataset.open||''));if(!p)return;const visual=card.querySelector('.visual');if(!visual)return;
    const image=imageFor(p,null);if(image&&!visual.querySelector('img.product-photo')){const cup=visual.querySelector('.cup');if(cup)cup.remove();const img=document.createElement('img');img.className='product-photo';img.src=image;img.alt=p.name||'';img.loading='lazy';img.decoding='async';img.onerror=()=>{img.remove();visual.classList.remove('has-photo')};visual.classList.add('has-photo');visual.appendChild(img);}
    if(!card.querySelector('.product-card-body')){const children=[...card.children].filter(el=>el!==visual);if(children.length){const body=document.createElement('div');body.className='product-card-body';visual.after(body);children.forEach(el=>body.appendChild(el));}}
  });
}
function hydrateCart(root=document){
  root.querySelectorAll?.('.cart-item').forEach(item=>{const btn=item.querySelector('[data-change]');const id=Number(btn?.dataset.change||0);const info=byProductId.get(id);const box=item.querySelector('.mini-visual');const image=info?imageFor(info.display,info.variant):'';if(!box||!image||box.querySelector('img'))return;box.textContent='';const img=document.createElement('img');img.src=image;img.alt=info.display.name||'';img.loading='lazy';img.decoding='async';box.appendChild(img);});
}
function showProductImage(key){const p=byKey.get(String(key||''));if(!p)return;const preferred=(p.variants||[]).find(v=>v.is_default)||(p.variants||[])[0];const image=imageFor(p,preferred);const img=document.getElementById('productImage'),fallback=document.getElementById('productFallback');if(!img||!fallback)return;if(image){img.src=image;img.alt=p.name||'';img.hidden=false;fallback.hidden=true;img.onerror=()=>{img.hidden=true;fallback.hidden=false};}else{img.removeAttribute('src');img.hidden=true;fallback.hidden=false;}}
function hydrateHero(shop){const title=document.getElementById('heroTitle');if(title&&shop?.hero_title)title.textContent=shop.hero_title;}
function observe(){const obs=new MutationObserver(m=>{for(const x of m){for(const node of x.addedNodes){if(node.nodeType!==1)continue;hydrateCards(node);hydrateCart(node);}}});obs.observe(document.body,{childList:true,subtree:true});}
async function load(){try{const r=await fetch(apiBase+'/customer_catalog.php?_photo='+Date.now(),{cache:'no-store',headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok||!d.ok)return;products=d.products||[];indexProducts();hydrateHero(d.shop||{});hydrateCards();hydrateCart();}catch(e){}}
document.addEventListener('click',e=>{const card=e.target.closest?.('.product-card[data-open]');if(card&&!e.target.closest('[data-add]'))setTimeout(()=>showProductImage(card.dataset.open),0);const variant=e.target.closest?.('[data-variant]');if(variant){const id=Number(variant.dataset.variant||0),info=byProductId.get(id);if(info)setTimeout(()=>{const img=document.getElementById('productImage');if(img&&imageFor(info.display,info.variant)){img.src=imageFor(info.display,info.variant);img.hidden=false;document.getElementById('productFallback').hidden=true;}},0);}},true);
observe();load();window.addEventListener('focus',()=>{hydrateCards();hydrateCart();});
})();
