<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/csv_exchange.php';

$allowedUnits=['g','kg','ml','l','pcs'];

if(($_GET['export']??'')==='ingredients'){
    $exportRows=db()->query('SELECT id,name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity FROM ingredients ORDER BY name')->fetchAll();
    csv_exchange_send('kapouch-ingredients-'.date('Y-m-d').'.csv',
        ['ID','Ингредиент','Единица','Цена закупки','Количество в закупке','Остаток','Минимальный остаток'],
        array_map(static fn($r)=>[$r['id'],$r['name'],$r['unit'],$r['purchase_price'],$r['purchase_quantity'],$r['stock_quantity'],$r['min_stock_quantity']??0],$exportRows));
}
if(($_GET['export']??'')==='ingredients-template'){
    csv_exchange_send('kapouch-ingredients-template.csv',
        ['ID','Ингредиент','Единица','Цена закупки','Количество в закупке','Остаток','Минимальный остаток'],
        [
            ['', 'Кофе зерно', 'g', '1800', '1000', '0', '200'],
            ['', 'Молоко 3.2%', 'ml', '95', '1000', '0', '2000'],
            ['', 'Стакан 350 мл', 'pcs', '4.5', '1', '0', '50'],
        ]);
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=(string)($_POST['action']??'create');
    try{
        if($action==='import_csv'){
            $importRows=csv_exchange_read_upload('csv_file',[
                'id'=>['ID','id'],
                'name'=>['Ингредиент','Название','name'],
                'unit'=>['Единица','Ед.','unit'],
                'purchase_price'=>['Цена закупки','purchase price','purchase_price'],
                'purchase_quantity'=>['Количество в закупке','purchase quantity','purchase_quantity'],
                'stock_quantity'=>['Остаток','stock','stock_quantity'],
                'min_stock_quantity'=>['Минимальный остаток','Мин. остаток','min stock','min_stock_quantity'],
            ]);
            $existing=db()->query('SELECT id,name FROM ingredients ORDER BY id')->fetchAll();
            $byId=[];$byName=[];
            foreach($existing as $item){
                $iid=(int)$item['id'];$key=csv_exchange_key((string)$item['name']);$byId[$iid]=(string)$item['name'];
                $byName[$key]=array_key_exists($key,$byName)?0:$iid;
            }
            $prepared=[];$errors=[];
            foreach($importRows as $row){
                $idText=trim((string)$row['id']);$id=0;
                if($idText!==''){
                    if(!ctype_digit($idText)||($id=(int)$idText)<=0){$errors[]=['row'=>$row['_row'],'message'=>'некорректный ID ингредиента.'];continue;}
                    if(!isset($byId[$id])){$errors[]=['row'=>$row['_row'],'message'=>'ингредиент с ID '.$id.' не найден.'];continue;}
                }
                $name=trim((string)$row['name']);
                $unit=csv_exchange_unit((string)$row['unit']);
                $price=trim((string)$row['purchase_price'])===''?0.0:csv_exchange_number((string)$row['purchase_price']);
                $qty=trim((string)$row['purchase_quantity'])===''?1.0:csv_exchange_number((string)$row['purchase_quantity']);
                $stock=trim((string)$row['stock_quantity'])===''?0.0:csv_exchange_number((string)$row['stock_quantity']);
                $minStock=trim((string)$row['min_stock_quantity'])===''?0.0:csv_exchange_number((string)$row['min_stock_quantity']);
                if($name===''){$errors[]=['row'=>$row['_row'],'message'=>'не указано название ингредиента.'];continue;}
                if($unit===null){$errors[]=['row'=>$row['_row'],'message'=>'неизвестная единица. Используйте g, kg, ml, l или pcs.'];continue;}
                if($price===null||$qty===null||$stock===null||$minStock===null){$errors[]=['row'=>$row['_row'],'message'=>'одно из числовых полей заполнено неверно.'];continue;}
                if($price<0||$qty<0||$stock<0||$minStock<0){$errors[]=['row'=>$row['_row'],'message'=>'цена, количество и остатки не могут быть отрицательными.'];continue;}
                $nameKey=csv_exchange_key($name);
                if($id>0){
                    $nameOwner=$byName[$nameKey]??null;
                    if($nameOwner===0||($nameOwner!==null&&(int)$nameOwner!==$id)){$errors[]=['row'=>$row['_row'],'message'=>'название «'.$name.'» уже используется другим ингредиентом.'];continue;}
                }elseif(($byName[$nameKey]??null)===0){$errors[]=['row'=>$row['_row'],'message'=>'в базе несколько ингредиентов с названием «'.$name.'», нужен ID.'];continue;}
                $prepared[]=['row'=>$row['_row'],'id'=>$id,'name'=>$name,'unit'=>$unit,'price'=>$price,'qty'=>$qty,'stock'=>$stock,'min'=>$minStock];
            }
            if($errors)throw new RuntimeException(csv_exchange_error_message($errors));

            $pdo=db();$pdo->beginTransaction();$created=0;$updated=0;
            try{
                $update=$pdo->prepare('UPDATE ingredients SET name=?,unit=?,purchase_price=?,purchase_quantity=?,stock_quantity=?,min_stock_quantity=? WHERE id=?');
                $insert=$pdo->prepare('INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity) VALUES(?,?,?,?,?,?)');
                foreach($prepared as $item){
                    $targetId=(int)$item['id'];$key=csv_exchange_key($item['name']);
                    if($targetId<=0){$matched=$byName[$key]??null;if(is_int($matched)&&$matched>0)$targetId=$matched;}
                    if($targetId>0){
                        $oldName=$byId[$targetId]??'';$update->execute([$item['name'],$item['unit'],$item['price'],$item['qty'],$item['stock'],$item['min'],$targetId]);$updated++;
                        if($oldName!==''&&csv_exchange_key($oldName)!==$key)unset($byName[csv_exchange_key($oldName)]);
                        $byId[$targetId]=$item['name'];$byName[$key]=$targetId;
                    }else{
                        $insert->execute([$item['name'],$item['unit'],$item['price'],$item['qty'],$item['stock'],$item['min']]);$targetId=(int)$pdo->lastInsertId();$created++;
                        $byId[$targetId]=$item['name'];$byName[$key]=$targetId;
                    }
                }
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            audit_write('ingredients_csv_imported','Импорт ингредиентов из CSV: создано '.$created.', обновлено '.$updated);
            flash('success','Импорт ингредиентов завершён. Создано: '.$created.' · обновлено: '.$updated.'.');
        }elseif($action==='update'){
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

<div class="card section"><div class="chart-head"><div><h2>Импорт и экспорт ингредиентов</h2><p>CSV открывается в Excel. ID позволяет безопасно переименовывать уже существующие ингредиенты; для новых строк ID оставляйте пустым.</p></div></div><div class="actions" style="margin-bottom:14px"><a class="btn ghost" href="ingredients.php?export=ingredients-template">Скачать образец CSV</a><a class="btn ghost" href="ingredients.php?export=ingredients">Экспортировать текущие ингредиенты</a></div><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="import_csv"><label>Готовый CSV-файл<input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required></label><div><button class="btn primary">Импортировать ингредиенты</button></div></form><div class="alert warning" style="margin-top:14px"><strong>Перед массовым импортом</strong> лучше сначала скачать текущий экспорт. Импорт обновляет совпавшие ингредиенты и создаёт новые; при ошибке в строках весь файл отменяется.</div></div>

<div class="card section"><div class="chart-head"><div><h2>Список ингредиентов</h2><p>Карточку можно изменить без удаления ингредиента и без потери связей с техкартами.</p></div></div><table><thead><tr><th>Ингредиент</th><th>Ед.</th><th>Закупка</th><th>Цена за базовую ед.</th><th>Остаток</th><th>Мин. остаток</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=e($r['name'])?></strong></td><td><?=e($r['unit'])?></td><td><?=money((float)$r['purchase_price'])?> / <?=e((string)$r['purchase_quantity'])?></td><td><?=money((float)$r['unit_cost'])?></td><td><?=e((string)$r['stock_quantity'])?></td><td><?=e((string)($r['min_stock_quantity']??0))?></td><td><details><summary class="btn ghost">Редактировать</summary><form method="post" class="stack" style="margin-top:10px;min-width:280px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=$r['id']?>"><label>Название<input name="name" value="<?=e($r['name'])?>" required></label><label>Единица<select name="unit"><?php foreach($allowedUnits as $unit):?><option value="<?=e($unit)?>" <?=$r['unit']===$unit?'selected':''?>><?=e($unit==='pcs'?'шт':$unit)?></option><?php endforeach;?></select></label><label>Цена закупки<input type="number" min="0" step="0.01" name="purchase_price" value="<?=e((string)$r['purchase_price'])?>" required></label><label>Количество в закупке<input type="number" min="0.001" step="0.001" name="purchase_quantity" value="<?=e((string)$r['purchase_quantity'])?>" required></label><label>Текущий остаток<input type="number" min="0" step="0.001" name="stock_quantity" value="<?=e((string)$r['stock_quantity'])?>"></label><label>Минимальный остаток<input type="number" min="0" step="0.001" name="min_stock_quantity" value="<?=e((string)($r['min_stock_quantity']??0))?>"></label><div class="alert warning"><strong>Единица измерения.</strong> Если изменить, например, g на kg или ml на l, количества в существующих техкартах автоматически не пересчитаются. После такого изменения проверь связанные техкарты.</div><button class="btn primary">Сохранить изменения</button></form></details></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>