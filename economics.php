<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/economics.php';
require __DIR__.'/inc/layout.php';

$to=$_GET['to']??date('Y-m-d');$from=$_GET['from']??date('Y-m-d',strtotime($to.' -29 days'));
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=date('Y-m-d',strtotime('-29 days'));
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=date('Y-m-d');
if($from>$to)[$from,$to]=[$to,$from];

$cmp=economics_period_comparison($from,$to);$m=$cmp['current'];$be=economics_break_even($from,$to);$inv=economics_inventory_kpis($from,$to);$menu=economics_menu_abc_xyz($from,$to);$cats=economics_category_profitability($from,$to);$cash=economics_cash_reserve($from,$to);$forecast=economics_month_forecast();
function econ_delta(?float $v): string{return $v===null?'—':(($v>=0?'+':'').number_format($v,1,',',' ').'%');}
function econ_class(?float $v,bool $positiveGood=true): string{if($v===null)return 'neutral';$good=$positiveGood?$v>=0:$v<=0;return $good?'up':'down';}
page_header('Экономика');
?>
<div class="card filter-card"><form method="get" style="display:contents"><label>С<input type="date" name="from" value="<?=e($from)?>"></label><label>По<input type="date" name="to" value="<?=e($to)?>"></label><div class="period-note">Сравнение: <?=date('d.m.Y',strtotime($cmp['previous_from']))?>–<?=date('d.m.Y',strtotime($cmp['previous_to']))?></div><button class="btn primary">Применить</button></form></div>

<div class="grid section">
<div class="card metric"><div class="label">Выручка</div><div class="value"><?=money($m['revenue'])?></div><div class="meta"><span class="delta <?=econ_class($cmp['revenue_change'])?>"><?=econ_delta($cmp['revenue_change'])?></span> к предыдущему периоду</div></div>
<div class="card metric"><div class="label">Операционная прибыль</div><div class="value"><?=money($m['operating_profit'])?></div><div class="meta">Маржа <?=number_format($m['margin'],1,',',' ')?>%</div></div>
<div class="card metric"><div class="label">Средний чек</div><div class="value"><?=money($m['avg_check'])?></div><div class="meta"><span class="delta <?=econ_class($cmp['avg_check_change'])?>"><?=econ_delta($cmp['avg_check_change'])?></span> к предыдущему периоду</div></div>
<div class="card metric"><div class="label">Запас прочности</div><div class="value"><?=number_format($be['safety_margin'],1,',',' ')?>%</div><div class="meta">Выше точки безубыточности</div></div>
</div>

<div class="grid section">
<div class="card metric"><div class="label">Точка безубыточности / день</div><div class="value"><?=money($be['break_even_revenue_day'])?></div><div class="meta"><?=number_format($be['break_even_checks_day'],1,',',' ')?> чеков в день</div></div>
<div class="card metric"><div class="label">Contribution margin</div><div class="value"><?=number_format($be['contribution_margin'],1,',',' ')?>%</div><div class="meta"><?=money($be['contribution'])?> после COGS и % расходов</div></div>
<div class="card metric"><div class="label">Стоимость текущего склада</div><div class="value"><?=money($inv['stock_value'])?></div><div class="meta"><?=$inv['days_on_hand']===null?'Нет расхода для оценки':number_format($inv['days_on_hand'],1,',',' ').' дней запаса'?></div></div>
<div class="card metric"><div class="label">Запас наличности</div><div class="value"><?=$cash['reserve_days']===null?'—':number_format($cash['reserve_days'],1,',',' ')?></div><div class="meta">дней расчётных расходов · касса <?=money($cash['cash'])?></div></div>
</div>

