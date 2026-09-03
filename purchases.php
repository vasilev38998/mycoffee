<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
require_once __DIR__ . '/inc/inventory.php';
require_once __DIR__ . '/inc/suppliers.php';
ensure_inventory_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);
    $totalAmount = (float)($_POST['total_amount'] ?? 0);
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchasedAt = $_POST['purchased_at'] ?? date('Y-m-d');

    if ($ingredientId > 0 && $quantity > 0 && $totalAmount >= 0) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $supplierName='';
            if($supplierId>0){$supplierStmt=$pdo->prepare('SELECT name FROM suppliers WHERE id=? AND active=1');$supplierStmt->execute([$supplierId]);$supplierName=(string)($supplierStmt->fetchColumn()?:'');if($supplierName==='')throw new RuntimeException('Поставщик не найден или отключён.');}
            $previousStmt=$pdo->prepare('SELECT total_amount/NULLIF(quantity,0) FROM purchases WHERE ingredient_id=? AND quantity>0 ORDER BY purchased_at DESC,id DESC LIMIT 1');$previousStmt->execute([$ingredientId]);$previousPrice=$previousStmt->fetchColumn();

            $stmt = $pdo->prepare('INSERT INTO purchases(purchased_at, ingredient_id, quantity, total_amount, supplier, supplier_id) VALUES(?,?,?,?,?,?)');
            $stmt->execute([$purchasedAt, $ingredientId, $quantity, $totalAmount, $supplierName, $supplierId?:null]);
            $purchaseId = (int)$pdo->lastInsertId();

            $update = $pdo->prepare('UPDATE ingredients SET stock_quantity = stock_quantity + ?, purchase_price = ?, purchase_quantity = ? WHERE id = ?');
            $update->execute([$quantity, $totalAmount, $quantity, $ingredientId]);

            $movement = $pdo->prepare('INSERT INTO inventory_movements(ingredient_id,movement_type,quantity_delta,reference_type,reference_id,occurred_at,note) VALUES(?,?,?,?,?,?,?)');
            $movement->execute([$ingredientId,'purchase',$quantity,'purchase',$purchaseId,$purchasedAt.' 12:00:00',$supplierName !== '' ? 'Закупка: '.$supplierName : 'Закупка']);

            if($supplierId>0){$link=$pdo->prepare('INSERT INTO supplier_ingredients(supplier_id,ingredient_id,last_price) VALUES(?,?,?) ON DUPLICATE KEY UPDATE last_price=VALUES(last_price),updated_at=CURRENT_TIMESTAMP');$link->execute([$supplierId,$ingredientId,$totalAmount/$quantity]);}

            $pdo->commit();
            $newPrice=$totalAmount/$quantity;$change=$previousPrice!==false&&(float)$previousPrice>0?($newPrice-(float)$previousPrice)/(float)$previousPrice*100:null;
            audit_write('purchase_created','Добавлена закупка','purchase',(string)$purchaseId,['ingredient_id'=>$ingredientId,'quantity'=>$quantity,'total_amount'=>$totalAmount,'supplier_id'=>$supplierId?:null,'unit_price'=>$newPrice,'price_change_pct'=>$change]);
            $message='Закупка сохранена, остаток и закупочная цена обновлены.';
            if($change!==null && $change>=(float)app_setting('purchase_price_warning_pct','10'))$message.=' Цена выросла на '.number_format($change,1,',',' ').'%. Проверь раздел «Закупочные цены».';
            flash('success',$message);
        } catch (Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            flash('danger', 'Не удалось сохранить закупку: '.$e->getMessage());
        }
    }

    redirect('purchases.php');
}

$ingredients = db()->query('SELECT id,name,unit,stock_quantity FROM ingredients ORDER BY name')->fetchAll();
$suppliers = supplier_list(true);
$rows = db()->query("SELECT p.*,i.name ingredient_name,i.unit,COALESCE(s.name,NULLIF(p.supplier,''),'—') supplier_name,(p.total_amount/NULLIF(p.quantity,0)) unit_price FROM purchases p JOIN ingredients i ON i.id=p.ingredient_id LEFT JOIN suppliers s ON s.id=p.supplier_id ORDER BY p.purchased_at DESC,p.id DESC LIMIT 200")->fetchAll();

page_header('Закупки');
?>
<div class="card">
    <div class="chart-head"><div><h2>Новая закупка</h2><p>Каждая закупка теперь участвует в истории цен и аналитике поставщиков.</p></div><a class="btn ghost" href="suppliers.php">Поставщики</a></div>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <label>Дата<input type="date" name="purchased_at" value="<?=date('Y-m-d')?>" required></label>
        <label>Ингредиент
            <select name="ingredient_id" required>
                <?php foreach($ingredients as $i): ?>
                    <option value="<?=$i['id']?>"><?=e($i['name'])?> — остаток <?=e((string)$i['stock_quantity'])?> <?=e($i['unit'])?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Количество<input type="number" step="0.001" min="0.001" name="quantity" required></label>
        <label>Сумма закупки<input type="number" step="0.01" min="0" name="total_amount" required></label>
        <label>Поставщик<select name="supplier_id"><option value="0">Без поставщика</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>"><?=e($s['name'])?></option><?php endforeach;?></select></label>
        <div><button class="btn primary">Сохранить закупку</button></div>
    </form>
    <?php if(!$suppliers):?><div class="alert warning section">Карточек поставщиков пока нет. <a href="suppliers.php"><strong>Создать первого поставщика →</strong></a></div><?php endif;?>
</div>

<div class="card section table-card">
    <div class="chart-head"><div><h2>История закупок</h2><p>Цена за единицу позволяет быстро замечать подорожания.</p></div><a class="btn ghost" href="purchase_prices.php">Анализ цен</a></div>
    <table><thead><tr><th>Дата</th><th>Ингредиент</th><th>Количество</th><th>Сумма</th><th>Цена / ед.</th><th>Поставщик</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?><tr><td><?=e(date('d.m.Y', strtotime($r['purchased_at'])))?></td><td><?=e($r['ingredient_name'])?></td><td><?=e((string)$r['quantity'])?> <?=e($r['unit'])?></td><td><?=money((float)$r['total_amount'])?></td><td><?=money((float)$r['unit_price'])?> / <?=e($r['unit'])?></td><td><?=e($r['supplier_name'])?></td></tr><?php endforeach; ?>
    </tbody></table>
</div>
<?php page_footer(); ?>