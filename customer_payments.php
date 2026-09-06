<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/customer_payments.php';
require_once __DIR__.'/inc/customer_legal.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'payment');
    try{
        if($action==='legal'){
            customer_legal_save([
                'enabled'=>isset($_POST['legal_enabled']),
                'seller_name'=>(string)($_POST['seller_name']??''),
                'inn'=>(string)($_POST['inn']??''),
                'ogrnip'=>(string)($_POST['ogrnip']??''),
                'legal_address'=>(string)($_POST['legal_address']??''),
                'trade_address'=>(string)($_POST['trade_address']??''),
                'bank_name'=>(string)($_POST['bank_name']??''),
                'bik'=>(string)($_POST['bik']??''),
                'settlement_account'=>(string)($_POST['settlement_account']??''),
                'correspondent_account'=>(string)($_POST['correspondent_account']??''),
                'contact_email'=>(string)($_POST['contact_email']??''),
                'contact_phone'=>(string)($_POST['contact_phone']??''),
                'offer_title'=>(string)($_POST['offer_title']??''),
                'offer_version'=>(string)($_POST['offer_version']??''),
                'offer_date'=>(string)($_POST['offer_date']??''),
                'offer_text'=>(string)($_POST['offer_text']??''),
                'extra_terms'=>(string)($_POST['extra_terms']??''),
            ]);
            audit_write('customer_legal_settings','Обновлены реквизиты ИП и публичная оферта клиентского PWA');
            flash('success','Юридическая информация и оферта сохранены.');
            redirect('customer_payments.php#legal');
        }
        $cash=isset($_POST['cash_enabled'])?'1':'0';
        $sbp=isset($_POST['sbp_enabled'])?'1':'0';
        if($cash==='0'&&$sbp==='0')throw new RuntimeException('Оставьте включённым хотя бы один способ оплаты.');
        $vat=(int)($_POST['vat_code']??1);if($vat<1||$vat>12)throw new RuntimeException('Выберите корректный код НДС.');
        $subject=(string)($_POST['payment_subject']??'commodity');if(!in_array($subject,['commodity','service','excise','payment','another'],true))throw new RuntimeException('Выберите корректный предмет расчёта.');
        $mode=(string)($_POST['payment_mode']??'full_payment');if(!in_array($mode,['full_prepayment','full_payment'],true))throw new RuntimeException('«Чеки от ЮKassa» поддерживают только полную предоплату или полный расчёт.');
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
if(!in_array($paymentMode,['full_payment','full_prepayment'],true))$paymentMode='full_payment';
$legal=customer_legal_settings();$legalMissing=customer_legal_missing($legal);$legalReady=$legal['enabled']&&!$legalMissing;
try{$webhook=customer_payment_public_url('api/customer_payment_yookassa_webhook.php');}catch(Throwable $e){$webhook='https://kapouch.store/api/customer_payment_yookassa_webhook.php';}
try{$legalUrl=customer_payment_public_url('customer/legal.html');}catch(Throwable $e){$legalUrl='/customer/legal.html';}
page_header('Оплата в PWA');
?>
<div class="card"><div class="chart-head"><div><h2>Способы оплаты</h2><p>Для покупателя способ называется «Оплата по СБП». Технически платёж и фискальный чек проходят через ЮKassa.</p></div><div class="actions"><a class="btn ghost" href="#legal">Оферта и реквизиты</a><a class="btn ghost" href="customer_refunds.php">Возвраты ЮKassa</a><a class="btn ghost" href="customer_app.php">← Клиентское PWA</a></div></div></div>

<div class="alert info section"><strong>Безопасность:</strong> секретный ключ ЮKassa хранится в Kapouch зашифрованным и после сохранения не показывается. Не отправляйте его в чат и не добавляйте в Git.</div>

<div class="card section" id="legal"><div class="chart-head"><div><h2>Оферта, контакты и реквизиты ИП</h2><p>Эти данные публикуются в клиентском PWA на отдельной странице и используются в публичной оферте. После заполнения можно передать ЮKassa прямую ссылку на документ.</p></div><span class="pill <?=$legalReady?'connected':''?>"><?=$legalReady?'Готово к публикации':'Нужно заполнить'?></span></div>
<?php if($legalMissing):?><div class="alert warning" style="margin:14px 0"><strong>Не заполнено:</strong> <?=e(implode(', ',$legalMissing))?>. До заполнения PWA покажет предупреждение, что юридическая информация готовится.</div><?php else:?><div class="alert success" style="margin:14px 0"><strong>Публичная страница готова:</strong> <a href="<?=e($legalUrl)?>" target="_blank" rel="noopener"><?=e($legalUrl)?> ↗</a></div><?php endif;?>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="legal">
<label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="legal_enabled" value="1" style="width:auto" <?=$legal['enabled']?'checked':''?>> Публиковать правовую информацию в клиентском PWA</label>
<div class="form-grid">
<label>Наименование продавца / ИП<input name="seller_name" maxlength="255" value="<?=e($legal['seller_name'])?>" placeholder="Индивидуальный предприниматель Иванов Иван Иванович"></label>
<label>ИНН ИП<input name="inn" inputmode="numeric" maxlength="12" value="<?=e($legal['inn'])?>" placeholder="12 цифр"></label>
<label>ОГРНИП<input name="ogrnip" inputmode="numeric" maxlength="15" value="<?=e($legal['ogrnip'])?>" placeholder="15 цифр"></label>
<label>Адрес регистрации ИП<textarea name="legal_address" rows="2" maxlength="500" placeholder="Индекс, регион, город, улица, дом"><?=e($legal['legal_address'])?></textarea></label>
<label>Адрес точки самовывоза<textarea name="trade_address" rows="2" maxlength="500" placeholder="Адрес кофейни, где покупатель получает заказ"><?=e($legal['trade_address'])?></textarea></label>
<label>Email для покупателей<input type="email" name="contact_email" value="<?=e($legal['contact_email'])?>" placeholder="info@example.ru"></label>
<label>Телефон для покупателей<input name="contact_phone" maxlength="80" value="<?=e($legal['contact_phone'])?>" placeholder="+7 ..."></label>
</div>
<h3 style="margin:8px 0 0">Банковские реквизиты</h3>
<div class="form-grid">
<label>Банк<input name="bank_name" maxlength="255" value="<?=e($legal['bank_name'])?>" placeholder="Наименование банка"></label>
<label>БИК<input name="bik" inputmode="numeric" maxlength="9" value="<?=e($legal['bik'])?>" placeholder="9 цифр"></label>
<label>Расчётный счёт<input name="settlement_account" inputmode="numeric" maxlength="20" value="<?=e($legal['settlement_account'])?>" placeholder="20 цифр"></label>
<label>Корреспондентский счёт<input name="correspondent_account" inputmode="numeric" maxlength="20" value="<?=e($legal['correspondent_account'])?>" placeholder="20 цифр"></label>
</div>
<h3 style="margin:8px 0 0">Публичная оферта</h3>
<div class="form-grid">
<label>Название документа<input name="offer_title" maxlength="255" value="<?=e($legal['offer_title'])?>"></label>
<label>Версия<input name="offer_version" maxlength="40" value="<?=e($legal['offer_version'])?>" placeholder="1.0"></label>
<label>Дата начала действия<input type="date" name="offer_date" value="<?=e($legal['offer_date']!==''?$legal['offer_date']:date('Y-m-d'))?>"></label>
</div>
<label>Собственный текст оферты — необязательно<textarea name="offer_text" rows="12" maxlength="40000" placeholder="Оставьте пустым: Kapouch автоматически сформирует базовую оферту из реквизитов и правил клиентского заказа."><?=e($legal['offer_text'])?></textarea><small class="muted">Если поле пустое, Kapouch генерирует оферту автоматически. Если вставить свой текст, он полностью заменит автоматический шаблон.</small></label>
<label>Дополнительные условия для автоматической оферты — необязательно<textarea name="extra_terms" rows="4" maxlength="10000" placeholder="Например, особенности выдачи заказов или дополнительные контакты."><?=e($legal['extra_terms'])?></textarea></label>
<div class="alert warning"><strong>Шаблон — техническая основа, а не юридическая консультация.</strong> Перед коммерческим запуском проверьте текст оферты, реквизиты, порядок возврата и налоговые формулировки с вашим бухгалтером или юристом.</div>
<div class="actions"><button class="btn primary">Сохранить оферту и реквизиты</button><a class="btn ghost" href="<?=e($legalUrl)?>" target="_blank" rel="noopener">Открыть публичную страницу ↗</a></div>
</form></div>

<form method="post" class="section"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="payment">
<div class="two-col">
  <div class="card"><div class="chart-head"><div><h2>Наличными при самовывозе</h2><p>Заказ сразу попадает бариста со статусом «Не оплачено».</p></div><span class="pill <?=$cashEnabled?'connected':''?>"><?=$cashEnabled?'Включено':'Выключено'?></span></div><label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="cash_enabled" value="1" style="width:auto" <?=$cashEnabled?'checked':''?>> Разрешить оплату наличными при получении</label></div>
  <div class="card"><div class="chart-head"><div><h2>Оплата по СБП · ЮKassa</h2><p>Неоплаченный заказ скрыт из очереди бариста. После подтверждения ЮKassa он автоматически становится новым.</p></div><span class="pill <?=$sbpEnabled&&$hasSecret?'connected':''?>"><?=$sbpEnabled&&$hasSecret?'Подключено':($sbpEnabled?'Нужны реквизиты':'Выключено')?></span></div><label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="sbp_enabled" value="1" style="width:auto" <?=$sbpEnabled?'checked':''?>> Показывать покупателям «Оплата по СБП»</label><?php if(!$legalReady):?><p class="muted" style="margin-top:8px">Перед передачей сайта на проверку ЮKassa заполните блок «Оферта, контакты и реквизиты ИП» выше.</p><?php endif;?></div>
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
<?php foreach(['full_payment'=>'Полный расчёт','full_prepayment'=>'Полная предоплата'] as $value=>$label): ?><option value="<?=e($value)?>" <?=$paymentMode===$value?'selected':''?>><?=e($label)?></option><?php endforeach; ?>
</select></label>
</div>
<div class="alert warning" style="margin-top:14px"><strong>Налоговые параметры нужно сверить с бухгалтерией.</strong> Kapouch не пытается определять ставку НДС или признак расчёта автоматически.</div>
<div class="alert info" style="margin-top:14px"><strong>Система налогообложения (СНО):</strong> для «Чеков от ЮKassa» в текущем API Kapouch не передаёт <code>tax_system_code</code>. СНО должна быть правильно указана при подключении/настройке магазина в ЮKassa. Если у бизнеса одновременно несколько СНО, это нужно отдельно согласовать с ЮKassa до запуска.</div>
<div class="alert info" style="margin-top:14px"><strong>Email покупателя обязателен для оплаты по СБП.</strong> Покупатель сохраняет его в профиле Kapouch. Без корректного email создание онлайн-платежа блокируется до появления заказа.</div>
<div class="alert warning" style="margin-top:14px"><strong>Маркированные товары:</strong> текущий контур рассчитан на обычные позиции кофейни. Если через Kapouch будут продаваться товары с обязательной маркировкой, потребуется отдельная передача кода маркировки и связанных реквизитов чека.</div>
</div>

<div class="card section"><h2>Webhook ЮKassa</h2><p class="muted">Добавьте этот HTTPS-адрес в настройках входящих уведомлений ЮKassa для событий <code>payment.succeeded</code>, <code>payment.canceled</code> и <code>refund.succeeded</code>:</p><div class="token-box" style="margin-top:10px;letter-spacing:0;word-break:break-all"><?=e($webhook)?></div><p class="muted">Kapouch не доверяет статусу из тела webhook: после уведомления он повторно запрашивает платёж или возврат у API ЮKassa и сверяет сумму.</p></div>

<div class="card section"><div class="actions"><button class="btn primary">Сохранить оплату</button><a class="btn ghost" href="customer_refunds.php">Открыть возвраты</a><a class="btn ghost" href="customer/" target="_blank">Открыть PWA ↗</a></div></div>
</form>
<?php page_footer();?>