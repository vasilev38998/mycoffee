window.KAPOUCH_CUSTOMER_CONFIG = {
  apiBase: 'https://kapouch.store/api',
  appBase: 'https://app.kapouch.store/',
  pollIntervalMs: 10000
};
(function(){
  var apiBase=String(window.KAPOUCH_CUSTOMER_CONFIG.apiBase||'').replace(/\/$/,'');
  var nativeFetch=window.fetch.bind(window);
  var profileFailures=0;
  var profileBlockedUntil=0;
  var catalogShared={promise:null,response:null,at:0};
  var profileShared=new Map();

  function requestUrl(input){
    if(typeof input==='string')return input;
    if(input instanceof URL)return input.href;
    if(input&&typeof input.url==='string')return input.url;
    return '';
  }
  function requestMethod(init){return String((init&&init.method)||'GET').toUpperCase();}
  function headerValue(init,name){
    try{return new Headers((init&&init.headers)||{}).get(name)||''}catch(e){return ''}
  }
  function isProfileRequest(input){return requestUrl(input).indexOf('/customer_profile.php')!==-1;}
  function isCatalogRequest(input){return requestUrl(input).indexOf('/customer_catalog.php')!==-1;}
  function isOrderRequest(input){return requestUrl(input).indexOf('/customer_order.php')!==-1;}
  function loyaltyMode(){var mode=String(localStorage.getItem('kapouch_loyalty_mode')||'gift');return ['gift','points','none'].indexOf(mode)!==-1?mode:'gift';}
  function attachLoyaltyMode(input,init){
    if(!isOrderRequest(input)||requestMethod(init)!=='POST'||!init||typeof init.body!=='string')return init;
    try{
      var payload=JSON.parse(init.body);
      if(!payload||Array.isArray(payload)||typeof payload!=='object')return init;
      payload.loyalty_mode=loyaltyMode();
      return Object.assign({},init,{body:JSON.stringify(payload)});
    }catch(e){return init}
  }
  function publishJson(response,eventName,assignShop){
    if(!response||!response.ok)return;
    response.clone().json().then(function(data){
      if(!data||!data.ok)return;
      if(assignShop)window.KAPOUCH_CATALOG_SHOP=data.shop||{};
      try{window.dispatchEvent(new CustomEvent(eventName,{detail:data}));}catch(e){}
    }).catch(function(){});
  }
  function profileFailure(){
    profileFailures=Math.min(6,profileFailures+1);
    var delays=[0,10000,20000,40000,60000,120000,120000];
    profileBlockedUntil=Date.now()+delays[profileFailures];
  }
  function profileSuccess(){profileFailures=0;profileBlockedUntil=0;}
  function sharedGet(entry,input,init,ttl,onResponse){
    var now=Date.now();
    if(entry.response&&now-entry.at<ttl){
      try{return Promise.resolve(entry.response.clone())}catch(e){entry.response=null;entry.at=0}
    }
    if(entry.promise)return entry.promise.then(function(response){return response.clone()});
    entry.promise=nativeFetch(input,init).then(function(response){
      if(response.ok){
        try{entry.response=response.clone();entry.at=Date.now()}catch(e){entry.response=null;entry.at=0}
      }
      if(onResponse)onResponse(response);
      return response;
    }).finally(function(){entry.promise=null});
    return entry.promise.then(function(response){return response.clone()});
  }

  window.fetch=function(input,init){
    if(typeof input==='string'&&input.indexOf('../api/')===0)input=apiBase+'/'+input.slice('../api/'.length);
    else if(input instanceof URL&&input.href.indexOf(new URL('../api/',window.location.href).href)===0)input=new URL(apiBase+'/'+input.href.slice(new URL('../api/',window.location.href).href.length));

    init=attachLoyaltyMode(input,init);
    var method=requestMethod(init);
    var profile=isProfileRequest(input);
    var catalog=isCatalogRequest(input);
    if(profile&&method==='GET'&&Date.now()<profileBlockedUntil){
      return Promise.reject(new TypeError('Kapouch profile endpoint is cooling down after a server error'));
    }
    if(catalog&&method==='GET'){
      return sharedGet(catalogShared,input,init,10000,function(response){publishJson(response,'kapouch:catalog',true)});
    }
    if(profile&&method==='GET'){
      var token=headerValue(init,'X-Customer-Token');
      var entry=profileShared.get(token);
      if(!entry){entry={promise:null,response:null,at:0};profileShared.set(token,entry)}
      return sharedGet(entry,input,init,3000,function(response){
        if(response.ok||((response.status>=400&&response.status<500)&&response.status!==429))profileSuccess();
        else profileFailure();
        publishJson(response,'kapouch:profile',false);
      }).catch(function(error){profileFailure();throw error});
    }
    return nativeFetch(input,init).then(function(response){
      if(profile){
        if(response.ok||((response.status>=400&&response.status<500)&&response.status!==429))profileSuccess();
        else profileFailure();
        if(response.ok)publishJson(response,'kapouch:profile',false);
      }
      return response;
    },function(error){
      if(profile)profileFailure();
      throw error;
    });
  };
})();
window.addEventListener('DOMContentLoaded',function(){
  var authStyle=document.createElement('link');
  authStyle.rel='stylesheet';
  authStyle.href='assets/auth-required.css?v=1';
  document.head.appendChild(authStyle);

  var giftStyle=document.createElement('link');
  giftStyle.rel='stylesheet';
  giftStyle.href='assets/sixth-drink-checkout.css?v=3';
  document.head.appendChild(giftStyle);

  var disclaimerStyle=document.createElement('link');
  disclaimerStyle.rel='stylesheet';
  disclaimerStyle.href='assets/product-disclaimer.css?v=1';
  document.head.appendChild(disclaimerStyle);

  var contrastStyle=document.createElement('link');
  contrastStyle.rel='stylesheet';
  contrastStyle.href='assets/contrast-fix.css?v=1';
  document.head.appendChild(contrastStyle);

  var polishStyle=document.createElement('link');
  polishStyle.rel='stylesheet';
  polishStyle.href='assets/pwa-polish.css?v=2';
  document.head.appendChild(polishStyle);

  var polish=document.createElement('script');
  polish.src='assets/pwa-polish.js?v=3';
  polish.defer=true;
  document.body.appendChild(polish);

  var authRequired=document.createElement('script');
  authRequired.src='assets/auth-required.js?v=1';
  authRequired.defer=true;
  document.body.appendChild(authRequired);

  var giftCheckout=document.createElement('script');
  giftCheckout.src='assets/sixth-drink-checkout.js?v=5';
  giftCheckout.defer=true;
  document.body.appendChild(giftCheckout);

  var disclaimer=document.createElement('script');
  disclaimer.src='assets/product-disclaimer.js?v=1';
  disclaimer.defer=true;
  document.body.appendChild(disclaimer);

  var phoneMask=document.createElement('script');
  phoneMask.src='assets/phone-mask.js?v=1';
  phoneMask.defer=true;
  document.body.appendChild(phoneMask);

  var s=document.createElement('script');
  s.src='assets/push.js?v=2';
  s.defer=true;
  document.body.appendChild(s);

  var compact=document.createElement('script');
  compact.src='assets/profile-compact.js?v=2';
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
    var card=document.createElement('script');card.src='assets/loyalty-card.js?v=6';card.defer=true;card.dataset.kapouchLoyaltyCard='1';document.body.appendChild(card);
  };
  qr.onload=loadCard;qr.onerror=loadCard;document.body.appendChild(qr);
});