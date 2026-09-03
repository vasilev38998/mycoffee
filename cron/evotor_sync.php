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
require_once dirname(__DIR__) . '/inc/cash_register.php';
require_once dirname(__DIR__) . '/inc/automatic_expenses.php';
require_once dirname(__DIR__) . '/inc/inventory.php';
require_once dirname(__DIR__) . '/inc/control.php';
require_once dirname(__DIR__) . '/inc/notifications.php';
ensure_automatic_expense_tables();
ensure_inventory_tables();
ensure_cash_register_tables();
ensure_control_tables();

$connections = db()->query('SELECT * FROM evotor_connections WHERE enabled=1 ORDER BY id')->fetchAll();
$failed = false;

foreach ($connections as $connection) {
    try {
        $result = evotor_run_sync($connection, 'full');
        $cash = sync_evotor_cash_register(evotor_connection((int)$connection['id']) ?? $connection);
        echo sprintf("[%s] %s: processed %d, cash documents %d\n", date('c'), $connection['store_id'], $result['processed'], $cash);
    } catch (Throwable $e) {
        $failed = true;
        fwrite(STDERR, sprintf("[%s] %s: %s\n", date('c'), $connection['store_id'], $e->getMessage()));
    }
}

try {
    $inventoryMovements = sync_inventory_from_sales(date('Y-m-01'));
    echo sprintf("[%s] inventory movements created: %d\n", date('c'), $inventoryMovements);
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, sprintf("[%s] inventory: %s\n", date('c'), $e->getMessage()));
}

try {
    $accruals = refresh_automatic_expenses(date('Y-m-01'), date('Y-m-d'));
    echo sprintf("[%s] automatic expenses refreshed: %d accruals\n", date('c'), $accruals);
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, sprintf("[%s] automatic expenses: %s\n", date('c'), $e->getMessage()));
}

try {
    $alerts = evaluate_business_control();
    echo sprintf("[%s] business control: %d active alerts\n", date('c'), count($alerts));

    if ((string)app_setting('control_telegram_critical','1') === '1') {
        $critical = db()->query("SELECT * FROM control_alerts WHERE severity='critical' AND status<>'resolved' AND (last_notified_at IS NULL OR last_notified_at<DATE_SUB(NOW(),INTERVAL 12 HOUR)) ORDER BY last_seen_at DESC LIMIT 10")->fetchAll();
        $telegram = telegram_notification_settings();
        if ($critical && $telegram && (int)$telegram['enabled']) {
            $lines=[(string)app_setting('coffee_name','Kapouch').' · критичные сигналы'];
            foreach($critical as $a){$lines[]='';$lines[]='⚠ '.$a['title'];$lines[]=$a['message'];if($a['recommendation'])$lines[]='Что сделать: '.$a['recommendation'];}
            send_telegram_message(implode("\n",$lines));
            $ids=array_map('intval',array_column($critical,'id'));
            if($ids)db()->exec('UPDATE control_alerts SET last_notified_at=NOW() WHERE id IN ('.implode(',',$ids).')');
            echo sprintf("[%s] critical control alert sent to Telegram\n", date('c'));
        }
    }
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, sprintf("[%s] business control: %s\n", date('c'), $e->getMessage()));
}

if (!$connections) echo "No enabled Evotor connections. Warehouse, expenses and business control still refreshed.\n";
exit($failed ? 1 : 0);
