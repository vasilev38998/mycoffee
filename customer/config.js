window.KAPOUCH_CUSTOMER_CONFIG = {
  apiBase: '../api',
  pollIntervalMs: 3000
};
window.addEventListener('DOMContentLoaded',function(){
  var s=document.createElement('script');
  s.src='assets/push.js?v=1';
  s.defer=true;
  document.body.appendChild(s);

  var compact=document.createElement('script');
  compact.src='assets/profile-compact.js?v=1';
  compact.defer=true;
  document.body.appendChild(compact);

  var qr=document.createElement('script');
  qr.src='https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
  qr.integrity='sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==';
  qr.crossOrigin='anonymous';
  qr.referrerPolicy='no-referrer';
  var loadCard=function(){
    if(document.querySelector('script[data-kapouch-loyalty-card]'))return;
    var card=document.createElement('script');card.src='assets/loyalty-card.js?v=4';card.defer=true;card.dataset.kapouchLoyaltyCard='1';document.body.appendChild(card);
  };
  qr.onload=loadCard;qr.onerror=loadCard;document.body.appendChild(qr);
});
