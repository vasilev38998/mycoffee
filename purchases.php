<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
require_once __DIR__ . '/inc/inventory.php';
ensure_inventory_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);
    $totalAmount = (float)($_POST['total_amount'] ?? 0);
    $supplier = trim($_POST['supplier'] ?? '');
    $purchasedAt = $_POST['purchased_at'] ?? date('Y-m-d');

    if ($ingredientId > 0 && $quantity > 0 && $totalAmount >= 0) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO purchases(purchased_at, ingredient_id, quantity, total_amount, supplier) VALUES(?,?,?,?,?)');
            $stmt->execute([$purchasedAt, $ingredientId, $quantity, $totalAmount, $supplier]);
            $purchaseId = (int)$pdo->lastInsertId();

            $update = $pdo->prepare('UPDATE ingredients SET stock_quantity = stock_quantity + ?, purchase_price = ?, purchase_quantity = ? WHERE id = ?');
            $update->execute([$quantity, $totalAmount, $quantity, $ingredientId]);

            $movement = $pdo->prepare('INSERT INTO inventory_movements(ingredient_id,movement_type,quantity_delta,reference_type,reference_id,occurred_at,note) VALUES(?,?,?,?,?,?,?)');
            $movement->execute([$ingredientId,'purchase',$quantity,'purchase',$purchaseId,$purchasedAt.' 12:00:00',$supplier !== '' ? 'Закупка: '.$supplier : 'Закупка']);

            $pdo->commit();
            flash('success', 'Закупка сохранена, остаток и закупочная цена обновлены.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('danger', 'Не удалось сохранить закупку.');
        }
    }

    redirect('purchases.php');
}

$ingredients = db()->query('SELECT id,name,unit,stock_quantity FROM ingredients ORDER BY name')->fetchAll();
$rows = db()->query('SELECT p.*,i.name ingredient_name,i.unit FROM purchases p JOIN ingredients i ON i.id=p.ingredient_id ORDER BY p.purchased_at DESC,p.id DESC LIMIT 200')->fetchAll();

page_header('Закупки');
?>
<div class="card">
    <h2>Новая закупка</h2>
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
        <label>Поставщик<input name="supplier" placeholder="Необязательно"></label>
        <div><button class="btn primary">Сохранить закупку</button></div>
    </form>
</div>

<div class="card section">
    <h2>История закупок</h2>
    <table><thead><tr><th>Дата</th><th>Ингредиент</th><th>Количество</th><th>Сумма</th><th>Поставщик</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?><tr><td><?=e(date('d.m.Y', strtotime($r['purchased_at'])))?></td><td><?=e($r['ingredient_name'])?></td><td><?=e((string)$r['quantity'])?> <?=e($r['unit'])?></td><td><?=money((float)$r['total_amount'])?></td><td><?=e((string)$r['supplier'])?></td></tr><?php endforeach; ?>
    </tbody></table>
</div>
<?php page_footer(); ?>