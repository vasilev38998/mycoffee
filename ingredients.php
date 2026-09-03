<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

$allowedUnits=['g','kg','ml','l','pcs'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=(string)($_POST['action']??'create');
    try{
        if($action==='update'){
            $id=(int)($_POST['id']??0);
            $name=trim((string)($_POST['name']??''));
            $unit=(string)($_POST['unit']??'g');
            $price=(float)($_POST['purchase_price']??0);
            $qty=(float)($_POST['purchase_quantity']??1);
            $stock=(float)($_POST['stock_quantity']??0);
            $minStock=(float)($_POST['min_stock_quantity']??0);
            if($id<=0||$name===''||!in_array($unit,$allowedUnits,true)||$qty<=0)throw new RuntimeException('Проверь название, единицу и количество в закупке.');
            if($price<0||$stock<0||$minStock<0)throw new RuntimeException('Цена и остатки не могут быть отрицательными.');
            $s=db()->prepare('UPDATE ingredients SET name=?,unit=?,purchase_price=?,purchase_quantity=?,stock_quantity=?,min_stock_quantity=? WHERE id=?');
            $s->execute([$name,$unit,$price,$qty,$stock,$minStock,$id]);
            audit_write('ingredient_updated','Изменена карточка ингредиента','ingredient',(string)$id);
            flash('success','Ингредиент обновлён.');
        }else{
            $name=trim((string)($_POST['name']??''));
            $unit=(string)($_POST['unit']??'g');
            $price=(float)($_POST['purchase_price']??0);
            $qty=(float)($_POST['purchase_quantity']??1);
            $stock=(float)($_POST['stock_quantity']??0);
            $minStock=(float)($_POST['min_stock_quantity']??0);
            if($name===''||!in_array($unit,$allowedUnits,true)||$qty<=0)throw new RuntimeException('Проверь название, единицу и количество в закупке.');
            if($price<0||$stock<0||$minStock<0)throw new RuntimeException('Цена и остатки не могут быть отрицательными.');
            $s=db()->prepare('INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity) VALUES(?,?,?,?,?,?)');
            $s->execute([$name,$unit,$price,$qty,$stock,$minStock]);
            flash('success','Ингредиент добавлен.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('ingredients.php');
}
$rows=db()->query('SELECT *, purchase_price/NULLIF(purchase_quantity,0) unit_cost FROM ingredients ORDER BY name')->fetchAll();
page_header('Ингредиенты');
?>
<div class="card"><h2>Добавить ингредиент</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="create"><label>Название<input name="name" required></label><label>Единица<select name="unit"><option value="g">г</option><option value="kg">кг</option><option value="ml">мл</option><option value="l">л</option><option value="pcs">шт</option></select></label><label>Цена закупки<input type="number" min="0" step="0.01" name="purchase_price" required></label><label>Количество в закупке<input type="number" min="0.001" step="0.001" name="purchase_quantity" value="1" required></label><label>Остаток<input type="number" min="0" step="0.001" name="stock_quantity" value="0"></label><label>Минимальный остаток<input type="number" min="0" step="0.001" name="min_stock_quantity" value="0"></label><div><button class="btn primary">Добавить</button></div></form></div>

<div class="card section"><div class="chart-head"><div><h2>Список ингредиентов</h2><p>Карточку можно изменить без удаления ингредиента и без потери связей с техкартами.</p></div></div><table><thead><tr><th>Ингредиент</th><th>Ед.</th><th>Закупка</th><th>Цена за базовую ед.</th><th>Остаток</th><th>Мин. остаток</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=e($r['name'])?></strong></td><td><?=e($r['unit'])?></td><td><?=money((float)$r['purchase_price'])?> / <?=e((string)$r['purchase_quantity'])?></td><td><?=money((float)$r['unit_cost'])?></td><td><?=e((string)$r['stock_quantity'])?></td><td><?=e((string)($r['min_stock_quantity']??0))?></td><td><details><summary class="btn ghost">Редактировать</summary><form method="post" class="stack" style="margin-top:10px;min-width:280px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=$r['id']?>"><label>Название<input name="name" value="<?=e($r['name'])?>" required></label><label>Единица<select name="unit"><?php foreach($allowedUnits as $unit):?><option value="<?=e($unit)?>" <?=$r['unit']===$unit?'selected':''?>><?=e($unit==='pcs'?'шт':$unit)?></option><?php endforeach;?></select></label><label>Цена закупки<input type="number" min="0" step="0.01" name="purchase_price" value="<?=e((string)$r['purchase_price'])?>" required></label><label>Количество в закупке<input type="number" min="0.001" step="0.001" name="purchase_quantity" value="<?=e((string)$r['purchase_quantity'])?>" required></label><label>Текущий остаток<input type="number" min="0" step="0.001" name="stock_quantity" value="<?=e((string)$r['stock_quantity'])?>"></label><label>Минимальный остаток<input type="number" min="0" step="0.001" name="min_stock_quantity" value="<?=e((string)($r['min_stock_quantity']??0))?>"></label><div class="alert warning"><strong>Единица измерения.</strong> Если изменить, например, g на kg или ml на l, количества в существующих техкартах автоматически не пересчитаются. После такого изменения проверь связанные техкарты.</div><button class="btn primary">Сохранить изменения</button></form></details></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>