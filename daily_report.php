<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/reporting.php';
require_once __DIR__.'/inc/notifications.php';

$date=$_GET['date']??date('Y-m-d',strtotime('-1 day'));
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $date=$_POST['date']??$date;
    try{send_telegram_message(daily_owner_report_text($date));flash('success','Отчёт отправлен в Telegram.');}
    catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('daily_report.php?date='.urlencode($date));
}
$r=daily_owner_report($date);$m=$r['metrics'];
page_header('Отчёт владельца');
?>
<form class="card filter-card" method="get"><label>Дата<input type="date" name="date" value="<?=e($date)?>"></label><button class="btn primary">Показать отчёт</button></form>
<div class="grid section"><div class="card metric"><div class="label">Выручка</div><div class="value"><?=money($m['revenue'])?></div><div class="meta"><?=$m['checks']?> чеков</div></div><div class="card metric"><div class="label">Средний чек</div><div class="value"><?=money($m['avg_check'])?></div><div class="meta">за выбранный день</div></div><div class="card metric"><div class="label">Операционная прибыль</div><div class="value"><?=money($m['operating_profit'])?></div><div class="meta">маржа <?=number_format($m['margin'],1,',',' ')?>%</div></div><div class="card metric"><div class="label">Food cost</div><div class="value"><?=number_format($r['food_cost'],1,',',' ')?>%</div><div class="meta"><?=money($m['cogs'])?> себестоимость</div></div></div>
<div class="two-col section"><div class="card"><div class="chart-head"><div><h2>Что важно сегодня</h2><p>Автоматическая сводка для владельца</p></div></div><div class="alerts"><?php if($r['alerts']):foreach($r['alerts'] as $a):?><div class="alert-item warn"><span class="alert-dot"></span><div><strong><?=e($a)?></strong></div></div><?php endforeach;else:?><div class="alert-item good"><span class="alert-dot"></span><div><strong>Критичных отклонений не обнаружено</strong><p>Базовые показатели находятся в пределах заданных нормативов.</p></div></div><?php endif;?></div></div><div class="card"><div class="chart-head"><div><h2>Ключевые факты</h2><p>Быстрый взгляд на день</p></div></div><table><tbody><tr><td>Валовая прибыль</td><td><strong><?=money($m['gross_profit'])?></strong></td></tr><tr><td>Расходы</td><td><?=money($m['expenses'])?></td></tr><tr><td>Расходная нагрузка</td><td><?=number_format($r['expense_load'],1,',',' ')?>%</td></tr><tr><td>Лидер меню</td><td><strong><?=e($r['top']['name']??'—')?></strong></td></tr><tr><td>Пиковый час</td><td><?=isset($r['peak']['h'])?sprintf('%02d:00–%02d:00',(int)$r['peak']['h'],((int)$r['peak']['h']+1)%24):'—'?></td></tr></tbody></table></div></div>
<div class="card section"><div class="chart-head"><div><h2>Готовый текст отчёта</h2><p>Его можно отправить владельцу или управляющему</p></div></div><textarea rows="12" readonly><?=e(daily_owner_report_text($date))?></textarea><div class="actions section"><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="date" value="<?=e($date)?>"><button class="btn primary">Отправить в Telegram</button></form><a class="btn ghost" href="settings.php">Настроить Telegram</a></div></div>
<?php page_footer(); ?>