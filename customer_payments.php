<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/customer_payments.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $cash=isset($_POST['cash_enabled'])?'1':'0';
        $sbp=isset($_POST['sbp_enabled'])?'1':'0';
        if($cash==='0'&&$sbp==='0')throw new RuntimeException('Оставьте включённым хотя бы один способ оплаты.');
        set_app_setting('customer_payment_cash_enabled',$cash);
        set_app_setting('customer_payment_sbp_enabled',$sbp);
        customer_payment_save_sber([
            'enabled'=>$sbp==='1',
            'test_mode'=>isset($_POST['test_mode']),
            'merchant_login'=>(string)($_POST['merchant_login']??''),
            'password'=>(string)($_POST['password']??''),
            'api_base_url'=>(string)($_POST['api_base_url']??''),
        ]);
        audit_write('customer_payment_settings','Обновлены способы оплаты клиентского PWA');
        flash('success','Настройки оплаты сохранены.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_payments.php');
}

$connection=customer_payment_connection('sber_sbp');
$cashEnabled=app_setting('customer_payment_cash_enabled','1')==='1';
$sbpEnabled=app_setting('customer_payment_sbp_enabled','0')==='1';
$hasSecret=!empty($connection['secret_ciphertext']);
$testMode=$connection?((int)$connection['test_mode']===1):true;
try{$callback=customer_payment_public_url('api/customer_payment_sber_callback.php');}catch(Throwable $e){$callback='https://kapouch.store/api/customer_payment_sber_callback.php';}
page_header('Оплата в PWA');
?>
<div class="card"><div class="chart-head"><div><h2>Способы оплаты</h2><p>Наличные и СБП включаются независимо. Клиент увидит только доступные способы.</p></div><a class="btn ghost" href="customer_app.php">← Клиентское PWA</a></div></div>

<div class="alert info section"><strong>Безопасность:</strong> пароль платёжного шлюза хранится зашифрованным и после сохранения не показывается. Не отправляйте его в чат.</div>

<form method="post" class="section"><input type="hidden" name="csrf" value="<?=csrf_token()?>">
<div class="two-col">
  <div class="card"><div class="chart-head"><div><h2>Наличными при самовывозе</h2><p>Заказ сразу попадает бариста со статусом «Не оплачено».</p></div><span class="pill <?=$cashEnabled?'connected':''?>"><?=$cashEnabled?'Включено':'Выключено'?></span></div><label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="cash_enabled" value="1" style="width:auto" <?=$cashEnabled?'checked':''?>> Разрешить оплату наличными при получении</label></div>
  <div class="card"><div class="chart-head"><div><h2>Онлайн по СБП · Сбер</h2><p>Неоплаченный СБП-заказ не попадает в рабочую очередь. После подтверждения оплаты автоматически становится новым.</p></div><span class="pill <?=$sbpEnabled&&$hasSecret?'connected':''?>"><?=$sbpEnabled&&$hasSecret?'Подключено':($sbpEnabled?'Нужны реквизиты':'Выключено')?></span></div><label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="sbp_enabled" value="1" style="width:auto" <?=$sbpEnabled?'checked':''?>> Показывать клиентам «Онлайн по СБП»</label></div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Подключение Сбера</h2><p>Реквизиты интернет-эквайринга / платёжного шлюза. Обычного доступа к СберБизнесу недостаточно — нужен eCommerce API с СБП.</p></div></div><div class="form-grid">
<label>Логин мерчанта<input name="merchant_login" value="<?=e((string)($connection['merchant_login']??''))?>" autocomplete="off" placeholder="Логин из Сбера"></label>
<label>Пароль API<input type="password" name="password" autocomplete="new-password" placeholder="<?=$hasSecret?'Оставьте пустым, чтобы не менять':'Пароль платёжного шлюза'?>"></label>
<label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="test_mode" value="1" style="width:auto" <?=$testMode?'checked':''?>> Тестовый режим Сбера</label>
<label>API Base URL<input type="url" name="api_base_url" value="<?=e((string)($connection['api_base_url']??''))?>" placeholder="В тестовом режиме можно оставить пустым"></label>
</div><div class="alert warning" style="margin-top:14px"><strong>Боевой режим:</strong> адрес API не угадываем и не зашиваем в код. Вставьте точный Base URL, который будет указан Сбером при подключении вашего мерчанта.</div></div>

<div class="card section"><h2>Callback для Сбера</h2><p class="muted">Kapouch передаёт этот HTTPS-адрес как dynamicCallbackUrl при создании каждого СБП-платежа:</p><div class="token-box" style="margin-top:10px;letter-spacing:0;word-break:break-all"><?=e($callback)?></div><p class="muted">Callback сам по себе не считается доказательством оплаты: Kapouch после него запрашивает статус заказа у Сбера и проверяет сумму и <code>paymentState=DEPOSITED</code>.</p></div>

<div class="card section"><div class="actions"><button class="btn primary">Сохранить оплату</button><a class="btn ghost" href="customer/" target="_blank">Открыть PWA ↗</a></div></div>
</form>
<?php page_footer();?>
