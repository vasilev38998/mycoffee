<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/receipt_import.php';

function pc_local_endpoint(): string
{
    $forwarded=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??'');
    $scheme=$forwarded!==''?$forwarded:((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http');
    if(!in_array($scheme,['http','https'],true))$scheme='https';
    $host=preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??''));
    if($host==='')throw new RuntimeException('Не удалось определить адрес Kapouch.');
    return $scheme.'://'.$host.'/receipt_proverkacheka_proxy.php';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $token=trim((string)($_POST['token']??''));
        $current=receipt_connection();
        if($token===''&&(!$current||($current['name']??'')!=='ПроверкаЧека.com'))throw new RuntimeException('Вставьте API-токен ПроверкаЧека.com.');
        receipt_connection_save('ПроверкаЧека.com',pc_local_endpoint(),$token);
        audit_write('receipt_provider_updated','Настроен источник ПроверкаЧека.com');
        flash('success','ПроверкаЧека.com подключён. Теперь QR-чек можно получать автоматически.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('receipt_proverkacheka.php');
}

$connection=receipt_connection();
$connected=$connection&&($connection['name']??'')==='ПроверкаЧека.com'&&!empty($connection['token_ciphertext']);
page_header('ПроверкаЧека.com');
?>
<div class="card"><div class="chart-head"><div><h2>ПроверкаЧека.com</h2><p>Штатный источник электронных чеков для автоматического прихода закупок по QR.</p></div><a class="btn ghost" href="receipt_import.php">← QR-чек закупки</a></div></div>
<div class="section two-col">
  <div class="card"><div class="chart-head"><div><h2>Подключение</h2><p>В личном кабинете ПроверкаЧека.com создай API-токен и вставь его сюда. Kapouch хранит токен зашифрованным.</p></div><span class="pill <?=$connected?'connected':''?>"><?=$connected?'Подключено':'Не настроено'?></span></div>
    <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>API-токен<input type="password" name="token" autocomplete="new-password" placeholder="<?=$connected?'Оставь пустым, чтобы не менять':'Вставь токен из ПроверкаЧека.com'?>"></label><div><button class="btn primary"><?=$connected?'Сохранить / заменить':'Подключить'?></button></div></form>
  </div>
  <div class="card"><h2>Как будет работать</h2><p class="muted">После подключения ничего дополнительно вводить не нужно:</p><div class="stack"><div><strong>1.</strong> Сканируешь QR кассового чека.</div><div><strong>2.</strong> Kapouch передаёт ФН, ФД, ФП, дату, сумму и тип чека в API.</div><div><strong>3.</strong> Получает полный список товаров и создаёт черновик закупки.</div><div><strong>4.</strong> Убираешь личные позиции и подтверждаешь приход на склад.</div></div></div>
</div>
<div class="alert info section"><strong>Токен не показывается повторно.</strong> Он хранится в той же зашифрованной форме, что и другие интеграционные секреты Kapouch. Не вставляй токен в чат — вводи его только на этой странице.</div>
<div class="card section"><div class="chart-head"><div><h2>Проверка после подключения</h2><p>Открой мастер QR-чека и отсканируй реальный кассовый чек. При успешном ответе сразу появятся его позиции.</p></div><a class="btn primary" href="receipt_import.php">Сканировать QR-чек →</a></div></div>
<?php page_footer();?>
