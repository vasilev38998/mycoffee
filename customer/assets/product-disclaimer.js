(function(){
'use strict';
const TEXT='Продукт может отличаться от изображения';
let scheduled=false;
function make(className){const el=document.createElement('span');el.className=className;el.textContent=TEXT;return el}
function apply(){
  scheduled=false;
  document.querySelectorAll('.product-card .visual.has-photo').forEach(visual=>{
    if(!visual.querySelector('.product-image-disclaimer'))visual.appendChild(make('product-image-disclaimer'));
  });

  const modal=document.getElementById('productModal'),hero=modal?.querySelector('.product-hero'),image=document.getElementById('productImage');
  if(hero&&image&&image.getAttribute('src')&&!image.hidden){
    if(!hero.querySelector('.product-image-disclaimer.detail'))hero.appendChild(make('product-image-disclaimer detail'));
  }else if(hero){
    hero.querySelector('.product-image-disclaimer.detail')?.remove();
  }

  document.querySelectorAll('.cart-item').forEach(item=>{
    const image=item.querySelector('.mini-visual img');
    const copy=item.children[1];
    if(image&&copy&&!copy.querySelector('.product-disclaimer-line'))copy.appendChild(make('product-disclaimer-line'));
  });
}
function schedule(){if(scheduled)return;scheduled=true;requestAnimationFrame(apply)}
new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['src','hidden','class']});
window.addEventListener('kapouch:catalog',schedule);
window.addEventListener('hashchange',schedule);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)schedule()});
schedule();
})();