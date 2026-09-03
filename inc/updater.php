<?php
declare(strict_types=1);

const KAPOUCH_APP_VERSION = '2026.09.03';
const KAPOUCH_LEGACY_BASELINE_MAX = 8;

function kapouch_migrations_dir(): string
{
    return __DIR__ . '/../database/migrations';
}

function kapouch_migration_files(): array
{
    $files = glob(kapouch_migrations_dir() . '/*.sql') ?: [];
    sort($files, SORT_NATURAL);
    return $files;
}

function kapouch_migration_number(string $file): int
{
    $name = basename($file);
    return preg_match('/^(\d+)_/', $name, $m) ? (int)$m[1] : 0;
}

function kapouch_ensure_migration_registry(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) PRIMARY KEY,
        migration_number INT UNSIGNED NOT NULL,
        checksum CHAR(64) NOT NULL,
        status ENUM('applied','baseline','failed') NOT NULL DEFAULT 'applied',
        execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
        applied_at DATETIME DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        app_version VARCHAR(40) DEFAULT NULL,
        KEY idx_schema_migrations_number (migration_number),
        KEY idx_schema_migrations_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function kapouch_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function kapouch_baseline_legacy_migrations(PDO $pdo): int
{
    kapouch_ensure_migration_registry($pdo);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($count > 0 || !kapouch_table_exists($pdo, 'users')) return 0;

    // Existing Kapouch installations before the updater already applied migrations 002-008
    // through feature-specific ensure_* helpers / install.php. We register them without rerunning SQL.
    $baselined = 0;
    foreach (kapouch_migration_files() as $file) {
        $number = kapouch_migration_number($file);
        if ($number <= 0 || $number > KAPOUCH_LEGACY_BASELINE_MAX) continue;
        $sql = file_get_contents($file);
        if ($sql === false) continue;
        $stmt = $pdo->prepare("INSERT IGNORE INTO schema_migrations(migration,migration_number,checksum,status,execution_ms,applied_at,app_version) VALUES(?,?,?,'baseline',0,NOW(),?)");
        $stmt->execute([basename($file), $number, hash('sha256', $sql), KAPOUCH_APP_VERSION]);
        $baselined += $stmt->rowCount();
    }
    return $baselined;
}

function kapouch_migration_status(PDO $pdo): array
{
    kapouch_ensure_migration_registry($pdo);
    $applied = [];
    foreach ($pdo->query("SELECT * FROM schema_migrations WHERE status IN ('applied','baseline') ORDER BY migration_number,migration")->fetchAll() as $row) {
        $applied[$row['migration']] = $row;
    }

    $pending = [];
    $changed = [];
    foreach (kapouch_migration_files() as $file) {
        $name = basename($file);
        $sql = file_get_contents($file);
        if ($sql === false) continue;
        $checksum = hash('sha256', $sql);
        if (!isset($applied[$name])) {
            $pending[] = ['name'=>$name,'number'=>kapouch_migration_number($file),'file'=>$file,'checksum'=>$checksum];
        } elseif (!hash_equals((string)$applied[$name]['checksum'], $checksum)) {
            $changed[] = ['name'=>$name,'recorded'=>$applied[$name]['checksum'],'current'=>$checksum];
        }
    }

    $latest = 0;
    foreach ($applied as $row) $latest = max($latest, (int)$row['migration_number']);
    $available = 0;
    foreach (kapouch_migration_files() as $file) $available = max($available, kapouch_migration_number($file));

    return ['current_version'=>$latest,'available_version'=>$available,'pending'=>$pending,'changed'=>$changed,'applied'=>$applied];
}

function kapouch_apply_pending_migrations(PDO $pdo): array
{
    kapouch_ensure_migration_registry($pdo);
    kapouch_baseline_legacy_migrations($pdo);
    $status = kapouch_migration_status($pdo);
    if ($status['changed']) {
        throw new RuntimeException('Обнаружено изменение уже применённой миграции: ' . $status['changed'][0]['name'] . '. Обновление остановлено для защиты данных.');
    }

    $result = ['applied'=>[], 'failed'=>null];
    foreach ($status['pending'] as $migration) {
        $sql = file_get_contents($migration['file']);
        if ($sql === false) throw new RuntimeException('Не удалось прочитать миграцию ' . $migration['name']);
        $started = microtime(true);
        try {
            $pdo->exec($sql);
            $ms = (int)round((microtime(true)-$started)*1000);
            $stmt = $pdo->prepare("INSERT INTO schema_migrations(migration,migration_number,checksum,status,execution_ms,applied_at,error_message,app_version) VALUES(?,?,?,'applied',?,NOW(),NULL,?) ON DUPLICATE KEY UPDATE checksum=VALUES(checksum),status='applied',execution_ms=VALUES(execution_ms),applied_at=NOW(),error_message=NULL,app_version=VALUES(app_version)");
            $stmt->execute([$migration['name'],$migration['number'],$migration['checksum'],$ms,KAPOUCH_APP_VERSION]);
            $result['applied'][] = ['name'=>$migration['name'],'number'=>$migration['number'],'execution_ms'=>$ms];
        } catch (Throwable $e) {
            $ms = (int)round((microtime(true)-$started)*1000);
            $stmt = $pdo->prepare("INSERT INTO schema_migrations(migration,migration_number,checksum,status,execution_ms,applied_at,error_message,app_version) VALUES(?,?,?,'failed',?,NULL,?,?) ON DUPLICATE KEY UPDATE checksum=VALUES(checksum),status='failed',execution_ms=VALUES(execution_ms),error_message=VALUES(error_message),app_version=VALUES(app_version)");
            $stmt->execute([$migration['name'],$migration['number'],$migration['checksum'],$ms,mb_substr($e->getMessage(),0,4000),KAPOUCH_APP_VERSION]);
            $result['failed'] = ['name'=>$migration['name'],'message'=>$e->getMessage()];
            throw new RuntimeException('Не удалось применить ' . $migration['name'] . ': ' . $e->getMessage(), 0, $e);
        }
    }
    return $result;
}

function kapouch_update_history(PDO $pdo, int $limit=30): array
{
    kapouch_ensure_migration_registry($pdo);
    $stmt=$pdo->prepare('SELECT * FROM schema_migrations ORDER BY migration_number DESC,migration DESC LIMIT ?');
    $stmt->bindValue(1,$limit,PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
