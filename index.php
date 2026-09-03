<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

$from=$_GET['from'] ?? date('Y-m-01');
$to=$_GET['to'] ?? date('Y-m-d');
$m=dashboard_metrics($from,$to);

$startDate=new DateTimeImmutable($from);
$endDate=new DateTimeImmutable($to);
$periodDays=max(1,(int)$startDate->diff($endDate)->days+1);
$prevTo=$startDate->modify('-1 day');
$prevFrom=$prevTo->modify('-'.($periodDays-1).' days');
$prev=dashboard_metrics($prevFrom->format('Y-m-d'),$prevTo->format('Y-m-d'));

function dashboard_delta_value(float $current,float $previous): ?float {
    if(abs($previous)<0.00001) return null;
    return (($current-$previous)/abs($previous))*100;
}
function delta_html(float $current,float $previous,bool $positiveIsGood=true): string {
    $value=dashboard_delta_value($current,$previous);
    if($value===null) return '<span class="delta neutral">нет базы</span>';
    $good=$positiveIsGood ? $value>=0 : $value<=0;
    return '<span class="delta '.($good?'up':'down').'">'.($value>=0?'↑':'↓').' '.number_format(abs($value),1,',',' ').'%</span>';
}

$dailyStmt=db()->prepare("SELECT DATE(sold_at) d,COALESCE(SUM(total_amount),0) revenue,COUNT(*) checks FROM sales WHERE sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY DATE(sold_at) ORDER BY d");
$dailyStmt->execute([$from.' 00:00:00',$to]);
$dailyRaw=[];foreach($dailyStmt as $r){$dailyRaw[$r['d']]=$r;}
$daily=[];$cursor=$startDate;
while($cursor<=$endDate){$key=$cursor->format('Y-m-d');$rev=(float)($dailyRaw[$key]['revenue']??0);$checks=(int)($dailyRaw[$key]['checks']??0);$daily[]=['date'=>$key,'label'=>$cursor->format('d.m'),'revenue'=>$rev,'checks'=>$checks,'avg'=>$checks>0?$rev/$checks:0];$cursor=$cursor->modify('+1 day');}
$maxDaily=max(array_column($daily,'revenue'))?:1;
$maxAvg=max(array_column($daily,'avg'))?:1;

$hourStmt=db()->prepare("SELECT HOUR(sold_at) h,COALESCE(SUM(total_amount),0) revenue,COUNT(*) checks FROM sales WHERE sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY HOUR(sold_at) ORDER BY h");
$hourStmt->execute([$from.' 00:00:00',$to]);
$hours=array_fill(0,24,['revenue'=>0.0,'checks'=>0]);foreach($hourStmt as $r){$hours[(int)$r['h']]=['revenue'=>(float)$r['revenue'],'checks'=>(int)$r['checks']];}
$maxHour=max(array_map(fn($x)=>(float)$x['revenue'],$hours))?:1;
$peakHour=0;foreach($hours as $h=>$v){if($v['revenue']>$hours[$peakHour]['revenue'])$peakHour=$h;}

