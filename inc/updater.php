<?php
declare(strict_types=1);

const KAPOUCH_APP_VERSION = '2026.09.05';
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

function kapouch_normalize_sql_line_endings(string $sql): string
{
    return str_replace(["\r\n", "\r"], "\n", $sql);
}

function kapouch_migration_checksum(string $sql): string
{
    return hash('sha256', kapouch_normalize_sql_line_endings($sql));
}

function kapouch_migration_checksum_matches(string $recorded, string $sql): bool
{
    $normalized = kapouch_normalize_sql_line_endings($sql);
    $candidates = array_unique([
        hash('sha256', $sql),
        hash('sha256', $normalized),
        hash('sha256', str_replace("\n", "\r\n", $normalized)),
    ]);
    foreach ($candidates as $candidate) {
        if (hash_equals($recorded, $candidate)) return true;
    }
    return false;
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

function kapouch_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table,$column]);
    return (int)$stmt->fetchColumn() > 0;
}

function kapouch_legacy_migration_present(PDO $pdo, int $number): bool
{
    return match($number) {
        2 => kapouch_table_exists($pdo,'evotor_connections') && kapouch_table_exists($pdo,'evotor_documents'),
        3 => kapouch_table_exists($pdo,'automatic_expense_rules') && kapouch_table_exists($pdo,'automatic_expense_accruals'),
        4 => kapouch_table_exists($pdo,'inventory_movements') && kapouch_table_exists($pdo,'inventory_counts'),
        5 => kapouch_table_exists($pdo,'app_settings') && kapouch_table_exists($pdo,'system_meta'),
        6 => kapouch_table_exists($pdo,'notification_settings'),
        7 => kapouch_table_exists($pdo,'cash_register_documents') && kapouch_column_exists($pdo,'evotor_connections','last_cash_sync_ms'),
        8 => kapouch_table_exists($pdo,'control_alerts'),
        default => false,
    };
}

function kapouch_baseline_legacy_migrations(PDO $pdo): int
{
    kapouch_ensure_migration_registry($pdo);
    if (!kapouch_table_exists($pdo, 'users')) return 0;
    $baselined = 0;
    foreach (kapouch_migration_files() as $file) {
        $number = kapouch_migration_number($file);
        if ($number <= 0 || $number > KAPOUCH_LEGACY_BASELINE_MAX || !kapouch_legacy_migration_present($pdo,$number)) continue;
        $sql = file_get_contents($file);
        if ($sql === false) continue;
        $stmt = $pdo->prepare("INSERT IGNORE INTO schema_migrations(migration,migration_number,checksum,status,execution_ms,applied_at,app_version) VALUES(?,?,?,'baseline',0,NOW(),?)");
        $stmt->execute([basename($file), $number, kapouch_migration_checksum($sql), KAPOUCH_APP_VERSION]);
        $baselined += $stmt->rowCount();
    }
    return $baselined;
}

function kapouch_migration_status(PDO $pdo): array
{
    kapouch_ensure_migration_registry($pdo);
    $applied = [];
    foreach ($pdo->query("SELECT * FROM schema_migrations WHERE status IN ('applied','baseline') ORDER BY migration_number,migration")->fetchAll() as $row) $applied[$row['migration']] = $row;
    $pending = [];$changed = [];
    foreach (kapouch_migration_files() as $file) {
        $name = basename($file);$sql = file_get_contents($file);if ($sql === false) continue;$checksum = kapouch_migration_checksum($sql);
        if (!isset($applied[$name])) $pending[] = ['name'=>$name,'number'=>kapouch_migration_number($file),'file'=>$file,'checksum'=>$checksum];
        elseif (!kapouch_migration_checksum_matches((string)$applied[$name]['checksum'], $sql)) $changed[] = ['name'=>$name,'recorded'=>$applied[$name]['checksum'],'current'=>$checksum];
    }
    $latest=0;foreach($applied as $row)$latest=max($latest,(int)$row['migration_number']);$available=0;foreach(kapouch_migration_files() as $file)$available=max($available,kapouch_migration_number($file));
    return ['current_version'=>$latest,'available_version'=>$available,'pending'=>$pending,'changed'=>$changed,'applied'=>$applied];
}

function kapouch_apply_pending_migrations(PDO $pdo, bool $baselineLegacy=true): array
{
    $lockName='kapouch_schema_migrations';$lock=$pdo->prepare('SELECT GET_LOCK(?,30)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Другая копия Kapouch уже обновляет базу. Повторите запрос через несколько секунд.');
    try{
        kapouch_ensure_migration_registry($pdo);
        if ($baselineLegacy) kapouch_baseline_legacy_migrations($pdo);
        $status = kapouch_migration_status($pdo);
        if ($status['changed']) throw new RuntimeException('Обнаружено изменение уже применённой миграции: ' . $status['changed'][0]['name'] . '. Обновление остановлено для защиты данных.');
        $result = ['applied'=>[], 'failed'=>null];
        foreach ($status['pending'] as $migration) {
            $sql = file_get_contents($migration['file']);
            if ($sql === false) throw new RuntimeException('Не удалось прочитать миграцию ' . $migration['name']);
            $started = microtime(true);
            try {
                $pdo->exec($sql);$ms = (int)round((microtime(true)-$started)*1000);
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
    } finally {
        try{$unlock=$pdo->prepare('SELECT RELEASE_LOCK(?)');$unlock->execute([$lockName]);}catch(Throwable $e){}
    }
}

function kapouch_update_history(PDO $pdo, int $limit=30): array
{
    kapouch_ensure_migration_registry($pdo);
    $stmt=$pdo->prepare('SELECT * FROM schema_migrations ORDER BY migration_number DESC,migration DESC LIMIT ?');
    $stmt->bindValue(1,$limit,PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll();
}
