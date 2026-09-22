(function(){
'use strict';
const quick=document.getElementById('loyaltyQuickButton');
if(!quick)return;
function openProfile(){
  const profileButton=document.querySelector('.bottom-nav [data-nav="profile"]');
  if(profileButton)profileButton.click();
  window.setTimeout(()=>{
    const target=document.querySelector('#profileUser:not([hidden]) .profile-balance, #profileGuest:not([hidden])');
    if(target)target.scrollIntoView({behavior:'smooth',block:'start'});
  },180);
}
quick.addEventListener('click',openProfile);
})();
