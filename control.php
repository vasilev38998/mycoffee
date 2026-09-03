<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/control.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=$_POST['action']??'';
    try{
        if($action==='run'){
            $alerts=evaluate_business_control();
            flash('success','Проверка завершена. Активных сигналов: '.count($alerts).'.');
        }elseif($action==='ack'){
            $id=(int)($_POST['id']??0);
            $stmt=db()->prepare("UPDATE control_alerts SET status='acknowledged',acknowledged_at=NOW() WHERE id=? AND status='open'");
            $stmt->execute([$id]);
            flash('success','Сигнал отмечен как просмотренный.');
        }elseif($action==='settings'){
            $fields=['control_revenue_drop_pct','control_avg_check_drop_pct','control_refund_share_pct','control_inventory_variance_value','control_stock_days_warning','control_cash_limit'];
            foreach($fields as $field)set_app_setting($field,(string)max(0,(float)($_POST[$field]??0)));
            set_app_setting('control_telegram_critical',isset($_POST['control_telegram_critical'])?'1':'0');
            flash('success','Пороги контроля сохранены.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('control.php');
}

$summary=control_summary();
$alerts=control_open_alerts();
$history=db()->query("SELECT * FROM control_alerts ORDER BY last_seen_at DESC LIMIT 50")->fetchAll();
page_header('Центр контроля');
?>
<div class="grid">
<div class="card metric"><div class="label">Критичные</div><div class="value"><?=$summary['critical']?></div><div class="meta">Требуют внимания в первую очередь</div></div>
<div class="card metric"><div class="label">Предупреждения</div><div class="value"><?=$summary['warning']?></div><div class="meta">Отклонения от заданных норм</div></div>
<div class="card metric"><div class="label">Всего активных</div><div class="value"><?=$summary['total']?></div><div class="meta">Открытые и просмотренные сигналы</div></div>
<div class="card metric"><div class="label">Автоконтроль</div><div class="value" style="font-size:20px">Готов</div><div class="meta">Для автоматики подключи cron business_control.php</div></div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Активные сигналы</h2><p>Kapouch сравнивает текущие данные с нормативами и исторической базой</p></div><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="run"><button class="btn primary">Запустить проверку</button></form></div><div class="alerts"><?php if(!$alerts):?><div class="alert-item good"><span class="alert-dot"></span><div><strong>Активных проблем нет</strong><p>После следующего cron-прохода Kapouch проверит показатели снова.</p></div></div><?php endif;?><?php foreach($alerts as $a):$cls=$a['severity']==='critical'?'bad':($a['severity']==='warning'?'warn':'');?><div class="alert-item <?=e($cls)?>"><span class="alert-dot"></span><div style="width:100%"><div style="display:flex;justify-content:space-between;gap:12px"><strong><?=e($a['title'])?></strong><span class="pill"><?=e($a['severity']==='critical'?'Критично':'Предупреждение')?></span></div><p><?=e($a['message'])?></p><?php if($a['recommendation']):?><p><strong>Что сделать:</strong> <?=e($a['recommendation'])?></p><?php endif;?><div class="muted" style="font-size:10px;margin-top:7px">Категория: <?=e($a['category'])?> · обнаружено <?=e(date('d.m H:i',strtotime($a['first_seen_at'])))?> · повторов <?=$a['occurrences']?></div><?php if($a['status']==='open'):?><form method="post" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="ack"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn ghost">Я посмотрел</button></form><?php endif;?></div></div><?php endforeach;?></div></div>

<div class="two-col section"><div class="card"><div class="chart-head"><div><h2>Пороги контроля</h2><p>Можно подстроить под реальную экономику кофейни</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="settings"><label>Падение выручки, %<input type="number" min="0" step="1" name="control_revenue_drop_pct" value="<?=e((string)app_setting('control_revenue_drop_pct','15'))?>"></label><label>Падение среднего чека, %<input type="number" min="0" step="1" name="control_avg_check_drop_pct" value="<?=e((string)app_setting('control_avg_check_drop_pct','10'))?>"></label><label>Доля возвратов, %<input type="number" min="0" step="0.1" name="control_refund_share_pct" value="<?=e((string)app_setting('control_refund_share_pct','8'))?>"></label><label>Расхождение склада, ₽<input type="number" min="0" step="100" name="control_inventory_variance_value" value="<?=e((string)app_setting('control_inventory_variance_value','1000'))?>"></label><label>Предупреждать об остатке, дней<input type="number" min="0" step="0.5" name="control_stock_days_warning" value="<?=e((string)app_setting('control_stock_days_warning','3'))?>"></label><label>Лимит наличных в кассе, ₽<input type="number" min="0" step="1000" name="control_cash_limit" value="<?=e((string)app_setting('control_cash_limit','20000'))?>"></label><label style="grid-column:1/-1"><span><input type="checkbox" name="control_telegram_critical" value="1" <?=app_setting('control_telegram_critical','1')==='1'?'checked':''?>> Отправлять критичные сигналы в настроенный Telegram</span></label><div><button class="btn primary">Сохранить пороги</button></div></form></div><div class="card"><div class="chart-head"><div><h2>Что контролируется</h2><p>Детерминированные правила на реальных данных</p></div></div><div class="alerts"><div class="alert-item"><span class="alert-dot"></span><div><strong>Продажи</strong><p>Выручка и средний чек против того же дня недели за 4 недели.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Маржа и касса</strong><p>Food cost, возвраты и превышение лимита наличных.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Склад</strong><p>Дни запаса и крупные расхождения инвентаризаций.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Интеграция</strong><p>Kapouch заметит, если Эвотор не обновляется в рабочее время.</p></div></div></div></div></div>

<div class="card table-card section"><div class="chart-head"><div><h2>История сигналов</h2><p>Последние 50 срабатываний и их статус</p></div></div><table><thead><tr><th>Последний раз</th><th>Сигнал</th><th>Категория</th><th>Уровень</th><th>Статус</th><th>Повторов</th></tr></thead><tbody><?php foreach($history as $a):?><tr><td><?=e(date('d.m.Y H:i',strtotime($a['last_seen_at'])))?></td><td><?=e($a['title'])?></td><td><?=e($a['category'])?></td><td><?=e($a['severity'])?></td><td><?=e($a['status'])?></td><td><?=$a['occurrences']?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>