<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $name=trim($_POST['name']??'');$category=trim($_POST['category']??'');$price=(float)($_POST['sale_price']??0);
 if($name!==''){$s=db()->prepare('INSERT INTO products(name,category,sale_price) VALUES(?,?,?)');$s->execute([$name,$category,$price]);flash('success','Позиция меню добавлена.');}
 redirect('products.php');
}
$products=db()->query('SELECT * FROM products ORDER BY category,name')->fetchAll();
page_header('Меню и техкарты');
?>
<div class="card"><h2>Новая позиция</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Название<input name="name" required></label><label>Категория<input name="category" placeholder="Кофе, Выпечка..."></label><label>Цена продажи<input type="number" step="0.01" name="sale_price" required></label><div><button class="btn primary">Добавить</button></div></form></div>
<div class="card section"><table><thead><tr><th>Позиция</th><th>Категория</th><th>Цена</th><th>Себестоимость</th><th>Валовая маржа</th><th></th></tr></thead><tbody><?php foreach($products as $p):$cost=product_cost((int)$p['id']);$margin=(float)$p['sale_price']-$cost;?><tr><td><?=e($p['name'])?></td><td><?=e((string)$p['category'])?></td><td><?=money((float)$p['sale_price'])?></td><td><?=money($cost)?></td><td><?=money($margin)?></td><td><a class="btn" href="recipe.php?id=<?=$p['id']?>">Техкарта</a></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer();?>