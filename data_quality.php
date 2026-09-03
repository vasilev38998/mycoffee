<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/data_quality.php';
require __DIR__.'/inc/layout.php';
$checks=data_quality_checks();$summary=data_quality_summary();
page_header('Качество данных');
?>
<div class="grid">
<div class="card metric"><div class="label">Критичные проверки</div><div class="value"><?=$summary['critical']?></div><div class="meta">Требуют первоочередного внимания</div></div>
<div class="card metric"><div class="label">Предупреждения</div><div class="value"><?=$summary['warning']?></div><div class="meta">Могут искажать аналитику</div></div>
<div class="card metric"><div class="label">Всего проблемных записей</div><div class="value"><?=$summary['total_issues']?></div><div class="meta">Сумма обнаруженных отклонений</div></div>
<div class="card metric"><div class="label">Успешные проверки</div><div class="value"><?=$summary['ok']?></div><div class="meta">Без обнаруженных проблем</div></div>
</div>
<div class="card section"><div class="chart-head"><div><h2>Автоматические проверки</h2><p>Kapouch проверяет данные, влияющие на себестоимость, склад, прибыль и управленческие отчёты.</p></div></div><div class="alerts"><?php foreach($checks as $c):$kind=$c['severity']==='critical'?'bad':($c['severity']==='warning'?'warn':($c['severity']==='ok'?'good':''));?><div class="alert-item <?=$kind?>"><span class="alert-dot"></span><div><strong><?=e($c['title'])?> · <?=$c['count']?></strong><p><?=e($c['message'])?></p><p><b>Что сделать:</b> <?=e($c['action'])?></p></div></div><?php endforeach;?></div></div>
<div class="alert warning section"><strong>Важно.</strong> Этот экран не исправляет данные автоматически. Он специально отделяет обнаружение проблемы от изменения базы, чтобы никакие хозяйственные данные не корректировались без решения пользователя.</div>
<?php page_footer(); ?>