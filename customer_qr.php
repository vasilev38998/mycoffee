<?php
declare(strict_types=1);

require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();

function customer_qr_default_url(): string
{
    $host=preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??''));
    if($host==='')return 'https://kapouch.store/customer/';
    $forwarded=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??'');
    $scheme=in_array(strtolower($forwarded),['http','https'],true)?strtolower($forwarded):(kapouch_is_https_request()?'https':'http');
    if($scheme!=='https')$scheme='https';
    return 'https://'.$host.'/customer/';
}

function customer_qr_validate_target(string $value): string
{
    $value=trim($value);
    if($value==='')return '';
    if(strlen($value)>500||!filter_var($value,FILTER_VALIDATE_URL))throw new RuntimeException('Укажите корректную полную ссылку на клиентское приложение.');
    $parts=parse_url($value);
    if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host']))throw new RuntimeException('QR-код может вести только на полный HTTPS-адрес.');
    if(isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('Ссылка для QR-кода не должна содержать логин или пароль.');
    return $value;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if((string)($_POST['action']??'')!=='save')throw new RuntimeException('Неизвестное действие.');
        $target=customer_qr_validate_target((string)($_POST['customer_qr_target_url']??''));
        $title=mb_substr(trim((string)($_POST['customer_qr_title']??'')),0,100);
        $text=mb_substr(trim((string)($_POST['customer_qr_text']??'')),0,240);
        set_app_setting('customer_qr_target_url',$target);
        set_app_setting('customer_qr_title',$title!==''?$title:'Закажи кофе заранее');
        set_app_setting('customer_qr_text',$text!==''?$text:'Сканируй QR-код, выбери напитки и забери заказ без очереди.');
        audit_write('customer_qr_updated','Обновлены QR-код и печатный макет клиентского PWA');
        flash('success','QR-код для клиентов сохранён.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_qr.php');
}

$configuredUrl=trim((string)app_setting('customer_qr_target_url',''));
$targetUrl=$configuredUrl!==''?$configuredUrl:customer_qr_default_url();
$title=(string)app_setting('customer_qr_title','Закажи кофе заранее');
$text=(string)app_setting('customer_qr_text','Сканируй QR-код, выбери напитки и забери заказ без очереди.');
$coffeeName=(string)app_setting('coffee_name','Kapouch');
$urlJson=json_encode($targetUrl,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);

page_header('QR для клиентов');
?>
<style>
.customer-qr-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.8fr);gap:20px;align-items:start}.customer-qr-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.customer-qr-url{word-break:break-all;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;padding:11px 12px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.03)}.customer-qr-preview{display:flex;justify-content:center;padding:12px}.customer-qr-poster{width:min(100%,520px);margin:auto;background:#fff;color:#15120f;border-radius:26px;padding:34px;text-align:center;box-shadow:0 18px 50px rgba(0,0,0,.16)}.customer-qr-poster .poster-brand{font-size:15px;letter-spacing:.14em;text-transform:uppercase;font-weight:800;color:#8b6630}.customer-qr-poster h2{font-size:34px;line-height:1.08;margin:14px 0 12px;color:#17130f}.customer-qr-poster p{font-size:18px;line-height:1.45;color:#574a3f;margin:0 auto 22px;max-width:410px}.customer-qr-code{width:344px;height:344px;max-width:100%;margin:0 auto 20px;padding:12px;background:#fff;border:1px solid #eee4d9;border-radius:20px;display:flex;align-items:center;justify-content:center}.customer-qr-code canvas,.customer-qr-code img{max-width:100%;height:auto!important}.customer-qr-poster .poster-hint{font-size:14px;color:#75675b}.customer-qr-poster .poster-url{font-size:12px;word-break:break-all;color:#8b7b6e;margin-top:8px}.customer-qr-status{min-height:22px;margin-top:8px;font-size:13px}.customer-qr-status.ok{color:#4f8c5b}.customer-qr-status.bad{color:#c55}.customer-qr-note{margin-top:12px;font-size:13px;color:var(--muted)}
@media(max-width:900px){.customer-qr-grid{grid-template-columns:1fr}.customer-qr-poster{padding:26px 18px}.customer-qr-poster h2{font-size:29px}.customer-qr-code{width:300px;height:300px}}
@media print{body *{visibility:hidden!important}.customer-qr-poster,.customer-qr-poster *{visibility:visible!important}.customer-qr-poster{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:148mm;min-height:190mm;box-sizing:border-box;border-radius:0;box-shadow:none;padding:18mm 14mm;display:flex;flex-direction:column;align-items:center;justify-content:center}.customer-qr-poster h2{font-size:30pt}.customer-qr-poster p{font-size:15pt}.customer-qr-code{width:82mm;height:82mm;padding:4mm}.customer-qr-poster .poster-hint{font-size:12pt}.customer-qr-poster .poster-url{font-size:8pt}}
</style>

<div class="card"><div class="chart-head"><div><h2>QR-код клиентского меню</h2><p>Постоянный QR ведёт прямо в PWA. Его можно поставить на стойке, столах, витрине или напечатать на наклейках.</p></div><a class="btn ghost" href="customer/" target="_blank" rel="noopener">Открыть PWA ↗</a></div></div>

<div class="customer-qr-grid section">
  <div class="card">
    <div class="chart-head"><div><h2>Настройка</h2><p>Если PWA работает на этом же домене, поле ссылки можно оставить пустым — Kapouch подставит адрес автоматически.</p></div></div>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="save">
      <label style="grid-column:1/-1">Ссылка, зашитая в QR-код
        <input type="url" name="customer_qr_target_url" value="<?=e($configuredUrl)?>" placeholder="<?=e(customer_qr_default_url())?>">
        <small class="muted">Только HTTPS. Пустое поле = текущий домен + /customer/.</small>
      </label>
      <label>Заголовок плаката<input name="customer_qr_title" maxlength="100" value="<?=e($title)?>"></label>
      <label>Текст под заголовком<textarea name="customer_qr_text" maxlength="240" rows="3"><?=e($text)?></textarea></label>
      <div style="grid-column:1/-1"><button class="btn primary">Сохранить QR-код</button></div>
    </form>
    <div class="customer-qr-note"><strong>Важно:</strong> это отдельная публичная ссылка только для QR. Она не меняет адрес API и не влияет на кнопки принятия заказа на Эвоторе.</div>
  </div>

  <div class="card">
    <div class="chart-head"><div><h2>Текущий адрес</h2><p>Именно эта ссылка сейчас закодирована.</p></div></div>
    <div class="customer-qr-url" id="customer-qr-url"><?=e($targetUrl)?></div>
    <div class="customer-qr-actions">
      <a class="btn ghost" href="<?=e($targetUrl)?>" target="_blank" rel="noopener">Проверить ссылку ↗</a>
      <button class="btn ghost" type="button" id="customer-qr-copy">Копировать ссылку</button>
      <button class="btn ghost" type="button" id="customer-qr-download">Скачать QR PNG</button>
      <button class="btn primary" type="button" id="customer-qr-print">Печать плаката</button>
    </div>
    <div class="customer-qr-status" id="customer-qr-status" aria-live="polite"></div>
  </div>
</div>

<div class="card section">
  <div class="chart-head"><div><h2>Печатный макет</h2><p>Предпросмотр A5. При печати интерфейс Kapouch скрывается автоматически.</p></div></div>
  <div class="customer-qr-preview">
    <div class="customer-qr-poster" id="customer-qr-poster">
      <div class="poster-brand"><?=e($coffeeName)?></div>
      <h2><?=e($title)?></h2>
      <p><?=e($text)?></p>
      <div class="customer-qr-code" id="customer-qr-code"><span id="customer-qr-loading">Формируем QR…</span></div>
      <div class="poster-hint">Наведи камеру телефона и открой меню</div>
      <div class="poster-url"><?=e($targetUrl)?></div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function(){
  'use strict';
  var target=<?=$urlJson?:"''"?>;
  var box=document.getElementById('customer-qr-code');
  var status=document.getElementById('customer-qr-status');
  function say(text,ok){status.textContent=text||'';status.className='customer-qr-status '+(ok===true?'ok':ok===false?'bad':'');}
  function render(){
    if(typeof QRCode==='undefined'){box.innerHTML='<span>Не удалось загрузить модуль QR. Обновите страницу при наличии интернета.</span>';say('Модуль QR не загрузился.',false);return;}
    box.innerHTML='';
    try{new QRCode(box,{text:target,width:320,height:320,colorDark:'#111111',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.H});}
    catch(e){box.innerHTML='<span>Не удалось сформировать QR-код.</span>';say('Ссылка слишком длинная или некорректная.',false);}
  }
  function copyFallback(){var input=document.createElement('textarea');input.value=target;input.setAttribute('readonly','');input.style.position='fixed';input.style.opacity='0';document.body.appendChild(input);input.select();var ok=false;try{ok=document.execCommand('copy');}catch(e){}document.body.removeChild(input);say(ok?'Ссылка скопирована.':'Не удалось скопировать ссылку.',ok);}
  document.getElementById('customer-qr-copy').addEventListener('click',function(){if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(target).then(function(){say('Ссылка скопирована.',true);},copyFallback);}else copyFallback();});
  document.getElementById('customer-qr-download').addEventListener('click',function(){var canvas=box.querySelector('canvas');if(!canvas){say('QR-код ещё не готов.',false);return;}try{var link=document.createElement('a');link.download='kapouch-customer-qr.png';link.href=canvas.toDataURL('image/png');document.body.appendChild(link);link.click();document.body.removeChild(link);say('PNG подготовлен.',true);}catch(e){say('Не удалось скачать PNG.',false);}});
  document.getElementById('customer-qr-print').addEventListener('click',function(){window.print();});
  render();
})();
</script>
<?php page_footer(); ?>