$weekdayNames=['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
$weekdayStmt=db()->prepare("SELECT WEEKDAY(sold_at) wd,COALESCE(SUM(total_amount),0) revenue,COUNT(DISTINCT DATE(sold_at)) active_days FROM sales WHERE sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY WEEKDAY(sold_at) ORDER BY wd");
$weekdayStmt->execute([$from.' 00:00:00',$to]);
$weekdays=array_fill(0,7,['revenue'=>0.0,'avg_day'=>0.0]);foreach($weekdayStmt as $r){$wd=(int)$r['wd'];$active=max(1,(int)$r['active_days']);$weekdays[$wd]=['revenue'=>(float)$r['revenue'],'avg_day'=>(float)$r['revenue']/$active];}
$maxWeekday=max(array_map(fn($x)=>(float)$x['avg_day'],$weekdays))?:1;
$bestWeekday=0;foreach($weekdays as $i=>$v){if($v['avg_day']>$weekdays[$bestWeekday]['avg_day'])$bestWeekday=$i;}

$paymentStmt=db()->prepare("SELECT payment_method,COALESCE(SUM(total_amount),0) total FROM sales WHERE sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY payment_method");
$paymentStmt->execute([$from.' 00:00:00',$to]);
$payments=['card'=>0.0,'cash'=>0.0,'other'=>0.0];foreach($paymentStmt as $r){$payments[$r['payment_method']]=(float)$r['total'];}
$paymentTotal=array_sum($payments);$cardShare=$paymentTotal>0?max(0,min(100,$payments['card']/$paymentTotal*100)):0;

$top=db()->prepare("SELECT p.name,
    SUM(si.quantity) qty,
    SUM(si.quantity*si.unit_price) revenue,
    SUM(si.quantity * CASE
        WHEN si.unit_cost > 0 THEN si.unit_cost
        ELSE COALESCE((
            SELECT SUM(ri.quantity * (i.purchase_price / NULLIF(i.purchase_quantity,0)))
            FROM recipe_items ri
            JOIN ingredients i ON i.id=ri.ingredient_id
            WHERE ri.product_id=si.product_id
        ),0)
    END) cost,
    SUM(si.quantity * (si.unit_price - CASE
        WHEN si.unit_cost > 0 THEN si.unit_cost
        ELSE COALESCE((
            SELECT SUM(ri.quantity * (i.purchase_price / NULLIF(i.purchase_quantity,0)))
            FROM recipe_items ri
            JOIN ingredients i ON i.id=ri.ingredient_id
            WHERE ri.product_id=si.product_id
        ),0)
    END)) profit
    FROM sale_items si
    JOIN sales s ON s.id=si.sale_id
    JOIN products p ON p.id=si.product_id
    WHERE s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY)
    GROUP BY p.id,p.name
    ORDER BY profit DESC LIMIT 8");
$top->execute([$from.' 00:00:00',$to]);$topRows=$top->fetchAll();$bestProduct=$topRows[0]??null;

$foodCost=$m['revenue']>0?$m['cogs']/$m['revenue']*100:0;
$prevFoodCost=$prev['revenue']>0?$prev['cogs']/$prev['revenue']*100:0;
$grossMargin=$m['revenue']>0?$m['gross_profit']/$m['revenue']*100:0;
$expenseLoad=$m['revenue']>0?$m['expenses']/$m['revenue']*100:0;

$monthStart=date('Y-m-01');$today=date('Y-m-d');$monthMetrics=dashboard_metrics($monthStart,$today);
$elapsed=max(1,(int)date('j'));$daysInMonth=(int)date('t');$monthForecast=$monthMetrics['revenue']/$elapsed*$daysInMonth;

$alerts=[];
$revDelta=dashboard_delta_value($m['revenue'],$prev['revenue']);
$avgDelta=dashboard_delta_value($m['avg_check'],$prev['avg_check']);
if($revDelta!==null && $revDelta<-10)$alerts[]=['bad','Выручка снизилась',number_format(abs($revDelta),1,',',' ').'% к предыдущему сопоставимому периоду.'];
if($avgDelta!==null && $avgDelta<-8)$alerts[]=['warn','Средний чек просел',number_format(abs($avgDelta),1,',',' ').'% к предыдущему периоду.'];
if($foodCost>35)$alerts[]=['warn','Высокий food cost',number_format($foodCost,1,',',' ').'% выручки. Проверь закупочные цены и техкарты.'];
if($expenseLoad>35)$alerts[]=['warn','Высокая нагрузка расходов',number_format($expenseLoad,1,',',' ').'% выручки уходит на операционные расходы.'];
if(!$alerts)$alerts[]=['good','Критичных отклонений не видно','По текущим базовым правилам показатели выглядят стабильно.'];

page_header('Дашборд');
?>
<form class="card filter-card" method="get"><label>Период с<input type="date" name="from" value="<?=e($from)?>"></label><label>по<input type="date" name="to" value="<?=e($to)?>"></label><div class="period-note">Сравнение: <?=e($prevFrom->format('d.m.Y'))?>–<?=e($prevTo->format('d.m.Y'))?></div><button class="btn primary">Обновить</button></form>

<div class="grid section">
<div class="card metric"><div class="label">Выручка</div><div class="value"><?=money($m['revenue'])?></div><div class="meta"><?=delta_html($m['revenue'],$prev['revenue'])?> к предыдущему периоду</div></div>
<div class="card metric"><div class="label">Операционная прибыль</div><div class="value"><?=money($m['operating_profit'])?></div><div class="meta"><?=delta_html($m['operating_profit'],$prev['operating_profit'])?> · маржа <?=number_format($m['margin'],1,',',' ')?>%</div></div>
<div class="card metric"><div class="label">Средний чек</div><div class="value"><?=money($m['avg_check'])?></div><div class="meta"><?=delta_html($m['avg_check'],$prev['avg_check'])?> · <?=number_format($m['checks'],0,',',' ')?> чеков</div></div>
<div class="card metric"><div class="label">Food cost</div><div class="value"><?=number_format($foodCost,1,',',' ')?>%</div><div class="meta"><?=delta_html($foodCost,$prevFoodCost,false)?> · <?=money($m['cogs'])?></div></div>
</div>

<div class="dashboard-grid section">
<div class="card"><div class="chart-head"><div><h2>Выручка по дням</h2><p>Без SVG — стабильный CSS-график для Beget и старых браузеров</p></div><div class="chart-summary"><?=money($m['revenue'])?></div></div><div class="css-chart"><?php foreach($daily as $i=>$row):$h=max(3,$row['revenue']/$maxDaily*185);$show=($i%max(1,(int)ceil(count($daily)/7))===0)||$i===count($daily)-1;?><div class="chart-column" title="<?=e($row['label'])?> · <?=money($row['revenue'])?>"><div class="chart-bar" style="height:<?=$h?>px"></div><label><?=$show?e($row['label']):''?></label></div><?php endforeach;?></div></div>
<div class="card"><div class="chart-head"><div><h2>Оплаты</h2><p>Доля безнала</p></div></div><div class="donut-shell"><div class="donut" style="--card-share:<?=number_format($cardShare,2,'.','')?>%"><div class="donut-center"><strong><?=number_format($cardShare,0,',',' ')?>%</strong><span>карта</span></div></div></div><div class="legend"><div class="legend-row"><span>Карта</span><strong><?=money($payments['card'])?></strong></div><div class="legend-row cash"><span>Наличные / другое</span><strong><?=money($payments['cash']+$payments['other'])?></strong></div></div></div>
</div>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Продажи по часам</h2><p>Показывает загрузку смены</p></div><div class="chart-summary"><?=sprintf('%02d:00–%02d:00',$peakHour,($peakHour+1)%24)?></div></div><div class="css-chart hour-chart"><?php foreach($hours as $h=>$v):$height=max(3,(float)$v['revenue']/$maxHour*185);?><div class="chart-column" title="<?=sprintf('%02d:00 · %s',$h,money((float)$v['revenue']))?>"><div class="chart-bar" style="height:<?=$height?>px"></div><label><?=$h%2===0?sprintf('%02d',$h):''?></label></div><?php endforeach;?></div></div>
<div class="card forecast-card"><div class="kicker">Прогноз текущего месяца</div><strong><?=money($monthForecast)?></strong><p>Прогноз по среднему темпу выручки за <?=date('j')?> из <?=$daysInMonth?> дней. Уже получено <?=money($monthMetrics['revenue'])?>.</p><div class="section"><div class="kicker">Лучший день недели</div><strong><?=$weekdayNames[$bestWeekday]?></strong><p>Средняя выручка такого дня: <?=money((float)$weekdays[$bestWeekday]['avg_day'])?>.</p></div></div>
</div>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Сравнение дней недели</h2><p>Средняя выручка одного Пн/Вт/Ср и т.д. в выбранном периоде</p></div></div><div class="css-chart weekday-chart"><?php foreach($weekdays as $i=>$v):$height=max(3,(float)$v['avg_day']/$maxWeekday*165);?><div class="chart-column"><div class="chart-bar" style="height:<?=$height?>px"></div><label><?=$weekdayNames[$i]?></label><small><?=money((float)$v['avg_day'])?></small></div><?php endforeach;?></div></div>
<div class="card"><div class="chart-head"><div><h2>Динамика среднего чека</h2><p>По дням выбранного периода</p></div></div><div class="css-chart avg-chart"><?php foreach($daily as $i=>$row):$height=max(3,$row['avg']/$maxAvg*165);$show=($i%max(1,(int)ceil(count($daily)/7))===0)||$i===count($daily)-1;?><div class="chart-column" title="<?=e($row['label'])?> · <?=money($row['avg'])?>"><div class="chart-bar" style="height:<?=$height?>px"></div><label><?=$show?e($row['label']):''?></label></div><?php endforeach;?></div></div>
</div>

<div class="three-col section">
<div class="insight-card"><div class="kicker">Валовая маржа</div><strong><?=number_format($grossMargin,1,',',' ')?>%</strong><p><?=money($m['gross_profit'])?> остаётся после себестоимости.</p></div>
<div class="insight-card"><div class="kicker">Расходная нагрузка</div><strong><?=number_format($expenseLoad,1,',',' ')?>%</strong><p>Ручные <?=money($m['manual_expenses'])?> · автоматические <?=money($m['automatic_expenses'])?>.</p></div>
<div class="insight-card"><div class="kicker">Лидер меню</div><strong><?=e($bestProduct['name']??'Нет данных')?></strong><p><?=$bestProduct?'Валовая прибыль '.money((float)$bestProduct['profit']).'.':'После синхронизации появится лидер по прибыли.'?></p></div>
</div>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Контроль отклонений</h2><p>Автоматические сигналы для владельца</p></div></div><div class="alerts"><?php foreach($alerts as [$type,$title,$text]):?><div class="alert-item <?=e($type)?>"><span class="alert-dot"></span><div><strong><?=e($title)?></strong><p><?=e($text)?></p></div></div><?php endforeach;?></div></div>
<div class="card"><div class="chart-head"><div><h2>Коротко о периоде</h2><p>Что важно заметить первым</p></div></div><div class="alerts"><div class="alert-item good"><span class="alert-dot"></span><div><strong>Пиковый час: <?=sprintf('%02d:00–%02d:00',$peakHour,($peakHour+1)%24)?></strong><p><?=money((float)$hours[$peakHour]['revenue'])?> выручки за выбранный период.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>В среднем <?=number_format($m['checks']/$periodDays,1,',',' ')?> чеков в день</strong><p>Всего <?=number_format($m['checks'],0,',',' ')?> чеков за <?=$periodDays?> календарных дней.</p></div></div></div></div>
</div>

<div class="card table-card section"><div class="chart-head"><div><h2>Самые прибыльные позиции</h2><p>Рейтинг по валовой прибыли</p></div></div><table><thead><tr><th>#</th><th>Позиция</th><th>Продано</th><th>Выручка</th><th>Себестоимость</th><th>Валовая прибыль</th><th>Маржа</th></tr></thead><tbody><?php foreach($topRows as $i=>$row):$rowMargin=(float)$row['revenue']>0?(float)$row['profit']/(float)$row['revenue']*100:0;?><tr><td><?=($i+1)?></td><td><strong><?=e($row['name'])?></strong></td><td><?=number_format((float)$row['qty'],2,',',' ')?></td><td><?=money((float)$row['revenue'])?></td><td><?=money((float)$row['cost'])?></td><td><?=money((float)$row['profit'])?></td><td><?=number_format($rowMargin,1,',',' ')?>%</td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>