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
        $vat=(int)($_POST['vat_code']??1);if($vat<1||$vat>12)throw new RuntimeException('Выберите корректный код НДС.');
        $subject=(string)($_POST['payment_subject']??'commodity');if(!in_array($subject,['commodity','service','excise','payment','another'],true))throw new RuntimeException('Выберите корректный предмет расчёта.');
        $mode=(string)($_POST['payment_mode']??'full_payment');if(!in_array($mode,['full_prepayment','partial_prepayment','advance','full_payment','partial_payment','credit','credit_payment'],true))throw new RuntimeException('Выберите корректный способ расчёта.');
        set_app_setting('customer_payment_cash_enabled',$cash);
        set_app_setting('customer_payment_sbp_enabled',$sbp);
        set_app_setting('customer_yookassa_vat_code',(string)$vat);
        set_app_setting('customer_yookassa_payment_subject',$subject);
        set_app_setting('customer_yookassa_payment_mode',$mode);
        customer_payment_save_yookassa([
            'enabled'=>$sbp==='1',
            'test_mode'=>isset($_POST['test_mode']),
            'shop_id'=>(string)($_POST['shop_id']??''),
            'secret_key'=>(string)($_POST['secret_key']??''),
        ]);
        audit_write('customer_payment_settings','Обновлены настройки СБП через ЮKassa и «Чеков от ЮKassa»');
        flash('success','Настройки оплаты сохранены.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_payments.php');
}

$connection=customer_payment_connection('yookassa_sbp');
$cashEnabled=app_setting('customer_payment_cash_enabled','1')==='1';
$sbpEnabled=app_setting('customer_payment_sbp_enabled','0')==='1';
$hasSecret=!empty($connection['secret_ciphertext']);
$testMode=$connection?((int)$connection['test_mode']===1):true;
$vatCode=(int)app_setting('customer_yookassa_vat_code','1');
$paymentSubject=(string)app_setting('customer_yookassa_payment_subject','commodity');
$paymentMode=(string)app_setting('customer_yookassa_payment_mode','full_payment');
try{$webhook=customer_payment_public_url('api/customer_payment_yookassa_webhook.php');}catch(Throwable $e){$webhook='https://kapouch.store/api/customer_payment_yookassa_webhook.php';}
page_header('Оплата в PWA');
?>
<div class="card"><div class="chart-head"><div><h2>Способы оплаты</h2><p>Для покупателя способ называется «Оплата по СБП». Технически платёж и фискальный чек проходят через ЮKassa.</p></div><a class="btn ghost" href="customer_app.php">← Клиентское PWA</a></div></div>

<div class="alert info section"><strong>Безопасность:</strong> секретный ключ ЮKassa хранится в Kapouch зашифрованным и после сохранения не показывается. Не отправляйте его в чат и не добавляйте в Git.</div>

<form method="post" class="section"><input type="hidden" name="csrf" value="<?=csrf_token()?>">
<div class="two-col">
  <div class="card"><div class="chart-head"><div><h2>Наличными при самовывозе</h2><p>Заказ сразу попадает бариста со статусом «Не оплачено».</p></div><span class="pill <?=$cashEnabled?'connected':''?>"><?=$cashEnabled?'Включено':'Выключено'?></span></div><label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="cash_enabled" value="1" style="width:auto" <?=$cashEnabled?'checked':''?>> Разрешить оплату наличными при получении</label></div>
  <div class="card"><div class="chart-head"><div><h2>Оплата по СБП · ЮKassa</h2><p>Неоплаченный заказ скрыт из очереди бариста. После подтверждения ЮKassa он автоматически становится новым.</p></div><span class="pill <?=$sbpEnabled&&$hasSecret?'connected':''?>"><?=$sbpEnabled&&$hasSecret?'Подключено':($sbpEnabled?'Нужны реквизиты':'Выключено')?></span></div><label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="sbp_enabled" value="1" style="width:auto" <?=$sbpEnabled?'checked':''?>> Показывать покупателям «Оплата по СБП»</label></div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Подключение ЮKassa</h2><p>Используются серверный API ЮKassa и способ оплаты <code>sbp</code>. Для тестов укажите реквизиты тестового магазина ЮKassa.</p></div></div><div class="form-grid">
