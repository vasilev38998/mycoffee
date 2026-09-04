window.KAPOUCH_CUSTOMER_CONFIG = {
  apiBase: '../api',
  pollIntervalMs: 3000
};
window.addEventListener('DOMContentLoaded',function(){
  ['assets/push.js?v=1','assets/pwa-v2.js?v=1'].forEach(function(src){var s=document.createElement('script');s.src=src;s.defer=true;document.body.appendChild(s);});
});
