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
  var PROFILE_SNAPSHOT='kapouch_offline_profile_v1';
  var LOYALTY_SNAPSHOT='kapouch_offline_loyalty_v1';
  var SNAPSHOT_MAX_AGE=30*86400000;

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
  function isLoyaltyCardRequest(input){return requestUrl(input).indexOf('/customer_loyalty_card.php')!==-1;}
  function isLogoutRequest(input){return requestUrl(input).indexOf('/customer_logout.php')!==-1;}
  function isOrderRequest(input){return requestUrl(input).indexOf('/customer_order.php')!==-1;}
  function isConnectivityProbe(input){return requestUrl(input).indexOf('connectivity_probe=1')!==-1;}
  function signal(type,resource){try{window.dispatchEvent(new CustomEvent(type,{detail:{resource:resource||'network'}}))}catch(e){}}
  function ownerKey(token){token=String(token||'');return token.length>=24?token.slice(0,12)+':'+token.slice(-12):''}
  function readStored(key,token){
    try{
      var row=JSON.parse(localStorage.getItem(key)||'null');
      if(!row||row.owner!==ownerKey(token)||!row.saved_at||Date.now()-Number(row.saved_at)>SNAPSHOT_MAX_AGE)return null;
      return row;
    }catch(e){return null}
  }
  function writeStored(key,token,data){
    var owner=ownerKey(token);if(!owner||!data)return;
    try{localStorage.setItem(key,JSON.stringify({owner:owner,saved_at:Date.now(),data:data}))}catch(e){}
  }
  function clearOfflineSnapshots(){try{localStorage.removeItem(PROFILE_SNAPSHOT);localStorage.removeItem(LOYALTY_SNAPSHOT)}catch(e){}}
  function num(v){var n=Number(v);return Number.isFinite(n)?n:0}
  function cleanText(v,max){return String(v??'').slice(0,max||500)}
  function sanitizeProfile(profile){
    var p=profile||{},c=p.customer||{};
    return {
      customer:{id:num(c.id),name:cleanText(c.name,160),loyalty_balance:num(c.loyalty_balance),avatar_path:cleanText(c.avatar_path,255),avatar_url:cleanText(c.avatar_url,255)},
      orders:(Array.isArray(p.orders)?p.orders:[]).slice(0,12).map(function(o){return {order_number:cleanText(o?.order_number,64),status:cleanText(o?.status,32),status_label:cleanText(o?.status_label,80),total_amount:num(o?.total_amount),external_created_at:cleanText(o?.external_created_at,40),created_at:cleanText(o?.created_at,40)}}),
      loyalty:(Array.isArray(p.loyalty)?p.loyalty:[]).slice(0,12).map(function(r){return {amount:num(r?.amount),operation_type:cleanText(r?.operation_type,40),note:cleanText(r?.note,200),created_at:cleanText(r?.created_at,40)}}),
      stats:{completed_orders:num(p.stats?.completed_orders),completed_spend:num(p.stats?.completed_spend),favorite_product:cleanText(p.stats?.favorite_product,180),favorite_quantity:num(p.stats?.favorite_quantity),loyalty_earned:num(p.stats?.loyalty_earned)},
      drink_loyalty:p.drink_loyalty?JSON.parse(JSON.stringify(p.drink_loyalty)):null,
      _offline_snapshot:true
    };
  }
  function sanitizeLoyalty(data){
    var card=data?.card||{},c=card.customer||{};
    return {card:{code:cleanText(card.code,300),version:num(card.version),customer:{id:num(c.id),name:cleanText(c.name,160),loyalty_balance:num(c.loyalty_balance)},loyalty_rate:num(card.loyalty_rate),drink_loyalty:card.drink_loyalty?JSON.parse(JSON.stringify(card.drink_loyalty)):null}};
  }
  function offlineResponse(key,token){
    var row=readStored(key,token);if(!row)return null;
    var data=Object.assign({ok:true,offline:true,cached_at:Number(row.saved_at)},row.data||{});
    return new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store'}});
  }
  function rememberResponse(response,key,token,sanitizer){
    if(!response||!response.ok)return;
    response.clone().json().then(function(data){if(data&&data.ok)writeStored(key,token,sanitizer(data))}).catch(function(){});
  }
  function loyaltyMode(){var mode=String(localStorage.getItem('kapouch_loyalty_mode')||'gift');return ['gift','points','wheel','none'].indexOf(mode)!==-1?mode:'gift';}
  function wheelRewardId(){var id=Number(localStorage.getItem('kapouch_wheel_reward_id')||0);return Number.isInteger(id)&&id>0?id:0;}
  function attachLoyaltyMode(input,init){
    if(!isOrderRequest(input)||requestMethod(init)!=='POST'||!init||typeof init.body!=='string')return init;
    try{
      var payload=JSON.parse(init.body);
      if(!payload||Array.isArray(payload)||typeof payload!=='object')return init;
      payload.loyalty_mode=loyaltyMode();
      if(payload.loyalty_mode==='wheel')payload.wheel_reward_id=wheelRewardId();
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
    if(typeof input==='string'&&input.indexOf('/customer_wheel.php')!==-1)input=input.replace('/customer_wheel.php','/customer_wheel_api.php');
    else if(input instanceof URL&&input.pathname.endsWith('/customer_wheel.php'))input=new URL(input.href.replace('/customer_wheel.php','/customer_wheel_api.php'));
    if(typeof input==='string'&&input.indexOf('../api/')===0)input=apiBase+'/'+input.slice('../api/'.length);
    else if(input instanceof URL&&input.href.indexOf(new URL('../api/',window.location.href).href)===0)input=new URL(apiBase+'/'+input.href.slice(new URL('../api/',window.location.href).href.length));

    init=attachLoyaltyMode(input,init);
    var method=requestMethod(init),url=requestUrl(input);
    var profile=isProfileRequest(input),catalog=isCatalogRequest(input),loyaltyCard=isLoyaltyCardRequest(input),logout=isLogoutRequest(input);
    if(isConnectivityProbe(input)){
      return nativeFetch(input,init).then(function(response){if(response.ok)signal('kapouch:network-online','probe');else if(response.status>=500)signal('kapouch:network-offline','probe');return response},function(error){signal('kapouch:network-offline','probe');throw error});
    }
    if(profile&&method==='GET'){
      var token=headerValue(init,'X-Customer-Token');
      if(Date.now()<profileBlockedUntil){var cooled=offlineResponse(PROFILE_SNAPSHOT,token);if(cooled){signal('kapouch:network-offline','profile');publishJson(cooled,'kapouch:profile',false);return Promise.resolve(cooled)}return Promise.reject(new TypeError('Kapouch profile endpoint is cooling down after a server error'))}
      var entry=profileShared.get(token);if(!entry){entry={promise:null,response:null,at:0};profileShared.set(token,entry)}
      return sharedGet(entry,input,init,3000,function(response){
        if(response.ok||((response.status>=400&&response.status<500)&&response.status!==429))profileSuccess();else profileFailure();
        if(response.ok){rememberResponse(response,PROFILE_SNAPSHOT,token,function(data){return {profile:sanitizeProfile(data.profile)}});signal('kapouch:network-online','profile')}
        if(response.status===401){try{localStorage.removeItem(PROFILE_SNAPSHOT);localStorage.removeItem(LOYALTY_SNAPSHOT)}catch(e){}}
        publishJson(response,'kapouch:profile',false);
      }).then(function(response){
        if(response.status>=500){var cached=offlineResponse(PROFILE_SNAPSHOT,token);if(cached){signal('kapouch:network-offline','profile');publishJson(cached,'kapouch:profile',false);return cached}}
        return response;
      }).catch(function(error){profileFailure();var cached=offlineResponse(PROFILE_SNAPSHOT,token);if(cached){signal('kapouch:network-offline','profile');publishJson(cached,'kapouch:profile',false);return cached}signal('kapouch:network-offline','profile');throw error});
    }
    if(loyaltyCard&&method==='GET'){
      var loyaltyToken=headerValue(init,'X-Customer-Token');
      return nativeFetch(input,init).then(function(response){
        if(response.ok){rememberResponse(response,LOYALTY_SNAPSHOT,loyaltyToken,sanitizeLoyalty);signal('kapouch:network-online','loyalty');return response}
        if(response.status===401){try{localStorage.removeItem(LOYALTY_SNAPSHOT)}catch(e){}}
        if(response.status>=500){var cached=offlineResponse(LOYALTY_SNAPSHOT,loyaltyToken);if(cached){signal('kapouch:network-offline','loyalty');return cached}}
        return response;
      },function(error){var cached=offlineResponse(LOYALTY_SNAPSHOT,loyaltyToken);if(cached){signal('kapouch:network-offline','loyalty');return cached}signal('kapouch:network-offline','loyalty');throw error});
    }
    if(catalog&&method==='GET'){
      return sharedGet(catalogShared,input,init,10000,function(response){if(response.ok)signal('kapouch:network-online','catalog');publishJson(response,'kapouch:catalog',true)});
    }
    return nativeFetch(input,init).then(function(response){
      if(logout&&response.ok)clearOfflineSnapshots();
      if(response.ok&&url.indexOf(apiBase)===0)signal('kapouch:network-online','api');
      else if(response.status>=500&&url.indexOf(apiBase)===0)signal('kapouch:network-offline','api');
      return response;
    },function(error){if(url.indexOf(apiBase)===0)signal('kapouch:network-offline','api');throw error});
  };
})();
window.addEventListener('DOMContentLoaded',function(){
  var cleanupStyle=document.createElement('style');
  cleanupStyle.id='kapouchUiCleanup';
  cleanupStyle.textContent='body.k-redesign-v2 .view[data-view="home"] #balanceCard{display:none!important}body.k-redesign-v2 .view[data-view="home"] #quickRepeatCard[hidden],body.k-redesign-v2 .view[data-view="home"] #quickRepeatCard:empty{display:none!important}.auth-alt-hint{display:none!important}';
  document.head.appendChild(cleanupStyle);

  var offline=document.createElement('script');
  offline.src='assets/offline-resilience.js?v=1';
  offline.defer=true;
  document.body.appendChild(offline);

  var authStyle=document.createElement('link');
  authStyle.rel='stylesheet';
  authStyle.href='assets/auth-required.css?v=1';
  document.head.appendChild(authStyle);

  var giftStyle=document.createElement('link');
  giftStyle.rel='stylesheet';
  giftStyle.href='assets/sixth-drink-checkout.css?v=4';
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
  giftCheckout.src='assets/sixth-drink-checkout.js?v=7';
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

  var callAuth=document.createElement('script');
  callAuth.src='assets/auth-call.js?v=3';
  callAuth.defer=true;
  document.body.appendChild(callAuth);

  var mapsReviews=document.createElement('script');
  mapsReviews.src='assets/maps-reviews.js?v=1';
  mapsReviews.defer=true;
  document.body.appendChild(mapsReviews);

  var s=document.createElement('script');
  s.src='assets/push.js?v=2';
  s.defer=true;
  document.body.appendChild(s);

  var compact=document.createElement('script');
  compact.src='assets/profile-compact.js?v=2';
  compact.defer=true;
  document.body.appendChild(compact);

  var profileAvatar=document.createElement('script');
  profileAvatar.src='assets/profile-avatar.js?v=2';
  profileAvatar.defer=true;
  profileAvatar.dataset.kapouchProfileAvatar='1';
  document.body.appendChild(profileAvatar);

  var statusOnce=document.createElement('script');
  statusOnce.src='assets/status-once.js?v=1';
  statusOnce.defer=true;
  document.body.appendChild(statusOnce);

  var savedOrder=document.createElement('script');
  savedOrder.src='assets/saved-order.js?v=1';
  savedOrder.defer=true;
  document.body.appendChild(savedOrder);

  var pickupCountdown=document.createElement('script');
  pickupCountdown.src='assets/pickup-countdown.js?v=1';
  pickupCountdown.defer=true;
  document.body.appendChild(pickupCountdown);

  var wheel=document.createElement('script');
  wheel.src='assets/wheel.js?v=1';
  wheel.defer=true;
  document.body.appendChild(wheel);

  var wheelPolish=document.createElement('script');
  wheelPolish.src='assets/wheel-polish.js?v=1';
  wheelPolish.defer=true;
  document.body.appendChild(wheelPolish);

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