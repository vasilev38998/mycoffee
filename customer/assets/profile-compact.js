(function(){
'use strict';
const view=document.querySelector('.view[data-view="profile"]');
const profile=document.getElementById('profileUser');
if(!view||!profile)return;

const style=document.createElement('style');style.textContent=`
.view[data-view="profile"] .page-head{padding:18px 0 10px}.view[data-view="profile"] .page-head h1{font-size:27px;margin-bottom:2px}.view[data-view="profile"] .page-head p{font-size:12px;line-height:1.35;max-width:390px}.view[data-view="profile"] .profile-balance{padding:14px 15px;border-radius:18px;min-height:78px}.view[data-view="profile"] .profile-balance strong{font-size:28px}.view[data-view="profile"] .profile-balance .balance-k{width:48px;height:48px;font-size:25px}.view[data-view="profile"] .section-title{margin:20px 0 9px}.view[data-view="profile"] .section-title h2{font-size:18px}.view[data-view="profile"] .profile-list{gap:6px}.view[data-view="profile"] .profile-row{padding:10px 11px;border-radius:13px}.view[data-view="profile"] .profile-card{padding:15px;border-radius:18px}.view[data-view="profile"] #profileGuest .profile-icon{width:56px;height:56px;font-size:28px}.view[data-view="profile"] #profileGuest h2{margin-top:9px;font-size:19px}.view[data-view="profile"] #profileGuest p{margin-bottom:11px;font-size:12px}.profile-fold{margin-top:10px;border:1px solid rgba(255,255,255,.07);border-radius:17px;background:#171513;overflow:hidden}.profile-fold>summary{list-style:none;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 14px;cursor:pointer}.profile-fold>summary::-webkit-details-marker{display:none}.profile-fold-summary{display:grid;gap:2px;min-width:0}.profile-fold-summary strong{font-size:13px;color:var(--text)}.profile-fold-summary span{font-size:10px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profile-fold-chevron{flex:0 0 auto;color:var(--accent);font-size:18px;line-height:1;transition:transform .16s ease}.profile-fold[open] .profile-fold-chevron{transform:rotate(90deg)}.profile-fold-content{padding:0 12px 13px}.profile-fold .notification-card{margin:0;padding:2px 0 0;border:0;background:transparent;border-radius:0}.profile-fold .notification-card h2{font-size:18px;margin:6px 0}.profile-fold .notification-card p{font-size:12px;margin-bottom:10px}.profile-fold .notification-card .accent-button{padding:11px 13px;font-size:13px}.profile-fold .links-card{margin:0;padding:0;border:0;background:transparent;gap:5px}.profile-fold .links-card a{padding:9px 10px;font-size:12px}.profile-fold .logout{margin:7px 0 0}.profile-fold .pwa-legal-links{margin-top:9px}.profile-fold .pwa-legal-links a{font-size:10px}.profile-fold-loyalty .loyalty-summary{margin:0 0 7px}.profile-fold-loyalty .profile-list{margin-top:0}.view[data-view="profile"] .loyalty-card{margin:10px 0;padding:14px;border-radius:19px}.view[data-view="profile"] .loyalty-card:after{font-size:118px;right:-10px;top:-27px}.view[data-view="profile"] .loyalty-card-head h2{font-size:19px;margin-top:3px}.view[data-view="profile"] .loyalty-card-head span{font-size:8px}.view[data-view="profile"] .loyalty-card-balance{display:none}.view[data-view="profile"] .loyalty-card-body{grid-template-columns:116px minmax(0,1fr);gap:12px;margin-top:12px}.view[data-view="profile"] .loyalty-card-qr{width:116px;height:116px;padding:7px;border-radius:14px}.view[data-view="profile"] .loyalty-card-copy{font-size:11px;line-height:1.35}.view[data-view="profile"] .loyalty-card-copy strong{font-size:14px;margin-bottom:3px}.view[data-view="profile"] .loyalty-card-copy button{margin-top:8px;padding:8px 10px;border-radius:11px;font-size:11px}.view[data-view="profile"] .loyalty-card-status{margin-top:5px;font-size:9px}.profile-compact-details{margin-top:10px}.profile-compact-details .profile-plus-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:10px}.profile-compact-details .profile-stat{padding:10px;border-radius:13px}.profile-compact-details .profile-stat strong{font-size:15px}.profile-compact-details .auth-form{gap:7px}.profile-compact-details .auth-form input{padding:11px 12px}.profile-compact-details .accent-button{padding:11px 13px;font-size:13px}.profile-compact-details .profile-edit-note{font-size:9px}.profile-primary-stack{display:grid;gap:0}
@media(max-width:390px){.view[data-view="profile"] .page-head h1{font-size:25px}.view[data-view="profile"] .loyalty-card-body{grid-template-columns:102px minmax(0,1fr);gap:10px}.view[data-view="profile"] .loyalty-card-qr{width:102px;height:102px}.view[data-view="profile"] .loyalty-card-copy{font-size:10px}.profile-fold>summary{padding:12px}.profile-compact-details .profile-plus-grid{grid-template-columns:1fr 1fr}}
`;document.head.appendChild(style);

const head=view.querySelector('.page-head p');
if(head)head.textContent='Карта, бонусы и последние заказы.';

function makeFold(anchor,title,note,extraClass){
  const details=document.createElement('details');details.className='profile-fold '+(extraClass||'');
  const summary=document.createElement('summary');summary.innerHTML='<span class="profile-fold-summary"><strong>'+title+'</strong><span>'+note+'</span></span><span class="profile-fold-chevron">›</span>';
  const content=document.createElement('div');content.className='profile-fold-content';details.append(summary,content);
  anchor.parentNode.insertBefore(details,anchor);return {details,content};
}

const notification=profile.querySelector('.notification-card');
if(notification&&!notification.closest('.profile-fold')){
  const fold=makeFold(notification,'Уведомления','Push о заказах и бонусах','profile-fold-notifications');
  fold.content.appendChild(notification);
}

const loyalty=document.getElementById('profileLoyalty');
if(loyalty&&!loyalty.closest('.profile-fold')){
  const title=loyalty.previousElementSibling&&loyalty.previousElementSibling.classList.contains('section-title')?loyalty.previousElementSibling:null;
  const anchor=title||loyalty;
  const fold=makeFold(anchor,'История бонусов','Начисления и списания','profile-fold-loyalty');
  const summary=document.getElementById('loyaltySummary');
  if(summary)fold.content.appendChild(summary);
  fold.content.appendChild(loyalty);
  if(title)title.remove();
}

const links=document.getElementById('externalLinks');
const logout=document.getElementById('logoutButton');
const legal=view.querySelector(':scope > .pwa-legal-links');
if((links||logout||legal)&&!(links&&links.closest('.profile-fold-settings'))){
  const anchor=links||logout||legal;
  const fold=makeFold(anchor,'Ещё','Ссылки, документы и выход','profile-fold-settings');
  if(links)fold.content.appendChild(links);
  if(legal)fold.content.appendChild(legal);
  if(logout)fold.content.appendChild(logout);
}

const balance=profile.querySelector('.profile-balance');
if(balance&&!balance.parentElement.classList.contains('profile-primary-stack')){
  const stack=document.createElement('div');stack.className='profile-primary-stack';
  balance.parentNode.insertBefore(stack,balance);stack.appendChild(balance);
  const moveCard=()=>{const card=document.getElementById('personalLoyaltyCard');if(card&&card.parentNode!==stack)stack.appendChild(card)};
  moveCard();
  const observer=new MutationObserver(moveCard);observer.observe(profile,{childList:true,subtree:false});
  setTimeout(()=>observer.disconnect(),8000);
}
})();
