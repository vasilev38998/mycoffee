<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity = max(0.01, (float)($_POST['quantity'] ?? 1));
    $soldAt = trim($_POST['sold_at'] ?? date('Y-m-d\TH:i'));
    $payment = $_POST['payment_method'] ?? 'card';

    $stmt = db()->prepare('SELECT id, sale_price FROM products WHERE id=? AND active=1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if ($product) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $unitPrice = (float)$product['sale_price'];
            $unitCost = product_cost($productId);
            $total = $unitPrice * $quantity;
            $sale = $pdo->prepare('INSERT INTO sales(sold_at,total_amount,payment_method) VALUES(?,?,?)');
            $sale->execute([str_replace('T', ' ', $soldAt) . (strlen($soldAt) === 16 ? ':00' : ''), $total, $payment]);
            $saleId = (int)$pdo->lastInsertId();
            $item = $pdo->prepare('INSERT INTO sale_items(sale_id,product_id,quantity,unit_price,unit_cost) VALUES(?,?,?,?,?)');
            $item->execute([$saleId, $productId, $quantity, $unitPrice, $unitCost]);
            $pdo->commit();
            flash('success', 'Продажа добавлена. Себестоимость зафиксирована на момент продажи.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('danger', 'Не удалось сохранить продажу.');
        }
    }
    redirect('sales.php');
}

$products = db()->query('SELECT id,name,sale_price FROM products WHERE active=1 ORDER BY name')->fetchAll();
$rows = db()->query("SELECT s.*,p.name,si.quantity,si.unit_price,si.unit_cost FROM sales s JOIN sale_items si ON si.sale_id=s.id JOIN products p ON p.id=si.product_id ORDER BY s.sold_at DESC LIMIT 100")->fetchAll();
page_header('Продажи');
?>
<div class="card"><h2>Добавить продажу</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Позиция<select name="product_id" required><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=e($p['name'])?> — <?=money((float)$p['sale_price'])?></option><?php endforeach;?></select></label><label>Количество<input type="number" step="0.01" min="0.01" name="quantity" value="1" required></label><label>Дата и время<input type="datetime-local" name="sold_at" value="<?=date('Y-m-d\TH:i')?>" required></label><label>Оплата<select name="payment_method"><option value="card">Карта</option><option value="cash">Наличные</option><option value="other">Другое</option></select></label><div><button class="btn primary">Добавить продажу</button></div></form></div>
<div class="card section sales-history"><h2>Последние продажи</h2><table class="mobile-card-table"><thead><tr><th>Дата</th><th>Позиция</th><th>Кол-во</th><th>Выручка</th><th>Себестоимость</th><th>Валовая прибыль</th></tr></thead><tbody><?php foreach($rows as $r):$rev=(float)$r['unit_price']*(float)$r['quantity'];$cost=(float)$r['unit_cost']*(float)$r['quantity'];?><tr><td data-label="Дата"><?=e(date('d.m.Y H:i',strtotime($r['sold_at'])))?></td><td data-label="Позиция"><strong><?=e($r['name'])?></strong></td><td data-label="Кол-во"><?=e((string)$r['quantity'])?></td><td data-label="Выручка"><?=money($rev)?></td><td data-label="Себестоимость"><?=money($cost)?></td><td data-label="Валовая прибыль"><strong><?=money($rev-$cost)?></strong></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>