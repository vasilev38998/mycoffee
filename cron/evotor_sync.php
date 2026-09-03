<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/inc/bootstrap.php';
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/002_evotor.sql');
if ($migration !== false) db()->exec($migration);
require_once dirname(__DIR__) . '/inc/evotor.php';
require_once dirname(__DIR__) . '/inc/automatic_expenses.php';
ensure_automatic_expense_tables();

$connections = db()->query('SELECT * FROM evotor_connections WHERE enabled=1 ORDER BY id')->fetchAll();
$failed = false;

foreach ($connections as $connection) {
    try {
        $result = evotor_run_sync($connection, 'full');
        echo sprintf("[%s] %s: processed %d\n", date('c'), $connection['store_id'], $result['processed']);
    } catch (Throwable $e) {
        $failed = true;
        fwrite(STDERR, sprintf("[%s] %s: %s\n", date('c'), $connection['store_id'], $e->getMessage()));
    }
}

try {
    $accruals = refresh_automatic_expenses(date('Y-m-01'), date('Y-m-d'));
    echo sprintf("[%s] automatic expenses refreshed: %d accruals\n", date('c'), $accruals);
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, sprintf("[%s] automatic expenses: %s\n", date('c'), $e->getMessage()));
}

if (!$connections) echo "No enabled Evotor connections. Automatic expenses still refreshed.\n";
exit($failed ? 1 : 0);
