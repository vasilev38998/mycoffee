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

function dashboard_delta(float $current,float $previous): array {
    if(abs($previous)<0.00001) return [null,'neutral'];
    $value=(($current-$previous)/abs($previous))*100;
    return [$value,$value>=0?'up':'down'];
}
function delta_html(float $current,float $previous,bool $positiveIsGood=true): string {
    [$value,$direction]=dashboard_delta($current,$previous);
    if($value===null) return '<span class="delta neutral">нет базы</span>';
    $good=$positiveIsGood ? $value>=0 : $value<=0;
    $class=$good?'up':'down';
    $arrow=$value>=0?'↑':'↓';
    return '<span class="delta '.$class.'">'.$arrow.' '.number_format(abs($value),1,',',' ').'%</span>';
}

$dailyStmt=db()->prepare("SELECT DATE(sold_at) d, COALESCE(SUM(total_amount),0) revenue, COUNT(*) checks FROM sales WHERE sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY DATE(sold_at) ORDER BY d");
$dailyStmt->execute([$from.' 00:00:00',$to]);
$dailyRaw=[]; foreach($dailyStmt as $r){$dailyRaw[$r['d']]=$r;}
$daily=[];
$cursor=$startDate;
while($cursor<=$endDate){$key=$cursor->format('Y-m-d');$daily[]=['date'=>$key,'label'=>$cursor->format('d.m'),'revenue'=>(float)($dailyRaw[$key]['revenue']??0),'checks'=>(int)($dailyRaw[$key]['checks']??0)];$cursor=$cursor->modify('+1 day');}

$hourStmt=db()->prepare("SELECT HOUR(sold_at) h,COALESCE(SUM(total_amount),0) revenue,COUNT(*) checks FROM sales WHERE sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY HOUR(sold_at) ORDER BY h");
$hourStmt->execute([$from.' 00:00:00',$to]);
$hours=array_fill(0,24,['revenue'=>0.0,'checks'=>0]); foreach($hourStmt as $r){$hours[(int)$r['h']]=['revenue'=>(float)$r['revenue'],'checks'=>(int)$r['checks']];}
$maxHour=max(array_map(fn($x)=>(float)$x['revenue'],$hours)) ?: 1;
$peakHour=0; foreach($hours as $h=>$v){if($v['revenue']>$hours[$peakHour]['revenue'])$peakHour=$h;}

$paymentStmt=db()->prepare("SELECT payment_method,COALESCE(SUM(total_amount),0) total FROM sales WHERE sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY payment_method");
$paymentStmt->execute([$from.' 00:00:00',$to]);
$payments=['card'=>0.0,'cash'=>0.0,'other'=>0.0]; foreach($paymentStmt as $r){$payments[$r['payment_method']]=(float)$r['total'];}
$paymentTotal=array_sum($payments); $cardShare=$paymentTotal>0?max(0,min(100,$payments['card']/$paymentTotal*100)):0;

$top=db()->prepare("SELECT p.name,SUM(si.quantity) qty,SUM(si.quantity*si.unit_price) revenue,SUM(si.quantity*si.unit_cost) cost,SUM(si.quantity*(si.unit_price-si.unit_cost)) profit FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY p.id,p.name ORDER BY profit DESC LIMIT 8");
$top->execute([$from.' 00:00:00',$to]);
$topRows=$top->fetchAll();
$bestProduct=$topRows[0]??null;

$foodCost=$m['revenue']>0?$m['cogs']/$m['revenue']*100:0;
$expenseLoad=$m['revenue']>0?$m['expenses']/$m['revenue']*100:0;
$grossMargin=$m['revenue']>0?$m['gross_profit']/$m['revenue']*100:0;
$prevFoodCost=$prev['revenue']>0?$prev['cogs']/$prev['revenue']*100:0;

$chartW=900;$chartH=220;$padX=24;$padY=20;$maxRevenue=max(array_column($daily,'revenue'))?:1;$points=[];
$count=count($daily);
foreach($daily as $i=>$row){$x=$count>1?$padX+$i*(($chartW-$padX*2)/($count-1)):$chartW/2;$y=$chartH-$padY-($row['revenue']/$maxRevenue)*($chartH-$padY*2);$points[]=round($x,1).','.round($y,1);}
$linePoints=implode(' ',$points);
$areaPoints=$padX.','.($chartH-$padY).' '.$linePoints.' '.($chartW-$padX).','.($chartH-$padY);

page_header('Дашборд');
?>
<form class="card filter-card" method="get"><label>Период с<input type="date" name="from" value="<?=e($from)?>"></label><label>по<input type="date" name="to" value="<?=e($to)?>"></label><div class="muted">Сравнение: <?=e($prevFrom->format('d.m.Y'))?>–<?=e($prevTo->format('d.m.Y'))?></div><button class="btn primary">Обновить аналитику</button></form>

<div class="grid section">
<div class="card metric"><div class="label">Выручка</div><div class="value"><?=money($m['revenue'])?></div><div class="meta"><?=delta_html($m['revenue'],$prev['revenue'])?> к предыдущему периоду</div></div>
<div class="card metric"><div class="label">Операционная прибыль</div><div class="value"><?=money($m['operating_profit'])?></div><div class="meta"><?=delta_html($m['operating_profit'],$prev['operating_profit'])?> · маржа <?=number_format($m['margin'],1,',',' ')?>%</div></div>
<div class="card metric"><div class="label">Средний чек</div><div class="value"><?=money($m['avg_check'])?></div><div class="meta"><?=delta_html($m['avg_check'],$prev['avg_check'])?> · <?=$m['checks']?> чеков</div></div>
<div class="card metric"><div class="label">Food cost</div><div class="value"><?=number_format($foodCost,1,',',' ')?>%</div><div class="meta"><?=delta_html($foodCost,$prevFoodCost,false)?> · <?=money($m['cogs'])?> себестоимость</div></div>
</div>

