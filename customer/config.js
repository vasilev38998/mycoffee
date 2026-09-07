window.KAPOUCH_CUSTOMER_CONFIG = {
  apiBase: 'https://kapouch.store/api',
  appBase: 'https://app.kapouch.store/',
  pollIntervalMs: 3000
};
(function(){
  var apiBase=String(window.KAPOUCH_CUSTOMER_CONFIG.apiBase||'').replace(/\/$/,'');
  var nativeFetch=window.fetch.bind(window);
  window.fetch=function(input,init){
    if(typeof input==='string'&&input.indexOf('../api/')===0)input=apiBase+'/'+input.slice('../api/'.length);
    else if(input instanceof URL&&input.href.indexOf(new URL('../api/',window.location.href).href)===0)input=new URL(apiBase+'/'+input.href.slice(new URL('../api/',window.location.href).href.length));
    return nativeFetch(input,init);
  };
})();
window.addEventListener('DOMContentLoaded',function(){
  var authStyle=document.createElement('link');
  authStyle.rel='stylesheet';
  authStyle.href='assets/auth-required.css?v=1';
  document.head.appendChild(authStyle);

  var giftStyle=document.createElement('link');
  giftStyle.rel='stylesheet';
  giftStyle.href='assets/sixth-drink-checkout.css?v=1';
  document.head.appendChild(giftStyle);

  var authRequired=document.createElement('script');
  authRequired.src='assets/auth-required.js?v=1';
  authRequired.defer=true;
  document.body.appendChild(authRequired);

  var giftCheckout=document.createElement('script');
  giftCheckout.src='assets/sixth-drink-checkout.js?v=2';
  giftCheckout.defer=true;
  document.body.appendChild(giftCheckout);

  var phoneMask=document.createElement('script');
  phoneMask.src='assets/phone-mask.js?v=1';
  phoneMask.defer=true;
  document.body.appendChild(phoneMask);

  var s=document.createElement('script');
  s.src='assets/push.js?v=1';
  s.defer=true;
  document.body.appendChild(s);

  var compact=document.createElement('script');
  compact.src='assets/profile-compact.js?v=1';
  compact.defer=true;
  document.body.appendChild(compact);

  var statusOnce=document.createElement('script');
  statusOnce.src='assets/status-once.js?v=1';
  statusOnce.defer=true;
  document.body.appendChild(statusOnce);

  var qr=document.createElement('script');
  qr.src='https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
  qr.integrity='sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==';
  qr.crossOrigin='anonymous';
  qr.referrerPolicy='no-referrer';
  var loadCard=function(){
    if(document.querySelector('script[data-kapouch-loyalty-card]'))return;
    var card=document.createElement('script');card.src='assets/loyalty-card.js?v=5';card.defer=true;card.dataset.kapouchLoyaltyCard='1';document.body.appendChild(card);
  };
  qr.onload=loadCard;qr.onerror=loadCard;document.body.appendChild(qr);
});
