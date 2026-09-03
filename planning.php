<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

$monthFrom=date('Y-m-01');
$monthTo=date('Y-m-d');
$m=dashboard_metrics($monthFrom,$monthTo);
$grossMarginRatio=$m['revenue']>0?max(0,min(1,$m['gross_profit']/$m['revenue'])):0;
$breakEven=$grossMarginRatio>0?$m['expenses']/$grossMarginRatio:0;
$profitGoal=(float)app_setting('monthly_profit_goal','0');
$revenueForProfitGoal=$grossMarginRatio>0?($m['expenses']+$profitGoal)/$grossMarginRatio:0;

$products=db()->query('SELECT id,name,sale_price FROM products WHERE active=1 ORDER BY name')->fetchAll();
$selectedId=(int)($_GET['product_id']??$_POST['product_id']??($products[0]['id']??0));
$product=null;
foreach($products as $p){if((int)$p['id']===$selectedId){$product=$p;break;}}

$scenario=null;
if($product){
    $cost=product_cost($selectedId);
    $stmt=db()->prepare("SELECT COALESCE(SUM(si.quantity),0) qty,COALESCE(SUM(si.quantity*si.unit_price),0) revenue FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.product_id=? AND s.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
    $stmt->execute([$selectedId]);
    $history=$stmt->fetch();
    $qty30=(float)$history['qty'];
    $currentPrice=(float)$product['sale_price'];
    $newPrice=(float)($_POST['new_price']??$currentPrice);
    $volumeChange=(float)($_POST['volume_change']??0);
    $projectedQty=max(0,$qty30*(1+$volumeChange/100));
    $currentGross=$qty30*($currentPrice-$cost);
    $projectedGross=$projectedQty*($newPrice-$cost);
    $scenario=[
        'cost'=>$cost,'qty30'=>$qty30,'current_price'=>$currentPrice,'new_price'=>$newPrice,'volume_change'=>$volumeChange,
        'projected_qty'=>$projectedQty,'current_gross'=>$currentGross,'projected_gross'=>$projectedGross,'difference'=>$projectedGross-$currentGross,
        'current_margin'=>$currentPrice>0?($currentPrice-$cost)/$currentPrice*100:0,
        'new_margin'=>$newPrice>0?($newPrice-$cost)/$newPrice*100:0,
    ];
}

$extraCost=(float)($_POST['extra_cost']??0);
$requiredExtraRevenue=$grossMarginRatio>0?$extraCost/$grossMarginRatio:0;

page_header('Планирование');
?>
<div class="grid">
<div class="card metric"><div class="label">Текущая валовая маржа</div><div class="value"><?=number_format($grossMarginRatio*100,1,',',' ')?>%</div><div class="meta">Основа для управленческих сценариев</div></div>
<div class="card metric"><div class="label">Точка безубыточности</div><div class="value"><?=money($breakEven)?></div><div class="meta">При текущей структуре расходов и марже</div></div>
<div class="card metric"><div class="label">Цель прибыли</div><div class="value"><?=money($profitGoal)?></div><div class="meta">Меняется в Настройки → Цели</div></div>
<div class="card metric"><div class="label">Выручка для цели</div><div class="value"><?=money($revenueForProfitGoal)?></div><div class="meta">Оценка при сохранении текущей валовой маржи</div></div>
</div>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Что будет, если изменить цену?</h2><p>Берём реальные продажи позиции за последние 30 дней</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Позиция<select name="product_id" onchange="this.form.submit()"><?php foreach($products as $p):?><option value="<?=$p['id']?>" <?=(int)$p['id']===$selectedId?'selected':''?>><?=e($p['name'])?> — <?=money((float)$p['sale_price'])?></option><?php endforeach;?></select></label><label>Новая цена<input type="number" step="1" min="0" name="new_price" value="<?=e((string)($scenario['new_price']??0))?>"></label><label>Изменение объёма продаж, %<input type="number" step="1" name="volume_change" value="<?=e((string)($scenario['volume_change']??0))?>"><span class="muted">Например, -5 если ожидаешь падение количества на 5%</span></label><div><button class="btn primary">Рассчитать сценарий</button></div></form><?php if($scenario):?><div class="section"><table><tbody><tr><td>Себестоимость позиции</td><td><strong><?=money($scenario['cost'])?></strong></td></tr><tr><td>Продано за 30 дней</td><td><strong><?=number_format($scenario['qty30'],1,',',' ')?></strong></td></tr><tr><td>Текущая маржа</td><td><strong><?=number_format($scenario['current_margin'],1,',',' ')?>%</strong></td></tr><tr><td>Маржа при новой цене</td><td><strong><?=number_format($scenario['new_margin'],1,',',' ')?>%</strong></td></tr><tr><td>Текущая валовая прибыль позиции</td><td><?=money($scenario['current_gross'])?></td></tr><tr><td>Прогноз валовой прибыли</td><td><strong><?=money($scenario['projected_gross'])?></strong></td></tr><tr><td>Изменение</td><td><strong><?=($scenario['difference']>=0?'+':'').money($scenario['difference'])?></strong></td></tr></tbody></table></div><?php endif;?></div>

<div class="card"><div class="chart-head"><div><h2>Новый постоянный расход</h2><p>Сколько дополнительной выручки потребуется</p></div></div><form method="post" class="stack"><input type="hidden" name="product_id" value="<?=$selectedId?>"><label>Дополнительный расход в месяц<input type="number" step="100" min="0" name="extra_cost" value="<?=e((string)$extraCost)?>" placeholder="Например, 30000"></label><button class="btn primary">Посчитать</button></form><div class="section insight-card"><div class="kicker">Нужно дополнительной выручки</div><strong><?=money($requiredExtraRevenue)?></strong><p>При сохранении текущей валовой маржи <?=number_format($grossMarginRatio*100,1,',',' ')?>%.</p></div><div class="section alert-item warn"><span class="alert-dot"></span><div><strong>Важно</strong><p>Это управленческая модель, а не бухгалтерский прогноз. Она предполагает, что структура продаж и валовая маржа останутся примерно такими же.</p></div></div></div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Как использовать</h2><p>Практические сценарии владельца</p></div></div><div class="three-col"><div class="insight-card"><div class="kicker">Цена</div><strong>260 → 280 ₽</strong><p>Укажи возможное падение объёма и посмотри, станет ли позиция приносить больше валовой прибыли.</p></div><div class="insight-card"><div class="kicker">Зарплата</div><strong>+10 000 ₽</strong><p>Посчитай, какая дополнительная выручка нужна, чтобы повышение ФОТ не снизило прибыль.</p></div><div class="insight-card"><div class="kicker">Новая точка</div><strong>Порог выручки</strong><p>Используй точку безубыточности как первичный ориентир для экономики будущей кофейни.</p></div></div></div>
<?php page_footer(); ?>