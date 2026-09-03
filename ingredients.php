<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $name=trim($_POST['name']??''); $unit=$_POST['unit']??'g';
    $price=(float)($_POST['purchase_price']??0); $qty=(float)($_POST['purchase_quantity']??1); $stock=(float)($_POST['stock_quantity']??0);
    if($name!=='' && $qty>0){$s=db()->prepare('INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity) VALUES(?,?,?,?,?)');$s->execute([$name,$unit,$price,$qty,$stock]);flash('success','Ингредиент добавлен.');}
    redirect('ingredients.php');
}
$rows=db()->query('SELECT *, purchase_price/NULLIF(purchase_quantity,0) unit_cost FROM ingredients ORDER BY name')->fetchAll();
page_header('Ингредиенты');
?>
<div class="card"><h2>Добавить ингредиент</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Название<input name="name" required></label><label>Единица<select name="unit"><option value="g">г</option><option value="kg">кг</option><option value="ml">мл</option><option value="l">л</option><option value="pcs">шт</option></select></label><label>Цена закупки<input type="number" step="0.01" name="purchase_price" required></label><label>Количество в закупке<input type="number" step="0.001" name="purchase_quantity" value="1" required></label><label>Остаток<input type="number" step="0.001" name="stock_quantity" value="0"></label><div><button class="btn primary">Добавить</button></div></form></div>
<div class="card section"><table><thead><tr><th>Ингредиент</th><th>Ед.</th><th>Закупка</th><th>Цена за базовую ед.</th><th>Остаток</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['name'])?></td><td><?=e($r['unit'])?></td><td><?=money((float)$r['purchase_price'])?> / <?=e((string)$r['purchase_quantity'])?></td><td><?=money((float)$r['unit_cost'])?></td><td><?=e((string)$r['stock_quantity'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>