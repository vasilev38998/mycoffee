<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return number_format($value, 2, ',', ' ') . ' ' . app_currency();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Сессия устарела. Обновите страницу и повторите действие.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function product_cost(int $productId): float
{
    $sql = "SELECT COALESCE(SUM(
                ri.quantity * (i.purchase_price / NULLIF(i.purchase_quantity, 0))
            ), 0) AS cost
            FROM recipe_items ri
            JOIN ingredients i ON i.id = ri.ingredient_id
            WHERE ri.product_id = ?";
    $stmt = db()->prepare($sql);
    $stmt->execute([$productId]);
    return (float)($stmt->fetchColumn() ?: 0);
}

function dashboard_metrics(string $from, string $to): array
{
    $pdo = db();

    $salesStmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) revenue, COUNT(*) checks FROM sales WHERE sold_at >= ? AND sold_at < DATE_ADD(?, INTERVAL 1 DAY)');
    $salesStmt->execute([$from . ' 00:00:00', $to]);
    $sales = $salesStmt->fetch();

    $cogsStmt = $pdo->prepare('SELECT COALESCE(SUM(si.quantity * si.unit_cost),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.sold_at >= ? AND s.sold_at < DATE_ADD(?, INTERVAL 1 DAY)');
    $cogsStmt->execute([$from . ' 00:00:00', $to]);
    $cogs = (float)$cogsStmt->fetchColumn();

    $expenseStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_at BETWEEN ? AND ?');
    $expenseStmt->execute([$from, $to]);
    $manualExpenses = (float)$expenseStmt->fetchColumn();

    require_once __DIR__ . '/automatic_expenses.php';
    $automaticExpenses = automatic_expenses_total($from, $to);
    $expenses = $manualExpenses + $automaticExpenses;

    $revenue = (float)$sales['revenue'];
    $checks = (int)$sales['checks'];
    $grossProfit = $revenue - $cogs;
    $operatingProfit = $grossProfit - $expenses;

    return [
        'revenue' => $revenue,
        'checks' => $checks,
        'avg_check' => $checks > 0 ? $revenue / $checks : 0,
        'cogs' => $cogs,
        'gross_profit' => $grossProfit,
        'manual_expenses' => $manualExpenses,
        'automatic_expenses' => $automaticExpenses,
        'expenses' => $expenses,
        'operating_profit' => $operatingProfit,
        'margin' => $revenue > 0 ? ($operatingProfit / $revenue) * 100 : 0,
    ];
}