<div class="three-col section">
<div class="insight-card"><div class="kicker">Чеки</div><strong><?=number_format($m['checks'],0,',',' ')?></strong><p><?=econ_delta($cmp['checks_change'])?> к предыдущему периоду. Рост выручки без роста чеков означает, что драйвером стал средний чек.</p></div>
<div class="insight-card"><div class="kicker">Списания</div><strong><?=money($inv['writeoff_value'])?></strong><p><?=number_format($inv['writeoff_to_cogs'],1,',',' ')?>% от себестоимости продаж за выбранный период.</p></div>
<div class="insight-card"><div class="kicker">Оборачиваемость</div><strong><?=$inv['turnover_period']===null?'—':number_format($inv['turnover_period'],2,',',' ').'×'?></strong><p>COGS периода к текущей стоимости запасов. Чем выше при стабильной доступности товара, тем меньше денег заморожено на складе.</p></div>
</div>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Безубыточность</h2><p>Управленческая модель на основе фактической себестоимости и расходов</p></div></div>
<div class="alerts"><div class="alert-item <?=$m['revenue']>=$be['break_even_revenue']?'good':'bad'?>"><span class="alert-dot"></span><div><strong><?=$m['revenue']>=$be['break_even_revenue']?'Период выше точки безубыточности':'Выручка ниже точки безубыточности'?></strong><p>Факт <?=money($m['revenue'])?> · точка <?=money($be['break_even_revenue'])?>.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Фиксированные и условно-постоянные расходы</strong><p><?=money($be['fixed_costs'])?>. Ручные расходы, фиксированные месячные и начисления за смену считаются условно-постоянными.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Переменные расходы</strong><p><?=money($be['variable_costs'])?>. Здесь учитываются автоматические проценты от выручки и безналичной выручки.</p></div></div></div>
</div>
<div class="card"><div class="chart-head"><div><h2>Прогноз месяца</h2><p>Линейный run-rate по факту текущего месяца</p></div></div><div class="insight-card"><div class="kicker">Прогноз выручки</div><strong><?=money($forecast['revenue'])?></strong><p><?=$forecast['revenue_goal_pct']===null?'Цель выручки не задана':number_format($forecast['revenue_goal_pct'],0,',',' ').'% от месячной цели'?></p></div><div class="insight-card section"><div class="kicker">Прогноз прибыли</div><strong><?=money($forecast['profit'])?></strong><p><?=$forecast['profit_goal_pct']===null?'Цель прибыли не задана':number_format($forecast['profit_goal_pct'],0,',',' ').'% от месячной цели'?></p></div></div>
</div>

<div class="card table-card section"><div class="chart-head"><div><h2>ABC/XYZ и маржа меню</h2><p>A — основные генераторы валовой прибыли; X — наиболее стабильный спрос</p></div></div><table><thead><tr><th>Позиция</th><th>ABC/XYZ</th><th>Класс меню</th><th>Продано</th><th>Выручка</th><th>Валовая прибыль</th><th>Маржа / ед.</th><th>Food cost</th></tr></thead><tbody><?php foreach(array_slice($menu,0,50) as $r):?><tr><td><strong><?=e($r['name'])?></strong></td><td><span class="pill connected"><?=e($r['abc_xyz'])?></span></td><td><?=e($r['class'])?></td><td><?=number_format($r['qty'],0,',',' ')?></td><td><?=money($r['revenue'])?></td><td><?=money($r['gross_profit'])?></td><td><?=money($r['contribution'])?></td><td><?=number_format($r['food_cost'],1,',',' ')?>%</td></tr><?php endforeach;?></tbody></table></div>

<div class="card table-card section"><div class="chart-head"><div><h2>Прибыльность категорий</h2><p>Показывает, какие группы меню создают выручку и валовую прибыль</p></div></div><table><thead><tr><th>Категория</th><th>Продано</th><th>Выручка</th><th>Себестоимость</th><th>Валовая прибыль</th><th>Валовая маржа</th></tr></thead><tbody><?php foreach($cats as $r):?><tr><td><strong><?=e($r['category'])?></strong></td><td><?=number_format((float)$r['qty'],0,',',' ')?></td><td><?=money($r['revenue'])?></td><td><?=money($r['cogs'])?></td><td><?=money($r['gross_profit'])?></td><td><?=number_format($r['margin'],1,',',' ')?>%</td></tr><?php endforeach;?></tbody></table></div>

<div class="alert warning section"><strong>Как читать показатели.</strong> Точка безубыточности — управленческая оценка: ручные расходы, месячные фиксированные расходы и расходы за смену считаются условно-постоянными; процентные авторасходы — переменными. «Запас наличности» учитывает только наличные в кассе Эвотор, а не деньги на банковских счетах.</div>
<?php page_footer(); ?>