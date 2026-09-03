<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/suppliers.php';
require __DIR__.'/inc/layout.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        set_app_setting('purchase_price_warning_pct',(string)max(0,(float)($_POST['warning_pct']??10)));
        set_app_setting('purchase_price_critical_pct',(string)max(0,(float)($_POST['critical_pct']??20)));
        flash('success','Пороги контроля закупочных цен сохранены.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('purchase_prices.php');
}
$rows=ingredient_price_intelligence(200);$alerts=purchase_price_alerts();$selected=(int)($_GET['ingredient_id']??($rows[0]['ingredient_id']??0));
$impact=$selected?ingredient_menu_price_impact($selected):[];$supplierCompare=$selected?supplier_ingredient_comparison($selected):[];
$selectedRow=null;foreach($rows as $r){if($r['ingredient_id']===$selected){$selectedRow=$r;break;}}
$warning=(float)app_setting('purchase_price_warning_pct','10');$critical=(float)app_setting('purchase_price_critical_pct','20');
page_header('Закупочные цены');
?>
<div class="grid">
<div class="card metric"><div class="label">Ингредиентов с историей</div><div class="value"><?=count($rows)?></div><div class="meta">Есть минимум одна закупка</div></div>
<div class="card metric"><div class="label">Сигналы роста цены</div><div class="value"><?=count($alerts)?></div><div class="meta">Рост выше <?=number_format($warning,0,',',' ')?>%</div></div>
<div class="card metric"><div class="label">Критичные подорожания</div><div class="value"><?=count(array_filter($alerts,fn($a)=>$a['severity']==='critical'))?></div><div class="meta">Рост выше <?=number_format($critical,0,',',' ')?>%</div></div>
<div class="card metric"><div class="label">Поставщики</div><div class="value"><a href="suppliers.php">Открыть →</a></div><div class="meta">карточки и оборот</div></div>
</div>

<?php if($alerts):?><div class="card section"><div class="chart-head"><div><h2>Сигналы подорожания</h2><p>Сравнение последней закупки с предыдущей.</p></div></div><div class="alerts"><?php foreach(array_slice($alerts,0,12) as $a):?><a class="alert-item <?=$a['severity']==='critical'?'bad':'warn'?>" href="purchase_prices.php?ingredient_id=<?=$a['ingredient_id']?>"><span class="alert-dot"></span><div><strong><?=e($a['name'])?>: +<?=number_format((float)$a['change_pct'],1,',',' ')?>%</strong><p><?=money((float)$a['previous_price'])?> → <?=money((float)$a['latest_price'])?> за <?=e($a['unit'])?> · <?=e($a['supplier_name'])?>.</p></div></a><?php endforeach;?></div></div><?php endif;?>

<div class="two-col section">
<div class="card table-card"><div class="chart-head"><div><h2>Динамика ингредиентов</h2><p>Последняя цена, предыдущая цена и средняя за доступные закупки последних 30 дней.</p></div></div><table><thead><tr><th>Ингредиент</th><th>Последняя цена</th><th>Изменение</th><th>Средняя 30 дней</th><th>Поставщик</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><a href="purchase_prices.php?ingredient_id=<?=$r['ingredient_id']?>"><strong><?=e($r['name'])?></strong></a></td><td><?=money((float)$r['latest_price'])?> / <?=e($r['unit'])?></td><td><?php if($r['change_pct']===null):?>—<?php else:?><span class="delta <?=$r['change_pct']>=$warning?'down':'neutral'?>"><?=$r['change_pct']>=0?'+':''?><?=number_format((float)$r['change_pct'],1,',',' ')?>%</span><?php endif;?></td><td><?=money((float)$r['avg_30'])?></td><td><?=e($r['supplier_name'])?></td></tr><?php endforeach;?></tbody></table></div>
<div class="card"><div class="chart-head"><div><h2>Пороги контроля</h2><p>Когда Kapouch должен считать рост цены значимым.</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Предупреждение, %<input type="number" min="0" step="0.1" name="warning_pct" value="<?=e((string)$warning)?>"></label><label>Критично, %<input type="number" min="0" step="0.1" name="critical_pct" value="<?=e((string)$critical)?>"></label><button class="btn primary">Сохранить пороги</button></form><?php if($selectedRow):?><div class="insight-card section"><div class="kicker">Выбранный ингредиент</div><strong><?=e($selectedRow['name'])?></strong><p>Последняя закупка <?=e(date('d.m.Y',strtotime($selectedRow['latest_date'])))?> у <?=e($selectedRow['supplier_name'])?>.</p></div><?php endif;?></div>
</div>

<?php if($selectedRow):?><div class="two-col section">
<div class="card table-card"><div class="chart-head"><div><h2>Влияние на меню: <?=e($selectedRow['name'])?></h2><p>Как изменение закупочной цены повлияло на расчётную себестоимость и маржу позиций.</p></div></div><table><thead><tr><th>Позиция</th><th>Цена продажи</th><th>Себестоимость</th><th>Изменение</th><th>Маржа сейчас</th></tr></thead><tbody><?php foreach($impact as $r):?><tr><td><strong><?=e($r['name'])?></strong></td><td><?=money((float)$r['sale_price'])?></td><td><?=money((float)$r['new_cost'])?></td><td><span class="delta <?=$r['cost_delta']>0?'down':($r['cost_delta']<0?'up':'neutral')?>"><?=$r['cost_delta']>=0?'+':''?><?=money((float)$r['cost_delta'])?></span></td><td><?=number_format((float)$r['new_margin'],1,',',' ')?>%</td></tr><?php endforeach;?></tbody></table><?php if(!$impact):?><p class="muted">Этот ингредиент пока не используется в активных техкартах.</p><?php endif;?></div>
<div class="card table-card"><div class="chart-head"><div><h2>Сравнение поставщиков</h2><p>Средневзвешенная цена по всей истории закупок ингредиента.</p></div></div><table><thead><tr><th>Поставщик</th><th>Закупок</th><th>Средняя цена</th><th>Лучшая цена</th><th>Последняя закупка</th></tr></thead><tbody><?php foreach($supplierCompare as $r):?><tr><td><strong><?=e($r['supplier_name'])?></strong></td><td><?=number_format((int)$r['purchases'],0,',',' ')?></td><td><?=money((float)$r['weighted_unit_price'])?></td><td><?=money((float)$r['best_unit_price'])?></td><td><?=e(date('d.m.Y',strtotime($r['last_purchase'])))?></td></tr><?php endforeach;?></tbody></table></div>
</div><?php endif;?>

<div class="alert warning section"><strong>Важно.</strong> Влияние на меню считается по текущей техкарте и последним двум закупочным ценам ингредиента. Это управленческая оценка: если упаковки, единицы измерения или состав техкарты менялись между закупками, результат нужно интерпретировать с учётом этих изменений.</div>
<?php page_footer(); ?>