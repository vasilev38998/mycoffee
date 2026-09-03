<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
$from=$_GET['from'] ?? date('Y-m-01');
$to=$_GET['to'] ?? date('Y-m-d');
$m=dashboard_metrics($from,$to);
$top=db()->prepare("SELECT p.name,SUM(si.quantity) qty,SUM(si.quantity*si.unit_price) revenue,SUM(si.quantity*(si.unit_price-si.unit_cost)) profit FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY p.id,p.name ORDER BY profit DESC LIMIT 8");
$top->execute([$from.' 00:00:00',$to]);
page_header('Дашборд');
?>
<form class="card form-grid" method="get"><label>С<input type="date" name="from" value="<?=e($from)?>"></label><label>По<input type="date" name="to" value="<?=e($to)?>"></label><button class="btn primary">Показать</button></form>
<div class="grid section">
<div class="card metric"><div class="muted">Выручка</div><div class="value"><?=money($m['revenue'])?></div></div>
<div class="card metric"><div class="muted">Чеков</div><div class="value"><?=$m['checks']?></div></div>
<div class="card metric"><div class="muted">Средний чек</div><div class="value"><?=money($m['avg_check'])?></div></div>
<div class="card metric"><div class="muted">Себестоимость</div><div class="value"><?=money($m['cogs'])?></div></div>
<div class="card metric"><div class="muted">Валовая прибыль</div><div class="value"><?=money($m['gross_profit'])?></div></div>
<div class="card metric"><div class="muted">Расходы</div><div class="value"><?=money($m['expenses'])?></div></div>
<div class="card metric"><div class="muted">Операционная прибыль</div><div class="value"><?=money($m['operating_profit'])?></div></div>
<div class="card metric"><div class="muted">Маржинальность</div><div class="value"><?=number_format($m['margin'],1,',',' ')?>%</div></div>
</div>
<div class="card section"><h2>Самые прибыльные позиции</h2><table><thead><tr><th>Позиция</th><th>Кол-во</th><th>Выручка</th><th>Валовая прибыль</th></tr></thead><tbody><?php foreach($top as $row):?><tr><td><?=e($row['name'])?></td><td><?=e((string)$row['qty'])?></td><td><?=money((float)$row['revenue'])?></td><td><?=money((float)$row['profit'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>