<div class="dashboard-hero section">
<div class="card chart-card"><div class="chart-head"><div><h2>Динамика выручки</h2><p>Продажи по дням за выбранный период</p></div><strong><?=money($m['revenue'])?></strong></div><svg class="svg-chart" viewBox="0 0 <?=$chartW?> <?=$chartH?>" preserveAspectRatio="none"><defs><linearGradient id="areaGradient" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#b77b49" stop-opacity=".28"/><stop offset="100%" stop-color="#b77b49" stop-opacity="0"/></linearGradient></defs><?php for($g=1;$g<=3;$g++):$gy=$padY+$g*(($chartH-$padY*2)/4);?><line class="chart-grid-line" x1="<?=$padX?>" y1="<?=$gy?>" x2="<?=$chartW-$padX?>" y2="<?=$gy?>"/><?php endfor;?><polygon class="chart-area" points="<?=$areaPoints?>"/><polyline class="chart-line" points="<?=$linePoints?>"/><?php foreach($points as $i=>$point):[$x,$y]=explode(',',$point);?><circle class="chart-dot" cx="<?=$x?>" cy="<?=$y?>" r="3.5"/><?php endforeach;?><?php $step=max(1,(int)ceil($count/7));foreach($daily as $i=>$row):if($i%$step!==0&&$i!==$count-1)continue;$x=$count>1?$padX+$i*(($chartW-$padX*2)/($count-1)):$chartW/2;?><text class="chart-label" x="<?=$x?>" y="<?=$chartH-2?>" text-anchor="middle"><?=e($row['label'])?></text><?php endforeach;?></svg></div>
<div class="card chart-card"><div class="chart-head"><div><h2>Способы оплаты</h2><p>Структура выручки</p></div></div><div class="donut-wrap"><div class="donut" style="--card-share:<?=number_format($cardShare,2,'.','')?>%"><div class="donut-center"><strong><?=number_format($cardShare,0,',',' ')?>%</strong><span>безнал</span></div></div></div><div class="legend"><div class="legend-row"><span>Карта</span><strong><?=money($payments['card'])?></strong></div><div class="legend-row cash"><span>Наличные / другое</span><strong><?=money($payments['cash']+$payments['other'])?></strong></div></div></div>
</div>

<div class="two-col section">
<div class="card chart-card"><div class="chart-head"><div><h2>Продажи по часам</h2><p>Помогает оценивать загрузку смены и ФОТ</p></div><strong><?=sprintf('%02d:00–%02d:00',$peakHour,($peakHour+1)%24)?></strong></div><div class="bars"><?php foreach($hours as $h=>$v):$height=max(2,(float)$v['revenue']/$maxHour*185);?><div class="bar-col" title="<?=sprintf('%02d:00 — %s',$h,money((float)$v['revenue']))?>"><div class="bar" style="height:<?=$height?>px"></div><span><?=$h%2===0?sprintf('%02d',$h):''?></span></div><?php endforeach;?></div></div>
<div class="card"><div class="chart-head"><div><h2>Экономика периода</h2><p>Ключевые доли от выручки</p></div></div><div class="insight-grid" style="grid-template-columns:1fr"><div class="insight-card"><div class="kicker">Валовая маржа</div><strong><?=number_format($grossMargin,1,',',' ')?>%</strong><p><?=money($m['gross_profit'])?> остаётся после себестоимости товара.</p></div><div class="insight-card"><div class="kicker">Расходная нагрузка</div><strong><?=number_format($expenseLoad,1,',',' ')?>%</strong><p>Ручные <?=money($m['manual_expenses'])?> · автоматические <?=money($m['automatic_expenses'])?>.</p></div></div></div>
</div>

<div class="insight-grid section"><div class="insight-card"><div class="kicker">Пиковый час</div><strong><?=sprintf('%02d:00–%02d:00',$peakHour,($peakHour+1)%24)?></strong><p>Выручка в этот час: <?=money((float)$hours[$peakHour]['revenue'])?> за выбранный период.</p></div><div class="insight-card"><div class="kicker">Лучший товар</div><strong><?=e($bestProduct['name']??'Нет данных')?></strong><p><?=$bestProduct?'Валовая прибыль '.money((float)$bestProduct['profit']).' при выручке '.money((float)$bestProduct['revenue']).'.':'Синхронизируйте продажи, чтобы увидеть лидера.'?></p></div><div class="insight-card"><div class="kicker">Чеков за период</div><strong><?=number_format($m['checks'],0,',',' ')?></strong><p>В среднем <?=number_format($m['checks']/$periodDays,1,',',' ')?> чеков в календарный день выбранного периода.</p></div></div>

<div class="card table-card section"><div class="chart-head"><div><h2>Самые прибыльные позиции</h2><p>Рейтинг по валовой прибыли, а не только по выручке</p></div></div><table><thead><tr><th>#</th><th>Позиция</th><th>Продано</th><th>Выручка</th><th>Себестоимость</th><th>Валовая прибыль</th><th>Маржа</th></tr></thead><tbody><?php foreach($topRows as $i=>$row):$rowMargin=(float)$row['revenue']>0?(float)$row['profit']/(float)$row['revenue']*100:0;?><tr><td><?=($i+1)?></td><td><strong><?=e($row['name'])?></strong></td><td><?=number_format((float)$row['qty'],2,',',' ')?></td><td><?=money((float)$row['revenue'])?></td><td><?=money((float)$row['cost'])?></td><td><?=money((float)$row['profit'])?></td><td><?=number_format($rowMargin,1,',',' ')?>%</td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>