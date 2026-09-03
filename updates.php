<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/updater.php';

$manualResult=null;$manualError=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $manualResult=kapouch_apply_pending_migrations(db(),true);
        flash('success',$manualResult['applied']?'Обновление выполнено. Применено миграций: '.count($manualResult['applied']).'.':'База данных уже актуальна.');
    }catch(Throwable $e){flash('danger','Обновление остановлено: '.$e->getMessage());}
    redirect('updates.php');
}

$status=kapouch_migration_status(db());
$history=kapouch_update_history(db(),50);
$bootApplied=$GLOBALS['kapouch_update_result']['applied']??[];
$bootError=$GLOBALS['kapouch_update_error']??null;
page_header('Обновления');
?>
<div class="grid">
<div class="card metric"><div class="label">Версия приложения</div><div class="value" style="font-size:22px"><?=e(KAPOUCH_APP_VERSION)?></div><div class="meta">Текущая версия файлов Kapouch</div></div>
<div class="card metric"><div class="label">Версия базы</div><div class="value"><?=$status['current_version']?></div><div class="meta">Последняя применённая миграция</div></div>
<div class="card metric"><div class="label">Доступная версия БД</div><div class="value"><?=$status['available_version']?></div><div class="meta">По файлам в database/migrations</div></div>
<div class="card metric"><div class="label">Ожидают применения</div><div class="value"><?=count($status['pending'])?></div><div class="meta"><?=$status['pending']?'Есть изменения структуры БД':'Система актуальна'?></div></div>
</div>

<?php if($bootError):?><div class="alert danger section"><strong>Автоматическое обновление не завершено.</strong><br><?=e($bootError)?><br><span class="muted">Данные не помечены как обновлённые. Исправь причину и нажми «Повторить обновление».</span></div><?php endif;?>
<?php if($status['changed']):?><div class="alert danger section"><strong>Защитная блокировка.</strong> Уже применённый SQL-файл был изменён: <?=e($status['changed'][0]['name'])?>. Kapouch не будет запускать его повторно, чтобы не повредить данные.</div><?php endif;?>
<?php if($bootApplied):?><div class="alert success section"><strong>Kapouch обновил базу автоматически.</strong> Применено миграций: <?=count($bootApplied)?>.</div><?php endif;?>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Состояние системы</h2><p>После загрузки новых файлов Kapouch автоматически применяет только отсутствующие миграции.</p></div><span class="pill <?=$status['pending']||$bootError?'':'connected'?>"><?=$status['pending']||$bootError?'Требует внимания':'Актуально'?></span></div>
<div class="alerts"><div class="alert-item good"><span class="alert-dot"></span><div><strong>Существующие данные сохраняются</strong><p>Продажи, расходы, склад, настройки, токены и пользователи не удаляются при обновлении.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Каждая миграция выполняется один раз</strong><p>Kapouch хранит имя файла, номер версии, checksum, дату и время выполнения.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Изменённые старые миграции блокируются</strong><p>Если уже применённый SQL-файл поменялся, обновление остановится вместо повторного запуска.</p></div></div></div>
<form method="post" class="section"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><button class="btn primary"><?=$bootError||$status['pending']?'Повторить обновление':'Проверить обновления'?></button></form></div>
<div class="card"><div class="chart-head"><div><h2>Как обновлять Kapouch</h2><p>Переустановка больше не нужна</p></div></div><div class="insight-card"><div class="kicker">1. GitHub</div><strong>Merge PR</strong><p>Новая версия попадает в main.</p></div><div class="insight-card section"><div class="kicker">2. Beget</div><strong>Обновить файлы</strong><p>Загрузи новую версию поверх существующих файлов, не трогая config.php.</p></div><div class="insight-card section"><div class="kicker">3. Открыть Kapouch</div><strong>Готово</strong><p>При первом запросе новые миграции выполнятся автоматически. Результат можно проверить на этой странице.</p></div></div>
</div>

<?php if($status['pending']):?><div class="card table-card section"><div class="chart-head"><div><h2>Ожидающие миграции</h2><p>Будут выполнены строго по порядку</p></div></div><table><thead><tr><th>Версия</th><th>Файл</th></tr></thead><tbody><?php foreach($status['pending'] as $m):?><tr><td><?=$m['number']?></td><td><code><?=e($m['name'])?></code></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>

<div class="card table-card section"><div class="chart-head"><div><h2>История базы данных</h2><p>Реестр применённых миграций</p></div></div><table><thead><tr><th>Версия</th><th>Миграция</th><th>Статус</th><th>Применена</th><th>Время</th><th>Версия приложения</th></tr></thead><tbody><?php foreach($history as $row):?><tr><td><?=$row['migration_number']?></td><td><code><?=e($row['migration'])?></code></td><td><span class="pill <?=$row['status']==='failed'?'': 'connected'?>"><?=e($row['status']==='baseline'?'Базовая':($row['status']==='applied'?'Применена':'Ошибка'))?></span></td><td><?=e($row['applied_at']?date('d.m.Y H:i',strtotime($row['applied_at'])):'—')?></td><td><?=$row['execution_ms']?> мс</td><td><?=e((string)($row['app_version']??'—'))?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>