<label>shopId<input name="shop_id" value="<?=e((string)($connection['merchant_login']??''))?>" autocomplete="off" placeholder="Идентификатор магазина ЮKassa"></label>
<label>Секретный ключ<input type="password" name="secret_key" autocomplete="new-password" placeholder="<?=$hasSecret?'Оставьте пустым, чтобы не менять':'Секретный ключ ЮKassa'?>"></label>
<label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="test_mode" value="1" style="width:auto" <?=$testMode?'checked':''?>> Используются реквизиты тестового магазина</label>
</div></div>

<div class="card section"><div class="chart-head"><div><h2>Фискализация · «Чеки от ЮKassa»</h2><p>При создании платежа Kapouch передаёт email покупателя и реальный состав заказа в объекте <code>receipt</code>. ЮKassa регистрирует чек и отправляет его покупателю по электронной почте.</p></div><span class="pill connected">Встроено в СБП</span></div>
<div class="form-grid">
<label>Код НДС<select name="vat_code">
<?php foreach([1=>'1 · Без НДС',2=>'2 · 0%',3=>'3 · 10%',4=>'4 · 20%',5=>'5 · 10/110',6=>'6 · 20/120',7=>'7 · 5%',8=>'8 · 7%',9=>'9 · 5/105',10=>'10 · 7/107',11=>'11 · 22%',12=>'12 · 22/122'] as $code=>$label): ?><option value="<?=$code?>" <?=$vatCode===$code?'selected':''?>><?=e($label)?></option><?php endforeach; ?>
</select></label>
<label>Предмет расчёта<select name="payment_subject">
<?php foreach(['commodity'=>'Товар','service'=>'Услуга','excise'=>'Подакцизный товар','payment'=>'Платёж','another'=>'Иное'] as $value=>$label): ?><option value="<?=e($value)?>" <?=$paymentSubject===$value?'selected':''?>><?=e($label)?></option><?php endforeach; ?>
</select></label>
<label>Способ расчёта<select name="payment_mode">
<?php foreach(['full_payment'=>'Полный расчёт','full_prepayment'=>'Полная предоплата','partial_prepayment'=>'Частичная предоплата','advance'=>'Аванс','partial_payment'=>'Частичный расчёт','credit'=>'Передача в кредит','credit_payment'=>'Оплата кредита'] as $value=>$label): ?><option value="<?=e($value)?>" <?=$paymentMode===$value?'selected':''?>><?=e($label)?></option><?php endforeach; ?>
</select></label>
</div>
<div class="alert warning" style="margin-top:14px"><strong>Налоговые параметры нужно сверить с бухгалтерией.</strong> Kapouch не пытается определять ставку НДС или признак расчёта автоматически.</div>
<div class="alert info" style="margin-top:14px"><strong>Email покупателя обязателен для оплаты по СБП.</strong> Покупатель сохраняет его в профиле Kapouch. Без корректного email создание онлайн-платежа блокируется до появления заказа.</div>
</div>

<div class="card section"><h2>Webhook ЮKassa</h2><p class="muted">Добавьте этот HTTPS-адрес в настройках входящих уведомлений ЮKassa для события <code>payment.succeeded</code> (также можно включить <code>payment.canceled</code>):</p><div class="token-box" style="margin-top:10px;letter-spacing:0;word-break:break-all"><?=e($webhook)?></div><p class="muted">Kapouch не доверяет статусу из тела webhook: после уведомления он повторно запрашивает платёж у API ЮKassa и сверяет статус и сумму.</p></div>

<div class="card section"><div class="actions"><button class="btn primary">Сохранить оплату</button><a class="btn ghost" href="customer/" target="_blank">Открыть PWA ↗</a></div></div>
</form>
<?php page_footer();?>
