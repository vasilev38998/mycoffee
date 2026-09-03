<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
$id=(int)($_GET['id']??0);
$stmt=db()->prepare('SELECT * FROM products WHERE id=?');$stmt->execute([$id]);$product=$stmt->fetch();if(!$product){http_response_code(404);exit('Позиция не найдена');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$ingredientId=(int)($_POST['ingredient_id']??0);$qty=(float)($_POST['quantity']??0);
 if($ingredientId>0&&$qty>0){$s=db()->prepare('INSERT INTO recipe_items(product_id,ingredient_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)');$s->execute([$id,$ingredientId,$qty]);flash('success','Техкарта обновлена.');}
 redirect('recipe.php?id='.$id);
}
$ingredients=db()->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();
$s=db()->prepare('SELECT ri.*,i.name,i.unit,i.purchase_price/i.purchase_quantity unit_cost,(ri.quantity*(i.purchase_price/i.purchase_quantity)) line_cost FROM recipe_items ri JOIN ingredients i ON i.id=ri.ingredient_id WHERE ri.product_id=? ORDER BY i.name');$s->execute([$id]);$items=$s->fetchAll();
page_header('Техкарта: '.$product['name']);
?>
<div class="grid"><div class="card metric"><div class="muted">Цена продажи</div><div class="value"><?=money((float)$product['sale_price'])?></div></div><div class="card metric"><div class="muted">Себестоимость</div><div class="value"><?=money(product_cost($id))?></div></div></div>
<div class="card section"><h2>Добавить ингредиент</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Ингредиент<select name="ingredient_id" required><?php foreach($ingredients as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?> (<?=e($i['unit'])?>)</option><?php endforeach;?></select></label><label>Количество на 1 порцию<input type="number" step="0.001" name="quantity" required></label><div><button class="btn primary">Сохранить</button></div></form></div>
<div class="card section"><table><thead><tr><th>Ингредиент</th><th>Количество</th><th>Цена за ед.</th><th>Стоимость</th></tr></thead><tbody><?php foreach($items as $r):?><tr><td><?=e($r['name'])?></td><td><?=e((string)$r['quantity'])?> <?=e($r['unit'])?></td><td><?=money((float)$r['unit_cost'])?></td><td><?=money((float)$r['line_cost'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer();?>