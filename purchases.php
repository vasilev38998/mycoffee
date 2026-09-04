<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

function purchases_table_exists(string $table): bool {
    $s=db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]);return (int)$s->fetchColumn()>0;
}
function purchases_column_exists(string $table,string $column): bool {
    $s=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$table,$column]);return (int)$s->fetchColumn()>0;
}
function purchases_schema(): array {
    return [
        'ingredients'=>purchases_table_exists('ingredients'),
        'purchases'=>purchases_table_exists('purchases'),
        'movements'=>purchases_table_exists('inventory_movements'),
        'suppliers'=>purchases_table_exists('suppliers'),
        'supplier_links'=>purchases_table_exists('supplier_ingredients'),
        'supplier_id'=>purchases_column_exists('purchases','supplier_id'),
        'cash_accounts'=>purchases_table_exists('cash_flow_accounts'),
        'cash_entries'=>purchases_table_exists('cash_flow_entries'),
        'cash_account_id'=>purchases_column_exists('purchases','cash_flow_account_id'),
    ];
}
function purchases_log_error(Throwable $e,string $stage): string {
    $code='PUR-'.strtoupper(substr(hash('sha256',$stage.'|'.$e->getMessage()),0,8));
    error_log('[Kapouch purchases '.$code.'] '.$stage.': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    return $code;
}

$schema=[];$warnings=[];$fatalMessage=null;$ingredients=[];$suppliers=[];$accounts=[];$rows=[];
try {
    $schema=purchases_schema();
    if(!$schema['ingredients']||!$schema['purchases'])throw new RuntimeException('Не найдены базовые таблицы ingredients/purchases. Открой «Обновления» и проверь миграции.');
} catch(Throwable $e) {
    $code=purchases_log_error($e,'schema');$fatalMessage='Не удалось проверить структуру базы. Код '.$code.'.';
}

if(!$fatalMessage && $_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $ingredientId=(int)($_POST['ingredient_id']??0);$quantity=(float)($_POST['quantity']??0);$totalAmount=(float)($_POST['total_amount']??0);$supplierId=(int)($_POST['supplier_id']??0);$cashAccountId=(int)($_POST['cash_flow_account_id']??0);$purchasedAt=(string)($_POST['purchased_at']??date('Y-m-d'));
        if($ingredientId<=0||$quantity<=0||$totalAmount<0)throw new RuntimeException('Проверь ингредиент, количество и сумму закупки.');
        $pdo=db();$pdo->beginTransaction();
        $supplierName='';
        if($supplierId>0&&$schema['suppliers']){$s=$pdo->prepare('SELECT name FROM suppliers WHERE id=? AND active=1');$s->execute([$supplierId]);$supplierName=(string)($s->fetchColumn()?:'');if($supplierName==='')throw new RuntimeException('Поставщик не найден или отключён.');}
        $prev=$pdo->prepare('SELECT total_amount/NULLIF(quantity,0) FROM purchases WHERE ingredient_id=? AND quantity>0 ORDER BY purchased_at DESC,id DESC LIMIT 1');$prev->execute([$ingredientId]);$previousPrice=$prev->fetchColumn();
        $columns=['purchased_at','ingredient_id','quantity','total_amount','supplier'];$values=[$purchasedAt,$ingredientId,$quantity,$totalAmount,$supplierName];
        if($schema['supplier_id']){$columns[]='supplier_id';$values[]=$supplierId?:null;}
        if($schema['cash_account_id']){$columns[]='cash_flow_account_id';$values[]=$cashAccountId?:null;}
        $sql='INSERT INTO purchases('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')';$pdo->prepare($sql)->execute($values);$purchaseId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE ingredients SET stock_quantity=stock_quantity+?,purchase_price=?,purchase_quantity=? WHERE id=?')->execute([$quantity,$totalAmount,$quantity,$ingredientId]);
        if($schema['movements'])$pdo->prepare('INSERT INTO inventory_movements(ingredient_id,movement_type,quantity_delta,reference_type,reference_id,occurred_at,note) VALUES(?,?,?,?,?,?,?)')->execute([$ingredientId,'purchase',$quantity,'purchase',$purchaseId,$purchasedAt.' 12:00:00',$supplierName!==''?'Закупка: '.$supplierName:'Закупка']);
        if($supplierId>0&&$schema['supplier_links'])$pdo->prepare('INSERT INTO supplier_ingredients(supplier_id,ingredient_id,last_price) VALUES(?,?,?) ON DUPLICATE KEY UPDATE last_price=VALUES(last_price),updated_at=CURRENT_TIMESTAMP')->execute([$supplierId,$ingredientId,$totalAmount/$quantity]);
        if($cashAccountId>0&&$totalAmount>0&&$schema['cash_entries']&&$schema['cash_accounts']){
            $u=current_user();$pdo->prepare("INSERT INTO cash_flow_entries(occurred_at,account_id,direction,entry_type,amount,source_type,source_id,category,description,created_by) VALUES(?,?,'out','purchase',?,'purchase',?,'Закупки',?,?)")->execute([$purchasedAt.' 12:00:00',$cashAccountId,$totalAmount,(string)$purchaseId,$supplierName!==''?'Закупка у '.$supplierName:'Закупка ингредиента',$u['id']??null]);
        }
        $pdo->commit();$newPrice=$totalAmount/$quantity;$change=$previousPrice!==false&&(float)$previousPrice>0?($newPrice-(float)$previousPrice)/(float)$previousPrice*100:null;
        audit_write('purchase_created','Добавлена закупка','purchase',(string)$purchaseId,['ingredient_id'=>$ingredientId,'quantity'=>$quantity,'total_amount'=>$totalAmount,'unit_price'=>$newPrice,'price_change_pct'=>$change]);
        flash('success','Закупка сохранена, остаток и закупочная цена обновлены.');
    } catch(Throwable $e) {
        if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();$code=purchases_log_error($e,'save');flash('danger','Не удалось сохранить закупку: '.$e->getMessage().' · код '.$code);
    }
    redirect('purchases.php');
}

