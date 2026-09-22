(function(){
'use strict';
const quick=document.getElementById('loyaltyQuickButton');
const profileButton=document.querySelector('.bottom-nav [data-nav="profile"]');
const themeMeta=document.querySelector('meta[name="theme-color"]');
function syncTheme(){if(themeMeta)themeMeta.setAttribute('content','#f7f1e8')}
function openProfile(){
  if(profileButton)profileButton.click();
  window.setTimeout(()=>{
    const target=document.querySelector('#profileUser:not([hidden]) .profile-balance, #profileGuest:not([hidden])');
    if(target)target.scrollIntoView({behavior:'smooth',block:'start'});
  },180);
}
if(quick)quick.addEventListener('click',openProfile);
syncTheme();
window.addEventListener('load',()=>{syncTheme();window.setTimeout(syncTheme,700)});
document.addEventListener('visibilitychange',()=>{if(!document.hidden)syncTheme()});
})();
