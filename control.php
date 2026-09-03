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
<div class="card metric"><div class="label">Последняя проверка</div><div class="value" style="font-size:20px"><?=e(date('H:i'))?></div><div class="meta">Запусти проверку вручную или через cron</div></div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Активные сигналы</h2><p>Kapouch сравнивает текущие данные с нормативами и исторической базой</p></div><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="run"><button class="btn primary">Запустить проверку</button></form></div><div class="alerts"><?php if(!$alerts):?><div class="alert-item good"><span class="alert-dot"></span><div><strong>Активных проблем нет</strong><p>После следующего cron-прохода Kapouch проверит показатели снова.</p></div></div><?php endif;?><?php foreach($alerts as $a):$cls=$a['severity']==='critical'?'bad':($a['severity']==='warning'?'warn':'');?><div class="alert-item <?=e($cls)?>"><span class="alert-dot"></span><div style="width:100%"><div style="display:flex;justify-content:space-between;gap:12px"><strong><?=e($a['title'])?></strong><span class="pill"><?=e($a['severity']==='critical'?'Критично':'Предупреждение')?></span></div><p><?=e($a['message'])?></p><?php if($a['recommendation']):?><p><strong>Что сделать:</strong> <?=e($a['recommendation'])?></p><?php endif;?><div class="muted" style="font-size:10px;margin-top:7px">Категория: <?=e($a['category'])?> · обнаружено <?=e(date('d.m H:i',strtotime($a['first_seen_at'])))?> · повторов <?=$a['occurrences']?></div><?php if($a['status']==='open'):?><form method="post" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="ack"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn ghost">Я посмотрел</button></form><?php endif;?></div></div><?php endforeach;?></div></div>

<div class="card table-card section"><div class="chart-head"><div><h2>Что контролируется</h2><p>Правила работают детерминированно на данных Kapouch</p></div></div><table><thead><tr><th>Область</th><th>Проверка</th><th>Логика</th></tr></thead><tbody><tr><td>Продажи</td><td>Падение выручки</td><td>Сравнение с тем же днём недели за предыдущие 4 недели</td></tr><tr><td>Продажи</td><td>Средний чек</td><td>Отклонение от 4-недельной базы</td></tr><tr><td>Маржа</td><td>Food cost</td><td>Сравнение с нормативом из настроек</td></tr><tr><td>Касса</td><td>Возвраты / лимит наличных</td><td>Контроль доли возвратов и суммы денег в кассе</td></tr><tr><td>Склад</td><td>Остатки</td><td>Прогноз, на сколько дней хватит ингредиента</td></tr><tr><td>Склад</td><td>Инвентаризация</td><td>Денежная оценка крупных расхождений</td></tr><tr><td>Интеграция</td><td>Эвотор</td><td>Нет синхронизации более 2 часов в рабочее время</td></tr></tbody></table></div>

<div class="card table-card section"><div class="chart-head"><div><h2>История сигналов</h2><p>Последние 50 срабатываний и их статус</p></div></div><table><thead><tr><th>Последний раз</th><th>Сигнал</th><th>Категория</th><th>Уровень</th><th>Статус</th><th>Повторов</th></tr></thead><tbody><?php foreach($history as $a):?><tr><td><?=e(date('d.m.Y H:i',strtotime($a['last_seen_at'])))?></td><td><?=e($a['title'])?></td><td><?=e($a['category'])?></td><td><?=e($a['severity'])?></td><td><?=e($a['status'])?></td><td><?=$a['occurrences']?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>