if(!$fatalMessage){
    try{$ingredients=db()->query('SELECT id,name,unit,stock_quantity FROM ingredients ORDER BY name')->fetchAll();}catch(Throwable $e){$code=purchases_log_error($e,'ingredients');$fatalMessage='Не удалось загрузить ингредиенты. Код '.$code.'.';}
}
if(!$fatalMessage&&$schema['suppliers']){
    try{$suppliers=db()->query('SELECT id,name FROM suppliers WHERE active=1 ORDER BY name')->fetchAll();}catch(Throwable $e){$warnings[]='Поставщики временно недоступны · '.purchases_log_error($e,'suppliers');}
}else if(!$fatalMessage){$warnings[]='Таблица поставщиков отсутствует. Ручная закупка продолжит работать без поставщика.';}
if(!$fatalMessage&&$schema['cash_accounts']){
    try{$accounts=db()->query("SELECT id,name,account_type FROM cash_flow_accounts WHERE active=1 AND account_type<>'acquiring' ORDER BY name")->fetchAll();}catch(Throwable $e){$warnings[]='Cash Flow временно недоступен · '.purchases_log_error($e,'cash_accounts');}
}else if(!$fatalMessage){$warnings[]='Счета Cash Flow отсутствуют. Закупка продолжит работать без денежного счёта.';}
if(!$fatalMessage){
    try{
        $select="SELECT p.*,i.name ingredient_name,i.unit,(p.total_amount/NULLIF(p.quantity,0)) unit_price";
        $joins=' FROM purchases p JOIN ingredients i ON i.id=p.ingredient_id';
        if($schema['suppliers']&&$schema['supplier_id']){$select.=',COALESCE(s.name,NULLIF(p.supplier,\'\'),\'—\') supplier_name';$joins.=' LEFT JOIN suppliers s ON s.id=p.supplier_id';}else{$select.=',COALESCE(NULLIF(p.supplier,\'\'),\'—\') supplier_name';}
        if($schema['cash_accounts']&&$schema['cash_account_id']){$select.=',a.name cash_account_name';$joins.=' LEFT JOIN cash_flow_accounts a ON a.id=p.cash_flow_account_id';}else{$select.=',NULL cash_account_name';}
        $rows=db()->query($select.$joins.' ORDER BY p.purchased_at DESC,p.id DESC LIMIT 200')->fetchAll();
    }catch(Throwable $e){$code=purchases_log_error($e,'history');$warnings[]='История закупок не загрузилась. Код '.$code.'. Форма новой закупки остаётся доступной.';$rows=[];}
}

