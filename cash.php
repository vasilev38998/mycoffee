<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/evotor.php';
require_once __DIR__.'/inc/cash_register.php';
ensure_cash_register_tables();

$from=$_GET['from']??date('Y-m-01');
$to=$_GET['to']??date('Y-m-d');
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $connections=db()->query('SELECT * FROM evotor_connections WHERE enabled=1 ORDER BY id')->fetchAll();
        $count=0;foreach($connections as $connection)$count+=sync_evotor_cash_register($connection);
        flash('success','Касса синхронизирована. Обработано документов: '.$count);
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('cash.php?from='.urlencode($from).'&to='.urlencode($to));
}

$summary=cash_period_summary($from,$to);
$current=current_cash_balance();
$sessions=cash_session_reports($from,$to);
$stmt=db()->prepare("SELECT * FROM cash_register_documents WHERE occurred_at>=? AND occurred_at<DATE_ADD(?,INTERVAL 1 DAY) AND (cash_delta<>0 OR document_type IN ('X_REPORT','Z_REPORT','OPEN_SESSION','CLOSE_SESSION')) ORDER BY occurred_at DESC,id DESC LIMIT 300");
$stmt->execute([$from.' 00:00:00',$to]);$movements=$stmt->fetchAll();
$lastSync=db()->query('SELECT MAX(last_cash_sync_ms) FROM evotor_connections WHERE enabled=1')->fetchColumn();

function cash_type_label(string $type,array $row=[]): string{
    return match($type){
        'SELL'=>'Наличная продажа','PAYBACK'=>'Возврат наличными','CASH_INCOME'=>'Внесение','CASH_OUTCOME'=>(($row['payment_category_id']??null)==1?'Инкассация':'Изъятие'),'Z_REPORT'=>'Z-отчёт','X_REPORT'=>'X-отчёт','OPEN_SESSION'=>'Открытие смены','CLOSE_SESSION'=>'Закрытие смены',default=>$type};
}
page_header('Касса');
?>
<form class="card filter-card" method="get"><label>Период с<input type="date" name="from" value="<?=e($from)?>"></label><label>по<input type="date" name="to" value="<?=e($to)?>"></label><button class="btn primary">Обновить</button></form>

<div class="grid section">
<div class="card metric"><div class="label">Сейчас в кассе</div><div class="value"><?=money((float)$current['balance'])?></div><div class="meta"><?php if(($current['shift_open']??null)===true):?>Только текущая открытая смена: внесения + наличные продажи − возвраты − изъятия<?php elseif(($current['shift_open']??null)===false):?>Смена закрыта — остаток новой смене не переносится<?php else:?>Резервный расчёт по доступной истории<?php endif;?></div></div>
<div class="card metric"><div class="label">Наличные продажи</div><div class="value"><?=money($summary['cash_sales'])?></div><div class="meta">За выбранный период</div></div>
<div class="card metric"><div class="label">Инкассации</div><div class="value"><?=money($summary['collection'])?></div><div class="meta">CASH_OUTCOME с категорией «Инкассация»</div></div>
<div class="card metric"><div class="label">Чистое движение</div><div class="value"><?=money($summary['net_movement'])?></div><div class="meta">Продажи + внесения − возвраты − изъятия</div></div>
</div>

<div class="alert info section"><strong>Логика смены.</strong> После закрытия смены Kapouch считает кассу равной 0 ₽. После следующего открытия смены учитываются только внесение бариста и движения этой новой смены; остаток предыдущего дня не переносится.</div>

<div class="two-col section"><div class="card cash-movement-card"><div class="chart-head"><div><h2>Движение наличных</h2><p>Автоматически из документов Эвотор</p></div></div><table class="cash-summary-table"><tbody><tr><td>Наличные продажи</td><td><strong>+ <?=money($summary['cash_sales'])?></strong></td></tr><tr><td>Внесения</td><td><strong>+ <?=money($summary['cash_income'])?></strong></td></tr><tr><td>Возвраты наличными</td><td><strong>− <?=money($summary['cash_returns'])?></strong></td></tr><tr><td>Все изъятия</td><td><strong>− <?=money($summary['cash_outcome'])?></strong></td></tr><tr><td>В том числе инкассации</td><td><?=money($summary['collection'])?></td></tr></tbody></table></div><div class="card"><div class="chart-head"><div><h2>Синхронизация</h2><p>Данные кассы читаются напрямую из API Эвотор</p></div></div><div class="insight-card"><div class="kicker">Последняя синхронизация кассы</div><strong><?=$lastSync?e(date('d.m.Y H:i',(int)$lastSync/1000)):'Ещё не запускалась'?></strong><p>При первом запуске Kapouch загрузит доступную историю кассовых документов.</p></div><form method="post" class="section"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="from" value="<?=e($from)?>"><input type="hidden" name="to" value="<?=e($to)?>"><button class="btn primary">Синхронизировать кассу</button></form></div></div>

<div class="card table-card section"><div class="chart-head"><div><h2>Закрытые смены</h2><p>Контрольные данные Z-отчёта Эвотор</p></div></div><table><thead><tr><th>Смена</th><th>Закрыта</th><th>Наличные в кассе</th><th>Наличные продажи</th><th>Внесения</th><th>Изъятия</th><th>Инкассация Z</th></tr></thead><tbody><?php foreach($sessions as $s):?><tr><td>#<?=e((string)($s['session_number']??'—'))?></td><td><?=e(date('d.m.Y H:i',strtotime($s['occurred_at'])))?></td><td><strong><?=money((float)($s['report_cash']??0))?></strong></td><td><?=money((float)$s['cash_sales'])?></td><td><?=money((float)$s['cash_income'])?></td><td><?=money((float)$s['cash_outcome'])?></td><td><?=money((float)($s['report_collection']??0))?></td></tr><?php endforeach;?></tbody></table></div>

<div class="card table-card section"><div class="chart-head"><div><h2>Журнал кассовых операций</h2><p>Продажи, возвраты, внесения, изъятия и кассовые отчёты</p></div></div><table><thead><tr><th>Время</th><th>Операция</th><th>Смена</th><th>Приход</th><th>Расход</th><th>Категория / основание</th><th>Получатель / вноситель</th></tr></thead><tbody><?php foreach($movements as $row):$delta=(float)$row['cash_delta'];?><tr><td><?=e(date('d.m.Y H:i',strtotime($row['occurred_at'])))?></td><td><strong><?=e(cash_type_label($row['document_type'],$row))?></strong></td><td><?=e((string)($row['session_number']??'—'))?></td><td><?=$delta>0?money($delta):'—'?></td><td><?=$delta<0?money(abs($delta)):'—'?></td><td><?=e(trim((string)($row['payment_category_name']??'').' '.(string)($row['description']??'')))?></td><td><?=e((string)($row['counterparty']??''))?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>