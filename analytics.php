<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/intelligence.php';

$from=$_GET['from']??date('Y-m-01');
$to=$_GET['to']??date('Y-m-d');
$shifts=shift_analytics($from,$to);
$variance=inventory_variances(40);
$menu=menu_engineering($from,$to);
$forecast=purchase_forecast(14,7);
$totalVariance=array_sum(array_map(fn($r)=>(float)$r['variance_value'],$variance));
$urgent=array_values(array_filter($forecast,fn($r)=>$r['days_left']!==null && $r['days_left']<3));
$stars=count(array_filter($menu,fn($r)=>$r['class']==='Звезда'));
page_header('Операционная аналитика');
?>
<form class="card filter-card" method="get"><label>Период с<input type="date" name="from" value="<?=e($from)?>"></label><label>по<input type="date" name="to" value="<?=e($to)?>"></label><button class="btn primary">Обновить</button></form>
<div class="grid section"><div class="card metric"><div class="label">Смен за период</div><div class="value"><?=count($shifts)?></div><div class="meta">По сессиям Эвотор</div></div><div class="card metric"><div class="label">Потери по инвентаризациям</div><div class="value"><?=money($totalVariance)?></div><div class="meta">Абсолютная стоимость последних расхождений</div></div><div class="card metric"><div class="label">Звёзд меню</div><div class="value"><?=$stars?></div><div class="meta">Популярные и высокомаржинальные позиции</div></div><div class="card metric"><div class="label">Срочно закупить</div><div class="value"><?=count($urgent)?></div><div class="meta">Запас менее чем на 3 дня</div></div></div>

<div class="card table-card section"><div class="chart-head"><div><h2>Аналитика по сменам</h2><p>Выручка, средний чек, себестоимость и валовая прибыль по сменам Эвотор</p></div></div><table><thead><tr><th>Смена</th><th>Начало</th><th>Чеков</th><th>Выручка</th><th>Средний чек</th><th>Food cost</th><th>Валовая прибыль</th></tr></thead><tbody><?php foreach($shifts as $s):?><tr><td>#<?=e((string)($s['session_number']??'—'))?></td><td><?=e(date('d.m.Y H:i',strtotime($s['started_at'])))?></td><td><?=$s['checks']?></td><td><?=money($s['revenue'])?></td><td><?=money($s['avg_check'])?></td><td><?=number_format($s['food_cost'],1,',',' ')?>%</td><td><strong><?=money($s['gross_profit'])?></strong></td></tr><?php endforeach;?></tbody></table></div>

<div class="two-col section"><div class="card table-card"><div class="chart-head"><div><h2>Расхождения склада</h2><p>Факт инвентаризации против расчётного остатка</p></div></div><table><thead><tr><th>Дата</th><th>Ингредиент</th><th>Отклонение</th><th>Стоимость</th></tr></thead><tbody><?php foreach($variance as $v):?><tr><td><?=e(date('d.m.Y',strtotime($v['counted_at'])))?></td><td><?=e($v['name'])?></td><td><?=e((string)$v['difference_quantity'])?> <?=e($v['unit'])?></td><td><?=money((float)$v['variance_value'])?></td></tr><?php endforeach;?></tbody></table></div>
<div class="card table-card"><div class="chart-head"><div><h2>Прогноз закупок</h2><p>Расход последних 14 дней, целевой запас — 7 дней</p></div></div><table><thead><tr><th>Ингредиент</th><th>Остаток</th><th>Хватит</th><th>Заказать</th></tr></thead><tbody><?php foreach(array_slice($forecast,0,20) as $r):?><tr><td><?=e($r['name'])?></td><td><?=number_format((float)$r['stock_quantity'],2,',',' ')?> <?=e($r['unit'])?></td><td><?=$r['days_left']===null?'—':number_format((float)$r['days_left'],1,',',' ').' дн.'?></td><td><?=$r['suggested_order']>0?number_format((float)$r['suggested_order'],2,',',' ').' '.e($r['unit']).' · '.money((float)$r['suggested_order_value']):'Не требуется'?></td></tr><?php endforeach;?></tbody></table></div></div>

<div class="card table-card section"><div class="chart-head"><div><h2>Menu engineering</h2><p>Классификация по популярности и валовой прибыли на единицу</p></div></div><table><thead><tr><th>Позиция</th><th>Класс</th><th>Продано</th><th>Выручка</th><th>Прибыль/ед.</th><th>Валовая прибыль</th><th>Действие</th></tr></thead><tbody><?php foreach($menu as $r):$action=$r['class']==='Звезда'?'Держать качество и наличие':($r['class']==='Рабочая лошадка'?'Проверить цену/себестоимость':($r['class']==='Загадка'?'Продвигать и улучшать видимость':'Пересмотреть или убрать'));?><tr><td><strong><?=e($r['name'])?></strong></td><td><span class="pill <?=($r['class']==='Звезда'?'connected':'')?>"><?=e($r['class'])?></span></td><td><?=number_format($r['qty'],1,',',' ')?></td><td><?=money($r['revenue'])?></td><td><?=money($r['contribution'])?></td><td><?=money($r['gross_profit'])?></td><td><?=e($action)?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>