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

$connections = db()->query('SELECT * FROM evotor_connections WHERE enabled=1 ORDER BY id')->fetchAll();
if (!$connections) {
    echo "No enabled Evotor connections.\n";
    exit(0);
}

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

exit($failed ? 1 : 0);