$user=current_user();$canReceiptImport=in_array((string)($user['role']??''),['owner','manager'],true);page_header('Закупки');
?>
<?php if($fatalMessage):?><div class="alert danger section"><strong>Раздел закупок обнаружил проблему базы.</strong><br><?=e($fatalMessage)?> Открой раздел «Обновления» и проверь, нет ли незавершённой миграции.</div><?php page_footer();return;endif;?>
<?php foreach($warnings as $warning):?><div class="alert warning section"><?=e($warning)?></div><?php endforeach;?>
<div class="card"><div class="chart-head"><div><h2>Новая закупка</h2><p>Страница сама проверяет фактическую структуру базы и не должна падать HTTP 500 из-за необязательных модулей.</p></div><div class="actions"><?php if($canReceiptImport&&file_exists(__DIR__.'/receipt_import.php')):?><a class="btn primary" href="receipt_import.php">⌁ Сканировать QR-чек</a><?php endif;?><?php if($canReceiptImport&&file_exists(__DIR__.'/receipt_proverkacheka.php')):?><a class="btn ghost" href="receipt_proverkacheka.php">ПроверкаЧека.com</a><?php endif;?><?php if($schema['suppliers']):?><a class="btn ghost" href="suppliers.php">Поставщики</a><?php endif;?></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Дата<input type="date" name="purchased_at" value="<?=date('Y-m-d')?>" required></label><label>Ингредиент<select name="ingredient_id" required><?php foreach($ingredients as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?> — остаток <?=e((string)$i['stock_quantity'])?> <?=e($i['unit'])?></option><?php endforeach;?></select></label><label>Количество<input type="number" step="0.001" min="0.001" name="quantity" required></label><label>Сумма закупки<input type="number" step="0.01" min="0" name="total_amount" required></label><?php if($schema['suppliers']):?><label>Поставщик<select name="supplier_id"><option value="0">Без поставщика</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>"><?=e($s['name'])?></option><?php endforeach;?></select></label><?php endif;?><?php if($schema['cash_accounts']):?><label>Оплачено со счёта<select name="cash_flow_account_id"><option value="0">Не учитывать в Cash Flow</option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach;?></select></label><?php endif;?><div><button class="btn primary">Сохранить закупку</button></div></form></div>
<div class="card section table-card"><div class="chart-head"><div><h2>История закупок</h2><p>Последние 200 операций.</p></div><?php if($schema['suppliers']):?><a class="btn ghost" href="purchase_prices.php">Анализ цен</a><?php endif;?></div><table><thead><tr><th>Дата</th><th>Ингредиент</th><th>Количество</th><th>Сумма</th><th>Цена / ед.</th><th>Поставщик</th><th>Оплачено</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(date('d.m.Y',strtotime($r['purchased_at'])))?></td><td><?=e($r['ingredient_name'])?></td><td><?=e((string)$r['quantity'])?> <?=e($r['unit'])?></td><td><?=money((float)$r['total_amount'])?></td><td><?=money((float)$r['unit_price'])?> / <?=e($r['unit'])?></td><td><?=e($r['supplier_name'])?></td><td><?=e((string)($r['cash_account_name']??'—'))?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="7" class="muted">История пока пуста или временно недоступна.</td></tr><?php endif;?></tbody></table></div>
<?php page_footer(); ?>
