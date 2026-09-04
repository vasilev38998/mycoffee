<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/csv_exchange.php';

if(($_GET['export']??'')==='recipes'){
    $stmt=db()->query("SELECT p.id product_id,p.name product_name,p.category,p.sale_price,i.id ingredient_id,i.name ingredient_name,ri.quantity,i.unit
        FROM products p
        LEFT JOIN recipe_items ri ON ri.product_id=p.id
        LEFT JOIN ingredients i ON i.id=ri.ingredient_id
        ORDER BY p.category,p.name,i.name");
    $exportRows=$stmt->fetchAll();
    csv_exchange_send('kapouch-recipes-'.date('Y-m-d').'.csv',
        ['ID товара','Товар','Категория','Цена продажи','ID ингредиента','Ингредиент','Количество','Единица'],
        array_map(static fn($r)=>[$r['product_id'],$r['product_name'],$r['category'],$r['sale_price'],$r['ingredient_id'],$r['ingredient_name'],$r['quantity'],$r['unit']],$exportRows));
}
if(($_GET['export']??'')==='recipes-template'){
    csv_exchange_send('kapouch-recipes-template.csv',
        ['ID товара','Товар','Категория','Цена продажи','ID ингредиента','Ингредиент','Количество','Единица'],
        [
            ['', 'Капучино 350 мл', 'Кофе', '250', '', 'Кофе зерно', '18', 'g'],
            ['', 'Капучино 350 мл', 'Кофе', '250', '', 'Молоко 3.2%', '220', 'ml'],
            ['', 'Капучино 350 мл', 'Кофе', '250', '', 'Стакан 350 мл', '1', 'pcs'],
        ]);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $action=(string)($_POST['action']??'create');
 try{
  if($action==='import_recipes'){
    $importRows=csv_exchange_read_upload('csv_file',[
        'product_id'=>['ID товара','product id','product_id'],
        'product_name'=>['Товар','Продукт','Позиция','product','product name','product_name'],
        'ingredient_id'=>['ID ингредиента','ingredient id','ingredient_id'],
        'ingredient_name'=>['Ингредиент','ingredient','ingredient name','ingredient_name'],
        'quantity'=>['Количество','quantity','qty'],
    ]);
    $productsAll=db()->query('SELECT id,name FROM products ORDER BY id')->fetchAll();
    $ingredientsAll=db()->query('SELECT id,name FROM ingredients ORDER BY id')->fetchAll();
    $productById=[];$productByName=[];$ingredientById=[];$ingredientByName=[];
    foreach($productsAll as $p){$pid=(int)$p['id'];$key=csv_exchange_key((string)$p['name']);$productById[$pid]=(string)$p['name'];$productByName[$key]=array_key_exists($key,$productByName)?0:$pid;}
    foreach($ingredientsAll as $i){$iid=(int)$i['id'];$key=csv_exchange_key((string)$i['name']);$ingredientById[$iid]=(string)$i['name'];$ingredientByName[$key]=array_key_exists($key,$ingredientByName)?0:$iid;}
    $items=[];$listedProducts=[];$errors=[];
    foreach($importRows as $row){
        $pid=0;$pidText=trim((string)$row['product_id']);$productName=trim((string)$row['product_name']);
        if($pidText!==''){
            if(!ctype_digit($pidText)||($pid=(int)$pidText)<=0||!isset($productById[$pid])){$errors[]=['row'=>$row['_row'],'message'=>'товар с ID «'.$pidText.'» не найден.'];continue;}
        }else{
            if($productName===''){$errors[]=['row'=>$row['_row'],'message'=>'не указан товар.'];continue;}
            $match=$productByName[csv_exchange_key($productName)]??null;
            if($match===null){$errors[]=['row'=>$row['_row'],'message'=>'товар «'.$productName.'» не найден. Сначала создайте/синхронизируйте его в меню.'];continue;}
            if($match===0){$errors[]=['row'=>$row['_row'],'message'=>'в меню несколько товаров «'.$productName.'», укажите ID товара.'];continue;}
            $pid=(int)$match;
        }
        $listedProducts[$pid]=true;

        $iidText=trim((string)$row['ingredient_id']);$ingredientName=trim((string)$row['ingredient_name']);$qtyText=trim((string)$row['quantity']);
        if($iidText===''&&$ingredientName===''&&$qtyText==='')continue;
        if($qtyText===''){$errors[]=['row'=>$row['_row'],'message'=>'для ингредиента не указано количество.'];continue;}
        $qty=csv_exchange_number($qtyText);
        if($qty===null||$qty<=0){$errors[]=['row'=>$row['_row'],'message'=>'количество должно быть больше 0.'];continue;}
        $iid=0;
        if($iidText!==''){
            if(!ctype_digit($iidText)||($iid=(int)$iidText)<=0||!isset($ingredientById[$iid])){$errors[]=['row'=>$row['_row'],'message'=>'ингредиент с ID «'.$iidText.'» не найден.'];continue;}
        }else{
            if($ingredientName===''){$errors[]=['row'=>$row['_row'],'message'=>'не указан ингредиент.'];continue;}
            $match=$ingredientByName[csv_exchange_key($ingredientName)]??null;
            if($match===null){$errors[]=['row'=>$row['_row'],'message'=>'ингредиент «'.$ingredientName.'» не найден. Сначала импортируйте его в разделе «Ингредиенты».'];continue;}
            if($match===0){$errors[]=['row'=>$row['_row'],'message'=>'в базе несколько ингредиентов «'.$ingredientName.'», укажите ID ингредиента.'];continue;}
            $iid=(int)$match;
        }
        $items[$pid.':'.$iid]=['product_id'=>$pid,'ingredient_id'=>$iid,'quantity'=>$qty];
    }
    if($errors)throw new RuntimeException(csv_exchange_error_message($errors));
    if(!$listedProducts)throw new RuntimeException('В файле нет товаров для импорта.');

    $replace=isset($_POST['replace_recipes']);$pdo=db();$pdo->beginTransaction();
    try{
        if($replace){$delete=$pdo->prepare('DELETE FROM recipe_items WHERE product_id=?');foreach(array_keys($listedProducts) as $pid)$delete->execute([(int)$pid]);}
        $upsert=$pdo->prepare('INSERT INTO recipe_items(product_id,ingredient_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)');
        foreach($items as $item)$upsert->execute([$item['product_id'],$item['ingredient_id'],$item['quantity']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    audit_write('recipes_csv_imported','Импорт техкарт из CSV: товаров '.count($listedProducts).', строк '.count($items).', режим '.($replace?'замена':'обновление'));
    flash('success','Импорт техкарт завершён. Товаров: '.count($listedProducts).' · ингредиентов в техкартах: '.count($items).($replace?' · техкарты перечисленных товаров заменены.':' · существующие строки сохранены.'));
  }else{
    $name=trim($_POST['name']??'');$category=trim($_POST['category']??'');$price=(float)($_POST['sale_price']??0);
    if($name!==''){$s=db()->prepare('INSERT INTO products(name,category,sale_price) VALUES(?,?,?)');$s->execute([$name,$category,$price]);flash('success','Позиция меню добавлена.');}
  }
 }catch(Throwable $e){flash('danger',$e->getMessage());}
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
<div class="card"><h2>Новая позиция</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="create"><label>Название<input name="name" required></label><label>Категория<input name="category" placeholder="Кофе, Выпечка..."></label><label>Цена продажи<input type="number" step="0.01" name="sale_price" required></label><div><button class="btn primary">Добавить</button></div></form></div>

<div class="card section"><div class="chart-head"><div><h2>Импорт и экспорт техкарт</h2><p>Скачайте текущие техкарты или образец, заполните CSV в Excel и загрузите обратно. Для существующих товаров и ингредиентов лучше не удалять ID.</p></div></div><div class="actions" style="margin-bottom:14px"><a class="btn ghost" href="products.php?export=recipes-template">Скачать образец CSV</a><a class="btn ghost" href="products.php?export=recipes">Экспортировать все техкарты</a></div><form method="post" enctype="multipart/form-data" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="import_recipes"><div class="form-grid"><label>Готовый CSV-файл<input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required></label></div><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="replace_recipes" value="1" checked style="width:auto"> Полностью заменить техкарты товаров, перечисленных в файле</label><div class="muted" style="font-size:12px">Если снять галочку, импорт только добавит/обновит строки, не удаляя старые ингредиенты из техкарт.</div><div><button class="btn primary">Импортировать техкарты</button></div></form><div class="alert warning" style="margin-top:14px"><strong>Порядок работы:</strong> сначала импортируйте ингредиенты, затем техкарты. Товар должен уже существовать в меню. Если в файле есть ошибка, импорт целиком отменяется.</div></div>

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