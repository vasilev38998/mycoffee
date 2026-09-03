<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
require_once __DIR__ . '/inc/inventory.php';
ensure_inventory_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'sync_sales') {
            $count = sync_inventory_from_sales($_POST['from'] ?? date('Y-m-01'));
            flash('success', 'Склад синхронизирован с продажами. Создано движений: ' . $count);
        }

        if ($action === 'writeoff') {
            $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
            $qty = (float)($_POST['quantity'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($ingredientId > 0 && $qty > 0) {
                apply_inventory_movement($ingredientId, 'writeoff', -$qty, date('Y-m-d H:i:s'), null, null, $note !== '' ? $note : 'Ручное списание');
                flash('success', 'Списание сохранено.');
            }
        }

        if ($action === 'count') {
            $pdo = db();
            $pdo->beginTransaction();
            $countStmt = $pdo->prepare('INSERT INTO inventory_counts(counted_at,note) VALUES(?,?)');
            $countStmt->execute([date('Y-m-d H:i:s'), trim($_POST['count_note'] ?? '') ?: null]);
            $countId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO inventory_count_items(inventory_count_id,ingredient_id,expected_quantity,actual_quantity,difference_quantity) VALUES(?,?,?,?,?)');
            $moveStmt = $pdo->prepare('INSERT INTO inventory_movements(ingredient_id,movement_type,quantity_delta,reference_type,reference_id,occurred_at,note) VALUES(?,?,?,?,?,?,?)');
            $updateStmt = $pdo->prepare('UPDATE ingredients SET stock_quantity=? WHERE id=?');

            foreach (($_POST['actual'] ?? []) as $ingredientId => $actualRaw) {
                if ($actualRaw === '') continue;
                $ingredientId = (int)$ingredientId;
                $actual = (float)$actualRaw;
                $expected = ingredient_expected_stock($ingredientId);
                $diff = $actual - $expected;
                $itemStmt->execute([$countId,$ingredientId,$expected,$actual,$diff]);
                if (abs($diff) > 0.000001) {
                    $moveStmt->execute([$ingredientId,'inventory_adjustment',$diff,'inventory_count',$countId,date('Y-m-d H:i:s'),'Корректировка по инвентаризации']);
                    $updateStmt->execute([$actual,$ingredientId]);
                }
            }
            $pdo->commit();
            flash('success', 'Инвентаризация сохранена, остатки скорректированы.');
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        flash('danger', 'Ошибка склада: ' . $e->getMessage());
    }
    redirect('inventory.php');
}

$ingredients = db()->query("SELECT *, purchase_price/NULLIF(purchase_quantity,0) unit_cost FROM ingredients ORDER BY name")->fetchAll();
$movements = db()->query("SELECT m.*,i.name,i.unit FROM inventory_movements m JOIN ingredients i ON i.id=m.ingredient_id ORDER BY m.occurred_at DESC,m.id DESC LIMIT 100")->fetchAll();
page_header('Склад');
?>
<div class="grid">
<?php
$totalValue = 0; $low = 0;
foreach($ingredients as $i){$totalValue += (float)$i['stock_quantity']*(float)$i['unit_cost']; if((float)$i['min_stock_quantity']>0 && (float)$i['stock_quantity'] <= (float)$i['min_stock_quantity']) $low++;}
?>
<div class="card metric"><div class="muted">Стоимость остатков</div><div class="value"><?=money($totalValue)?></div></div>
<div class="card metric"><div class="muted">Ингредиентов</div><div class="value"><?=count($ingredients)?></div></div>
<div class="card metric"><div class="muted">Ниже минимума</div><div class="value"><?=$low?></div></div>
</div>

<div class="card section"><h2>Синхронизация с продажами</h2><p class="muted">Каждая продажа списывает ингредиенты согласно техкарте. Возврат Эвотор возвращает соответствующее количество на склад. Повторное списание одного и того же товара защищено от дублей.</p><form method="post" class="actions"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sync_sales"><label>Считать продажи начиная с<input type="date" name="from" value="<?=date('Y-m-01')?>"></label><div><button class="btn primary">Синхронизировать склад</button></div></form></div>

<div class="card section"><h2>Текущие остатки</h2><table><thead><tr><th>Ингредиент</th><th>Остаток</th><th>Минимум</th><th>Стоимость остатка</th></tr></thead><tbody><?php foreach($ingredients as $i):?><tr><td><?=e($i['name'])?></td><td><?=e((string)$i['stock_quantity'])?> <?=e($i['unit'])?></td><td><?=e((string)$i['min_stock_quantity'])?> <?=e($i['unit'])?></td><td><?=money((float)$i['stock_quantity']*(float)$i['unit_cost'])?></td></tr><?php endforeach;?></tbody></table></div>

<div class="card section"><h2>Списание</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="writeoff"><label>Ингредиент<select name="ingredient_id" required><?php foreach($ingredients as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?> — <?=e((string)$i['stock_quantity'])?> <?=e($i['unit'])?></option><?php endforeach;?></select></label><label>Количество<input type="number" step="0.001" min="0.001" name="quantity" required></label><label>Причина<input name="note" placeholder="Пролив, порча, просрочка..."></label><div><button class="btn primary">Списать</button></div></form></div>

<div class="card section"><h2>Инвентаризация</h2><p class="muted">Введите фактический остаток. Система сохранит расхождение и скорректирует склад.</p><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="count"><table><thead><tr><th>Ингредиент</th><th>Расчётный остаток</th><th>Фактический остаток</th></tr></thead><tbody><?php foreach($ingredients as $i):?><tr><td><?=e($i['name'])?></td><td><?=e((string)$i['stock_quantity'])?> <?=e($i['unit'])?></td><td><input type="number" step="0.001" name="actual[<?=$i['id']?>]" placeholder="<?=e((string)$i['stock_quantity'])?>"></td></tr><?php endforeach;?></tbody></table><div class="section form-grid"><label>Комментарий<input name="count_note" placeholder="Еженедельная инвентаризация"></label><div><button class="btn primary">Сохранить инвентаризацию</button></div></div></form></div>

<div class="card section"><h2>Последние движения</h2><table><thead><tr><th>Дата</th><th>Ингредиент</th><th>Тип</th><th>Изменение</th><th>Комментарий</th></tr></thead><tbody><?php foreach($movements as $m):?><tr><td><?=e(date('d.m.Y H:i',strtotime($m['occurred_at'])))?></td><td><?=e($m['name'])?></td><td><?=e($m['movement_type'])?></td><td><?=e((string)$m['quantity_delta'])?> <?=e($m['unit'])?></td><td><?=e((string)$m['note'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>