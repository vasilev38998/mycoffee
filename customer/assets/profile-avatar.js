(function(){
'use strict';
if(window.__KAPOUCH_PROFILE_AVATAR_LOADED)return;
window.__KAPOUCH_PROFILE_AVATAR_LOADED=true;

const cfg=window.KAPOUCH_CUSTOMER_CONFIG||{apiBase:'https://kapouch.store/api',appBase:'./'};
const apiBase=String(cfg.apiBase||'https://kapouch.store/api').replace(/\/$/,'');
const appBase=String(cfg.appBase||new URL('./',location.href).href).replace(/\/?$/,'/');
const TOKEN_KEY='kapouch_customer_auth_token';
const token=()=>String(localStorage.getItem(TOKEN_KEY)||'');
const validToken=()=>/^[a-f0-9]{64}$/i.test(token());
let current={name:'',avatar_path:''},busy=false;

const style=document.createElement('style');style.textContent=`
.home-avatar.has-photo{padding:0!important;overflow:hidden!important;background:#f2dfc8!important;box-shadow:0 10px 28px rgba(73,38,20,.12)}.home-avatar.has-photo img{display:block!important;width:100%!important;height:100%!important;min-width:100%!important;max-width:none!important;object-fit:cover!important;object-position:50% 50%!important;margin:0!important;border:0!important}.profile-avatar-card{display:flex;align-items:center;gap:14px;margin:0 0 4px;padding:14px;border-radius:18px;background:#fffaf4;border:1px solid rgba(102,61,34,.12);box-shadow:0 7px 20px rgba(70,37,20,.05)}.profile-avatar-preview{box-sizing:border-box!important;width:78px!important;height:78px!important;aspect-ratio:1/1!important;flex:0 0 78px!important;padding:0!important;margin:0!important;border:0!important;border-radius:22px!important;overflow:hidden!important;display:grid;place-items:center;background:linear-gradient(145deg,#ffd95d,#ffbd22);color:#3b2115;font-size:29px;font-weight:1000;line-height:1!important;box-shadow:0 8px 18px rgba(95,53,19,.14),inset 0 1px 0 rgba(255,255,255,.65);cursor:pointer}.profile-avatar-preview.has-photo{background:transparent!important}.profile-avatar-preview img{display:block!important;width:100%!important;height:100%!important;min-width:100%!important;max-width:none!important;object-fit:cover!important;object-position:50% 50%!important;margin:0!important;padding:0!important;border:0!important;border-radius:inherit!important}.profile-avatar-copy{min-width:0;flex:1}.profile-avatar-copy strong{display:block;font-size:15px;line-height:1.15;color:#3d2317}.profile-avatar-copy span{display:block;margin-top:4px;color:#7f6c60;font-size:11px;line-height:1.35}.profile-avatar-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.profile-avatar-actions button{border:0;border-radius:12px;padding:9px 12px;font-size:11px;font-weight:900;cursor:pointer}.profile-avatar-actions button:disabled{opacity:.55;cursor:default}.profile-avatar-pick{background:#3d2317;color:#fff8eb}.profile-avatar-remove{background:#f2e7dc;color:#704b39}.profile-avatar-status{margin-top:7px;font-size:10px;color:#8f5937;font-weight:750}.profile-avatar-status.error{color:#a23f32}@media(max-width:380px){.profile-avatar-card{gap:11px;padding:12px}.profile-avatar-preview{width:68px!important;height:68px!important;flex-basis:68px!important;border-radius:20px!important}.profile-avatar-actions button{padding:8px 10px}}
`;document.head.appendChild(style);

function imageUrl(path){path=String(path||'').trim();if(!path)return '';if(/^https?:\/\//i.test(path))return path;return appBase+path.replace(/^\/+/, '')}
function initial(name){const s=String(name||'').trim();return (s?Array.from(s)[0]:'K').toUpperCase()}
function setButtonsDisabled(disabled){const pick=document.getElementById('profileAvatarPick'),remove=document.getElementById('profileAvatarRemove');if(pick)pick.disabled=disabled;if(remove)remove.disabled=disabled}
function avatarDataContent(){return document.querySelector('#profileDataFold .profile-data-content')||document.querySelector('#profileDataFold .profile-fold-content')}
function wireCard(card){
  if(!card||card.dataset.avatarWired==='1')return card;
  card.dataset.avatarWired='1';
  const input=document.getElementById('profileAvatarInput'),pick=document.getElementById('profileAvatarPick'),preview=document.getElementById('profileAvatarPreview'),remove=document.getElementById('profileAvatarRemove');
  const choose=()=>{if(!busy&&input)input.click()};
  if(pick)pick.onclick=choose;
  if(preview){preview.onclick=choose;preview.setAttribute('role','button');preview.setAttribute('aria-label','Выбрать фотографию профиля');preview.tabIndex=0;preview.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();choose()}}}
  if(input)input.onchange=()=>{const file=input.files&&input.files[0];input.value='';if(file)upload(file)};
  if(remove)remove.onclick=removeAvatar;
  return card;
}
function ensureCard(){
  const profile=document.getElementById('profileUser'),dataContent=avatarDataContent();if(!profile||!dataContent)return null;
  let card=document.getElementById('profileAvatarCard');
  if(!card){
    card=document.createElement('section');card.id='profileAvatarCard';card.className='profile-avatar-card';
    card.innerHTML='<button class="profile-avatar-preview" id="profileAvatarPreview" type="button" aria-label="Выбрать фотографию профиля">K</button><div class="profile-avatar-copy"><strong>Фото профиля</strong><span>Фото автоматически обрежется по центру до квадрата 1:1.</span><div class="profile-avatar-actions"><button class="profile-avatar-pick" id="profileAvatarPick" type="button">Добавить фото</button><button class="profile-avatar-remove" id="profileAvatarRemove" type="button" hidden>Удалить</button></div><div class="profile-avatar-status" id="profileAvatarStatus" hidden></div><input id="profileAvatarInput" type="file" accept="image/jpeg,image/png,image/webp,image/*" hidden></div>';
  }
  if(card.parentNode!==dataContent){const form=dataContent.querySelector('#profileDetailsForm');dataContent.insertBefore(card,form||dataContent.firstChild)}
  return wireCard(card);
}
function syncSurface(node,url){
  if(!node)return;
  const letter=initial(current.name),img=node.querySelector('img[data-kapouch-profile-avatar]');
  if(url){
    if(!node.classList.contains('has-photo'))node.classList.add('has-photo');
    if(!img||img.getAttribute('src')!==url){node.innerHTML='<img data-kapouch-profile-avatar="1" src="'+url+'" alt="">'}
  }else{
    if(node.classList.contains('has-photo'))node.classList.remove('has-photo');
    if(node.textContent!==letter||node.children.length)node.textContent=letter;
  }
}
function syncGlobalAvatars(){const url=imageUrl(current.avatar_path||'');document.querySelectorAll('.home-avatar,[data-customer-avatar]').forEach(node=>syncSurface(node,url))}
function renderAvatarBox(){
  ensureCard();const path=current.avatar_path||'',url=imageUrl(path),preview=document.getElementById('profileAvatarPreview'),pick=document.getElementById('profileAvatarPick'),remove=document.getElementById('profileAvatarRemove');
  if(preview){preview.classList.toggle('has-photo',!!url);preview.innerHTML=url?'<img src="'+url+'" alt="Фото профиля">':initial(current.name)}
  if(pick)pick.textContent=url?'Изменить фото':'Добавить фото';if(remove)remove.hidden=!url;
  syncGlobalAvatars();
}
function renderProfile(profile){const c=profile&&profile.customer?profile.customer:{};current={name:String(c.name||''),avatar_path:String(c.avatar_path||c.avatar_url||'')};renderAvatarBox()}
function setStatus(text,error=false){const el=document.getElementById('profileAvatarStatus');if(!el)return;el.hidden=!text;el.textContent=text||'';el.classList.toggle('error',!!error)}
function loadImage(file){return new Promise((resolve,reject)=>{const url=URL.createObjectURL(file),img=new Image();img.onload=()=>{URL.revokeObjectURL(url);resolve(img)};img.onerror=()=>{URL.revokeObjectURL(url);reject(new Error('Не удалось прочитать фотографию.'))};img.src=url})}
async function squareFile(file){
  if(!file||file.size<=0)throw new Error('Выберите фотографию.');if(file.size>20*1024*1024)throw new Error('Фото должно быть не больше 20 МБ.');
  const img=await loadImage(file),sw=img.naturalWidth||img.width,sh=img.naturalHeight||img.height;if(!sw||!sh)throw new Error('Некорректное изображение.');
  const side=Math.min(sw,sh),sx=Math.max(0,(sw-side)/2),sy=Math.max(0,(sh-side)/2),size=900,canvas=document.createElement('canvas');canvas.width=size;canvas.height=size;
  const ctx=canvas.getContext('2d');if(!ctx)throw new Error('Не удалось подготовить фотографию.');ctx.drawImage(img,sx,sy,side,side,0,0,size,size);
  const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.9));if(!blob)throw new Error('Не удалось подготовить фотографию.');return blob;
}
async function request(form){
  if(!validToken())throw new Error('Сначала войдите в профиль.');
  const r=await fetch(apiBase+'/customer_profile_avatar.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','X-Customer-Token':token()},body:form});
  const d=await r.json().catch(()=>null);if(!r.ok||!d?.ok)throw new Error(d?.error||'Не удалось обновить фотографию.');return d;
}
async function refreshProfile(){
  if(!validToken())return;
  try{const r=await fetch(apiBase+'/customer_profile.php?_avatar='+Date.now(),{cache:'no-store',headers:{Accept:'application/json','X-Customer-Token':token()}});const d=await r.json().catch(()=>null);if(r.ok&&d?.ok&&d.profile)renderProfile(d.profile)}catch(e){}
}
async function upload(file){
  if(busy)return;busy=true;ensureCard();setButtonsDisabled(true);setStatus('Обрезаем фото по центру…');
  try{
    const prepared=await squareFile(file),form=new FormData();form.append('action','upload');form.append('avatar',prepared,'avatar.jpg');setStatus('Сохраняем фото…');
    const d=await request(form);current.avatar_path=String(d.avatar_path||d.avatar_url||'');renderAvatarBox();setStatus('Фото профиля обновлено ✓');setTimeout(refreshProfile,3500);setTimeout(()=>setStatus(''),2200);
  }catch(e){setStatus(e?.message||'Не удалось обновить фотографию.',true)}finally{busy=false;setButtonsDisabled(false)}
}
async function removeAvatar(){
  if(busy||!current.avatar_path)return;busy=true;setButtonsDisabled(true);setStatus('Удаляем фото…');
  try{const form=new FormData();form.append('action','delete');await request(form);current.avatar_path='';renderAvatarBox();setStatus('Фото удалено.');setTimeout(refreshProfile,3500);setTimeout(()=>setStatus(''),1800)}catch(e){setStatus(e?.message||'Не удалось удалить фотографию.',true)}finally{busy=false;setButtonsDisabled(false)}
}
let syncQueued=false;
function queueSync(){if(syncQueued)return;syncQueued=true;requestAnimationFrame(()=>{syncQueued=false;ensureCard();renderAvatarBox()})}
const domObserver=new MutationObserver(queueSync);
function start(){ensureCard();renderAvatarBox();domObserver.observe(document.body,{childList:true,subtree:true,characterData:true});if(validToken())refreshProfile()}
window.addEventListener('kapouch:profile',e=>{if(e.detail?.profile){renderProfile(e.detail.profile);requestAnimationFrame(syncGlobalAvatars)}});
window.addEventListener('storage',e=>{if(e.key!==TOKEN_KEY)return;if(validToken())refreshProfile();else{current={name:'',avatar_path:''};renderAvatarBox()}});
window.addEventListener('hashchange',()=>requestAnimationFrame(syncGlobalAvatars));
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
