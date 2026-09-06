<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/customer_operations.php';

$user=current_user();$canManage=in_array($user['role']??'',['owner','manager'],true);
if(!$canManage){http_response_code(403);exit('Недостаточно прав.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'save');
        if($action==='pause'){
            set_app_setting('customer_orders_accepting','0');
            set_app_setting('customer_orders_pause_reason',mb_substr(trim((string)($_POST['pause_reason']??'Приём заказов временно приостановлен.')),0,255));
            audit_write('customer_orders_paused','Приём заказов клиентского PWA приостановлен');
            flash('warning','Приём новых заказов приостановлен. Уже созданные заказы не затронуты.');
        }elseif($action==='resume'){
            set_app_setting('customer_orders_accepting','1');
            set_app_setting('customer_orders_pause_reason','');
            audit_write('customer_orders_resumed','Приём заказов клиентского PWA возобновлён');
            flash('success','Приём заказов возобновлён.');
        }else{
            customer_operations_save($_POST);
            audit_write('customer_operations_updated','Обновлены часы, слоты и лимиты клиентских заказов');
            flash('success','Настройки приёма заказов сохранены.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_operations.php');
}

require __DIR__.'/inc/layout.php';
$settings=customer_operations_settings();$metrics=customer_operations_metrics();$state=customer_operations_public_state();
$days=['1'=>'Понедельник','2'=>'Вторник','3'=>'Среда','4'=>'Четверг','5'=>'Пятница','6'=>'Суббота','7'=>'Воскресенье'];
page_header('Приём заказов PWA');
?>
<div class="card"><div class="chart-head"><div><h2>Приём заказов</h2><p>Рабочее время, фактические слоты самовывоза и ограничение загрузки бариста.</p></div><span class="pill <?=$settings['accepting']?'connected':''?>"><?=$settings['accepting']?'Приём включён':'Пауза'?></span></div>
<div class="four-col section" style="margin-top:14px"><div class="metric-card"><span>Активные заказы</span><strong><?=(int)$metrics['active']?></strong></div><div class="metric-card"><span>Заказов сегодня</span><strong><?=(int)$metrics['today']?></strong></div><div class="metric-card"><span>Просрочено к обещанному времени</span><strong><?=(int)$metrics['overdue']?></strong></div><div class="metric-card"><span>Ждут онлайн-оплату</span><strong><?=(int)$metrics['awaiting_payment']?></strong></div></div>
<?php if($settings['accepting']):?>
<form method="post" class="stack section" style="margin-top:14px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="pause"><label>Причина паузы для покупателей<input name="pause_reason" maxlength="255" value="<?=e($settings['pause_reason'])?>" placeholder="Например: высокая загрузка, вернёмся через 20 минут"></label><div><button class="btn danger">Поставить приём заказов на паузу</button></div></form>
<?php else:?>
<div class="alert warning" style="margin-top:14px"><strong>Покупатели сейчас не могут создать новый заказ.</strong> <?=e($settings['pause_reason']?:'Приём заказов временно приостановлен.')?></div><form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="resume"><button class="btn primary">Возобновить приём заказов</button></form>
<?php endif;?>
</div>

<div class="three-col section">
<div class="card"><div class="kicker">Ближайший свободный слот</div><h2 style="margin-top:8px"><?=e((string)($metrics['next_slot']['label']??'Нет доступных'))?></h2><p class="muted"><?=$metrics['next_slot']?'Свободно мест: '.(int)$metrics['next_slot']['remaining']:e($metrics['message']?:'Нет доступных интервалов.')?></p></div>
<div class="card"><div class="kicker">Минимум на приготовление</div><h2 style="margin-top:8px"><?=(int)$settings['prep_minutes']?> мин</h2><p class="muted">Раньше этого времени слот покупателю не предлагается.</p></div>
<div class="card"><div class="kicker">Вместимость слота</div><h2 style="margin-top:8px"><?=(int)$settings['slot_capacity']?> заказов</h2><p class="muted">После заполнения слот автоматически исчезает из клиентского PWA.</p></div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Расписание и нагрузка</h2><p>Изменения применяются сразу. Уже оформленные заказы сохраняют обещанное время.</p></div><a class="btn ghost" href="customer/" target="_blank" rel="noopener">Открыть PWA ↗</a></div>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save"><input type="hidden" name="accepting" value="<?=$settings['accepting']?'1':''?>"><input type="hidden" name="pause_reason" value="<?=e($settings['pause_reason'])?>">
<label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="schedule_enabled" value="1" style="width:auto" <?=$settings['schedule_enabled']?'checked':''?>> Ограничивать приём заказов рабочими часами</label>
<div class="form-grid">
<label>Минимальное время приготовления, мин<input type="number" name="prep_minutes" min="5" max="180" value="<?=(int)$settings['prep_minutes']?>"></label>
<label>Шаг временных слотов, мин<select name="slot_interval"><?php foreach([5,10,15,20,30,60] as $v):?><option value="<?=$v?>" <?=$settings['slot_interval']===$v?'selected':''?>><?=$v?> минут</option><?php endforeach;?></select></label>
<label>Максимум заказов в одном слоте<input type="number" name="slot_capacity" min="1" max="100" value="<?=(int)$settings['slot_capacity']?>"></label>
<label>Не принимать за N минут до закрытия<input type="number" name="last_order_minutes" min="0" max="180" value="<?=(int)$settings['last_order_minutes']?>"></label>
<label>На сколько часов вперёд показывать слоты<input type="number" name="horizon_hours" min="1" max="48" value="<?=(int)$settings['horizon_hours']?>"></label>
</div>
<div class="table-wrap"><table><thead><tr><th>День</th><th>Работаем</th><th>Открытие</th><th>Закрытие</th></tr></thead><tbody><?php foreach($days as $day=>$label):$row=$settings['hours'][$day];?><tr><td><strong><?=e($label)?></strong></td><td><label style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="day_<?=$day?>_enabled" value="1" style="width:auto" <?=$row['enabled']?'checked':''?>> Открыто</label></td><td><input type="time" name="day_<?=$day?>_open" value="<?=e($row['open'])?>"></td><td><input type="time" name="day_<?=$day?>_close" value="<?=e($row['close'])?>"></td></tr><?php endforeach;?></tbody></table></div>
<div class="alert info"><strong>Как считается загрузка:</strong> Kapouch учитывает все активные заказы с обещанным временем внутри слота — включая ожидающие оплату, новые, готовящиеся и готовые. Если лимит достигнут, покупатель увидит следующий свободный интервал.</div>
<div><button class="btn primary">Сохранить расписание и лимиты</button></div></form></div>

<div class="card section"><h2>Слоты, которые сейчас видит покупатель</h2><?php if(!$state['slots']):?><p class="muted"><?=e($state['message']?:'Нет доступных интервалов.')?></p><?php else:?><div class="actions"><?php foreach(array_slice($state['slots'],0,20) as $slot):?><span class="pill connected"><?=e($slot['label'])?> · свободно <?=(int)$slot['remaining']?></span><?php endforeach;?></div><?php endif;?></div>
<?php page_footer();?>
