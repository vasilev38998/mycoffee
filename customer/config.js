window.KAPOUCH_CUSTOMER_CONFIG = {
  apiBase: '../api',
  pollIntervalMs: 3000
};
window.addEventListener('DOMContentLoaded',function(){
  var s=document.createElement('script');
  s.src='assets/push.js?v=1';
  s.defer=true;
  document.body.appendChild(s);
});
