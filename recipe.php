<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/recipe_templates.php';
$id=(int)($_GET['id']??0);
$stmt=db()->prepare('SELECT * FROM products WHERE id=?');$stmt->execute([$id]);$product=$stmt->fetch();if(!$product){http_response_code(404);exit('Позиция не найдена');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'ingredient');
 try{
  if($action==='template'){
   $result=recipe_template_apply($id,(string)($_POST['template_key']??''),(int)($_POST['size']??0),isset($_POST['include_cup']),isset($_POST['include_straw']),isset($_POST['replace_recipe']));
   flash('success','Применён шаблон «'.$result['template'].'» '.$result['size'].' мл. Проверь количества под вашу фактическую рецептуру.');
  }elseif($action==='delete'){
   $ingredientId=(int)($_POST['ingredient_id']??0);if($ingredientId>0){$s=db()->prepare('DELETE FROM recipe_items WHERE product_id=? AND ingredient_id=?');$s->execute([$id,$ingredientId]);flash('success','Ингредиент удалён из техкарты.');}
  }else{
   $ingredientId=(int)($_POST['ingredient_id']??0);$qty=(float)($_POST['quantity']??0);
   if($ingredientId>0&&$qty>0){$s=db()->prepare('INSERT INTO recipe_items(product_id,ingredient_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)');$s->execute([$id,$ingredientId,$qty]);flash('success','Техкарта обновлена.');}
  }
 }catch(Throwable $e){flash('danger',$e->getMessage());}
 redirect('recipe.php?id='.$id);
}
$templates=recipe_templates();
$ingredients=db()->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();
$s=db()->prepare('SELECT ri.*,i.name,i.unit,i.purchase_price/i.purchase_quantity unit_cost,(ri.quantity*(i.purchase_price/i.purchase_quantity)) line_cost FROM recipe_items ri JOIN ingredients i ON i.id=ri.ingredient_id WHERE ri.product_id=? ORDER BY i.name');$s->execute([$id]);$items=$s->fetchAll();
page_header('Техкарта: '.$product['name']);
?>
<div class="grid"><div class="card metric"><div class="muted">Цена продажи</div><div class="value"><?=money((float)$product['sale_price'])?></div></div><div class="card metric"><div class="muted">Себестоимость</div><div class="value"><?=money(product_cost($id))?></div></div></div>

<div class="card section"><div class="chart-head"><div><h2>Заполнить из стандартного шаблона</h2><p>Стартовая техкарта для быстрого запуска. После применения обязательно сверяй граммовку кофе, молока и других ингредиентов с вашей фактической рецептурой.</p></div></div>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="template"><div class="form-grid"><label>Напиток<select name="template_key" required><?php foreach($templates as $key=>$template):?><option value="<?=e($key)?>"><?=e($template['label'])?></option><?php endforeach;?></select></label><label>Объём стакана<select name="size" required><option value="250">250 мл</option><option value="350">350 мл</option><option value="450">450 мл</option></select></label></div><div style="display:flex;gap:22px;flex-wrap:wrap"><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="include_cup" value="1" checked style="width:auto"> Добавить стакан выбранного объёма</label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="include_straw" value="1" checked style="width:auto"> Добавить трубочку</label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="replace_recipe" value="1" style="width:auto"> Полностью заменить текущую техкарту</label></div><div class="alert warning"><strong>Шаблон, не стандарт заведения.</strong> Kapouch создаст недостающие базовые ингредиенты и расходники с ценой закупки 0 ₽. После этого укажи реальные закупочные цены в «Ингредиентах», чтобы себестоимость считалась корректно.</div><div><button class="btn primary">Применить шаблон</button></div></form></div>

<div class="card section"><h2>Добавить или изменить ингредиент</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="ingredient"><label>Ингредиент<select name="ingredient_id" required><?php foreach($ingredients as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?> (<?=e($i['unit'])?>)</option><?php endforeach;?></select></label><label>Количество на 1 порцию<input type="number" step="0.001" name="quantity" required></label><div><button class="btn primary">Сохранить</button></div></form></div>
<div class="card section"><table><thead><tr><th>Ингредиент</th><th>Количество</th><th>Цена за ед.</th><th>Стоимость</th><th></th></tr></thead><tbody><?php foreach($items as $r):?><tr><td><?=e($r['name'])?></td><td><?=e((string)$r['quantity'])?> <?=e($r['unit'])?></td><td><?=money((float)$r['unit_cost'])?></td><td><?=money((float)$r['line_cost'])?></td><td><form method="post" onsubmit="return confirm('Удалить ингредиент из техкарты?')"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="ingredient_id" value="<?=$r['ingredient_id']?>"><button class="btn ghost">Удалить</button></form></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer();?>