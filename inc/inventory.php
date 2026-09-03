<?php
declare(strict_types=1);

function ensure_inventory_tables(): void
{
    $migration = file_get_contents(__DIR__ . '/../database/migrations/004_inventory.sql');
    if ($migration !== false) db()->exec($migration);
}

function apply_inventory_movement(
    int $ingredientId,
    string $movementType,
    float $quantityDelta,
    string $occurredAt,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $note = null
): bool {
    ensure_inventory_tables();
    $pdo = db();

    $pdo->beginTransaction();
    try {
        if ($referenceType !== null && $referenceId !== null) {
            $check = $pdo->prepare('SELECT id FROM inventory_movements WHERE ingredient_id=? AND movement_type=? AND reference_type=? AND reference_id=? LIMIT 1');
            $check->execute([$ingredientId,$movementType,$referenceType,$referenceId]);
            if ($check->fetchColumn()) {
                $pdo->rollBack();
                return false;
            }
        }

        $insert = $pdo->prepare('INSERT INTO inventory_movements(ingredient_id,movement_type,quantity_delta,reference_type,reference_id,occurred_at,note) VALUES(?,?,?,?,?,?,?)');
        $insert->execute([$ingredientId,$movementType,$quantityDelta,$referenceType,$referenceId,$occurredAt,$note]);

        $update = $pdo->prepare('UPDATE ingredients SET stock_quantity = stock_quantity + ? WHERE id=?');
        $update->execute([$quantityDelta,$ingredientId]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function apply_sale_inventory(int $saleId): int
{
    ensure_inventory_tables();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT s.sold_at,si.id sale_item_id,si.product_id,si.quantity FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.sale_id=?');
    $stmt->execute([$saleId]);
    $items = $stmt->fetchAll();
    $count = 0;

    foreach ($items as $item) {
        $recipe = $pdo->prepare('SELECT ingredient_id,quantity FROM recipe_items WHERE product_id=?');
        $recipe->execute([(int)$item['product_id']]);
        foreach ($recipe as $r) {
            $delta = -1 * (float)$item['quantity'] * (float)$r['quantity'];
            $type = $delta < 0 ? 'sale' : 'return';
            if (apply_inventory_movement(
                (int)$r['ingredient_id'],
                $type,
                $delta,
                (string)$item['sold_at'],
                'sale_item',
                (int)$item['sale_item_id'],
                'Автосписание по техкарте'
            )) $count++;
        }
    }
    return $count;
}

function sync_inventory_from_sales(?string $from = null): int
{
    ensure_inventory_tables();
    $sql = 'SELECT DISTINCT s.id FROM sales s JOIN sale_items si ON si.sale_id=s.id';
    $params = [];
    if ($from !== null) {
        $sql .= ' WHERE s.sold_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    $sql .= ' ORDER BY s.id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $count = 0;
    foreach ($stmt as $row) $count += apply_sale_inventory((int)$row['id']);
    return $count;
}

function ingredient_expected_stock(int $ingredientId): float
{
    $stmt = db()->prepare('SELECT stock_quantity FROM ingredients WHERE id=?');
    $stmt->execute([$ingredientId]);
    return (float)($stmt->fetchColumn() ?: 0);
}
