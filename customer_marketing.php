<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        set_app_setting('customer_scheduled_pickup_enabled',isset($_POST['customer_scheduled_pickup_enabled'])?'1':'0');
        set_app_setting('customer_promo_enabled',isset($_POST['customer_promo_enabled'])?'1':'0');
        set_app_setting('customer_promo_kicker',mb_substr(trim((string)($_POST['customer_promo_kicker']??'')),0,80));
        set_app_setting('customer_promo_title',mb_substr(trim((string)($_POST['customer_promo_title']??'')),0,160));
        set_app_setting('customer_promo_text',mb_substr(trim((string)($_POST['customer_promo_text']??'')),0,500));
        set_app_setting('customer_promo_button',mb_substr(trim((string)($_POST['customer_promo_button']??'')),0,80));
        $target=(string)($_POST['customer_promo_target']??'menu');if(!in_array($target,['menu','profile','cart'],true))$target='menu';set_app_setting('customer_promo_target',$target);
        set_app_setting('customer_personal_offer_enabled',isset($_POST['customer_personal_offer_enabled'])?'1':'0');
        set_app_setting('customer_loyalty_levels_enabled',isset($_POST['customer_loyalty_levels_enabled'])?'1':'0');
        audit_write('customer_marketing_updated','Обновлены акции и персонализация клиентского PWA');
        flash('success','Настройки клиентских акций сохранены.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_marketing.php');
}
$s=[
'scheduled'=>(string)app_setting('customer_scheduled_pickup_enabled','1')==='1',
'promo'=>(string)app_setting('customer_promo_enabled','0')==='1',
'kicker'=>(string)app_setting('customer_promo_kicker','АКЦИЯ'),
'title'=>(string)app_setting('customer_promo_title',''),
'text'=>(string)app_setting('customer_promo_text',''),
'button'=>(string)app_setting('customer_promo_button','Выбрать напиток'),
'target'=>(string)app_setting('customer_promo_target','menu'),
'personal'=>(string)app_setting('customer_personal_offer_enabled','1')==='1',
'levels'=>(string)app_setting('customer_loyalty_levels_enabled','1')==='1'];
page_header('Акции PWA');
?>
<div class="card"><div class="chart-head"><div><h2>Акции и персонализация PWA</h2><p>Управление промо-блоком, предзаказом ко времени и персональными подсказками.</p></div><a class="btn ghost" href="customer_app.php">← Клиентское PWA</a></div></div>
<form method="post" class="card section form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>">
<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="customer_scheduled_pickup_enabled" value="1" style="width:auto" <?=$s['scheduled']?'checked':''?>> Предзаказ ко времени</label>
<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="customer_personal_offer_enabled" value="1" style="width:auto" <?=$s['personal']?'checked':''?>> Персональные предложения</label>
<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="customer_loyalty_levels_enabled" value="1" style="width:auto" <?=$s['levels']?'checked':''?>> Уровни клиента</label>
<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="customer_promo_enabled" value="1" style="width:auto" <?=$s['promo']?'checked':''?>> Показывать промо-баннер</label>
<label>Надпись над акцией<input name="customer_promo_kicker" value="<?=e($s['kicker'])?>" placeholder="АКЦИЯ"></label>
<label>Заголовок акции<input name="customer_promo_title" value="<?=e($s['title'])?>" placeholder="Например: Двойные бонусы до 12:00"></label>
<label style="grid-column:1/-1">Текст акции<textarea name="customer_promo_text" rows="3"><?=e($s['text'])?></textarea></label>
<label>Текст кнопки<input name="customer_promo_button" value="<?=e($s['button'])?>"></label>
<label>Куда ведёт кнопка<select name="customer_promo_target"><option value="menu" <?=$s['target']==='menu'?'selected':''?>>Меню</option><option value="profile" <?=$s['target']==='profile'?'selected':''?>>Профиль</option><option value="cart" <?=$s['target']==='cart'?'selected':''?>>Корзина</option></select></label>
<div><button class="btn primary">Сохранить</button></div></form>
<div class="alert info section"><strong>Персональные предложения</strong> формируются из реальной истории завершённых заказов клиента и его любимого напитка. Уровни клиента также считаются автоматически, без ручного ведения.</div>
<?php page_footer(); ?>
