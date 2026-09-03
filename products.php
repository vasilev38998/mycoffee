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

$costFilter=$_GET['cost']??'all';
if(!in_array($costFilter,['all','with','without'],true))$costFilter='all';

$products=db()->query('SELECT * FROM products ORDER BY category,name')->fetchAll();
$withCost=0;$withoutCost=0;
foreach($products as &$product){
    $product['_cost']=product_cost((int)$product['id']);
    if($product['_cost']>0)$withCost++;else $withoutCost++;
}
unset($product);

$filteredProducts=array_values(array_filter($products,function(array $product) use($costFilter): bool{
    if($costFilter==='with')return (float)$product['_cost']>0;
    if($costFilter==='without')return (float)$product['_cost']<=0;
    return true;
}));

page_header('Меню и техкарты');
?>
<div class="card"><h2>Новая позиция</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Название<input name="name" required></label><label>Категория<input name="category" placeholder="Кофе, Выпечка..."></label><label>Цена продажи<input type="number" step="0.01" name="sale_price" required></label><div><button class="btn primary">Добавить</button></div></form></div>

<div class="card section">
<div class="chart-head"><div><h2>Техкарты</h2><p>Фильтр показывает позиции по фактически рассчитанной себестоимости.</p></div></div>
<div class="actions" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
<a class="btn <?=$costFilter==='all'?'primary':''?>" href="products.php?cost=all">Все · <?=count($products)?></a>
<a class="btn <?=$costFilter==='with'?'primary':''?>" href="products.php?cost=with">С себестоимостью · <?=$withCost?></a>
<a class="btn <?=$costFilter==='without'?'primary':''?>" href="products.php?cost=without">Без себестоимости · <?=$withoutCost?></a>
</div>
<?php if($costFilter==='without' && $withoutCost>0):?><div class="alert warning" style="margin-bottom:14px">В этом списке себестоимость равна 0 ₽. Причина может быть в пустой техкарте либо в ингредиентах без закупочной цены/количества.</div><?php endif;?>
<?php if($filteredProducts):?>
<table><thead><tr><th>Позиция</th><th>Категория</th><th>Цена</th><th>Себестоимость</th><th>Статус</th><th>Валовая маржа</th><th></th></tr></thead><tbody><?php foreach($filteredProducts as $p):$cost=(float)$p['_cost'];$margin=(float)$p['sale_price']-$cost;?><tr><td><?=e($p['name'])?></td><td><?=e((string)$p['category'])?></td><td><?=money((float)$p['sale_price'])?></td><td><?=money($cost)?></td><td><span class="pill"><?=$cost>0?'Рассчитана':'Не рассчитана'?></span></td><td><?=money($margin)?></td><td><a class="btn" href="recipe.php?id=<?=$p['id']?>">Техкарта</a></td></tr><?php endforeach;?></tbody></table>
<?php else:?><div class="alert success">В этой группе позиций нет.</div><?php endif;?>
</div>
<?php page_footer();?>