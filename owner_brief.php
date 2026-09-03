<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/owner_brief.php';
require __DIR__.'/inc/layout.php';

$date=$_GET['date']??date('Y-m-d',strtotime('-1 day'));
$brief=owner_brief_data($date);
$d=$brief['day'];$p=$brief['prev'];
$revDelta=$p['revenue']>0?($d['revenue']-$p['revenue'])/$p['revenue']*100:null;
$avgDelta=$p['avg_check']>0?($d['avg_check']-$p['avg_check'])/$p['avg_check']*100:null;
$food=$d['revenue']>0?$d['cogs']/$d['revenue']*100:0;
$forecastPct=$brief['revenue_goal']>0?$brief['forecast_revenue']/$brief['revenue_goal']*100:null;
page_header('Утро владельца');
?>
<form class="card filter-card" method="get"><label>День для разбора<input type="date" name="date" value="<?=e($date)?>"></label><div class="period-note">По умолчанию — вчерашний завершённый день</div><button class="btn primary">Обновить</button></form>

<div class="card section"><div class="chart-head"><div><h2>Что требует внимания сегодня</h2><p>Kapouch выбирает до трёх приоритетов из контроля, склада, техкарт и плана месяца.</p></div></div><div class="alerts"><?php foreach($brief['actions'] as $a):?><a class="alert-item <?=e($a['level'])?>" href="<?=e($a['href'])?>" style="display:flex"><span class="alert-dot"></span><div><strong><?=e($a['title'])?></strong><p><?=e($a['text'])?></p></div></a><?php endforeach;?></div></div>

<div class="grid section">
<div class="card metric"><div class="label">Выручка вчера</div><div class="value"><?=money($d['revenue'])?></div><div class="meta"><?=$revDelta===null?'Нет базы для сравнения':(($revDelta>=0?'↑ ':'↓ ').number_format(abs($revDelta),1,',',' ').'% к предыдущему дню')?></div></div>
<div class="card metric"><div class="label">Операционная прибыль</div><div class="value"><?=money($d['operating_profit'])?></div><div class="meta">После себестоимости и учтённых расходов</div></div>
<div class="card metric"><div class="label">Средний чек</div><div class="value"><?=money($d['avg_check'])?></div><div class="meta"><?=$avgDelta===null?'Нет базы для сравнения':(($avgDelta>=0?'↑ ':'↓ ').number_format(abs($avgDelta),1,',',' ').'%')?> · <?=$d['checks']?> чеков</div></div>
<div class="card metric"><div class="label">Food cost</div><div class="value"><?=number_format($food,1,',',' ')?>%</div><div class="meta"><?=money($d['cogs'])?> себестоимости</div></div>
</div>

<div class="three-col section">
<div class="insight-card"><div class="kicker">Сейчас в кассе</div><strong><?=money((float)$brief['cash']['balance'])?></strong><p><?=($brief['cash']['shift_open']??null)===true?'Текущая открытая смена':((($brief['cash']['shift_open']??null)===false)?'Смена закрыта':'По доступной истории кассы')?></p></div>
<div class="insight-card"><div class="kicker">Расходы за день</div><strong><?=money($d['expenses'])?></strong><p>Ручные <?=money($d['manual_expenses'])?> · автоматические <?=money($d['automatic_expenses'])?>.</p></div>
<div class="insight-card"><div class="kicker">Возвраты наличными</div><strong><?=money((float)$brief['cash_day']['cash_returns'])?></strong><p>Сверь с кассой, если значение необычное для смены.</p></div>
</div>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Прогноз месяца</h2><p>Линейный прогноз по текущему среднему темпу месяца</p></div></div><table><tbody><tr><td>Выручка накопительно</td><td><strong><?=money($brief['month']['revenue'])?></strong></td></tr><tr><td>Прогноз выручки</td><td><strong><?=money($brief['forecast_revenue'])?></strong></td></tr><?php if($brief['revenue_goal']>0):?><tr><td>Цель месяца</td><td><?=money($brief['revenue_goal'])?></td></tr><tr><td>Прогноз к цели</td><td><strong><?=number_format((float)$forecastPct,0,',',' ')?>%</strong></td></tr><?php endif;?><tr><td>Прогноз операционной прибыли</td><td><strong><?=money($brief['forecast_profit'])?></strong></td></tr></tbody></table><div class="alert info section">Прогноз ориентировочный: он продолжает текущий средний темп и не знает будущий трафик, закупки или разовые расходы.</div></div>
<div class="card"><div class="chart-head"><div><h2>Готовность данных</h2><p>Что сейчас ограничивает точность управленческой картины</p></div></div><div class="alerts"><div class="alert-item <?=$brief['no_recipe']>0?'warn':'good'?>"><span class="alert-dot"></span><div><strong><?=$brief['no_recipe']?> активных позиций без техкарты</strong><p><?=$brief['no_recipe']>0?'Их продажи не смогут получить нормальную себестоимость.':'Техкарты заполнены для активного меню.'?></p></div></div><div class="alert-item <?=$brief['missing_cost']>0?'warn':'good'?>"><span class="alert-dot"></span><div><strong><?=$brief['missing_cost']?> позиций зависят от ингредиентов с нулевой ценой</strong><p><?=$brief['missing_cost']>0?'Заполни закупочные параметры ингредиентов для точного Food Cost.':'Нулевых закупочных цен в используемых техкартах не найдено.'?></p></div></div></div></div>
</div>

<div class="card table-card section"><div class="chart-head"><div><h2>Критичные остатки</h2><p>Ингредиенты, которые достигли установленного минимального остатка</p></div><div class="chart-summary"><?=count($brief['low_stock'])?></div></div><?php if($brief['low_stock']):?><table><thead><tr><th>Ингредиент</th><th>Остаток</th><th>Минимум</th><th>Статус</th></tr></thead><tbody><?php foreach($brief['low_stock'] as $r):?><tr><td><strong><?=e($r['name'])?></strong></td><td><?=e((string)$r['stock_quantity'])?> <?=e($r['unit'])?></td><td><?=e((string)$r['min_stock_quantity'])?> <?=e($r['unit'])?></td><td><?=$r['stock_quantity']<=0?'Нет в наличии':'Ниже минимума'?></td></tr><?php endforeach;?></tbody></table><?php else:?><div class="alert success">Критичных остатков по установленным минимумам сейчас нет.</div><?php endif;?></div>

<div class="card section"><div class="chart-head"><div><h2>Быстрые переходы</h2><p>После утренней проверки</p></div></div><div style="display:flex;gap:10px;flex-wrap:wrap"><a class="btn primary" href="control.php">Контроль</a><a class="btn" href="cash.php">Касса</a><a class="btn" href="inventory.php">Склад</a><a class="btn" href="budget.php">План-факт</a><a class="btn" href="products.php">Техкарты</a></div></div>
<?php page_footer(); ?>