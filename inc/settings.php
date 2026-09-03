<?php
declare(strict_types=1);

function ensure_settings_tables(): void
{
    $migration = file_get_contents(__DIR__ . '/../database/migrations/005_settings.sql');
    if ($migration !== false) db()->exec($migration);
}

function app_setting(string $key, mixed $default = null): mixed
{
    static $cache = [];
    ensure_settings_tables();
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    if ($value === false) return $cache[$key] = $default;
    return $cache[$key] = $value;
}

function set_app_setting(string $key, string $value): void
{
    ensure_settings_tables();
    $stmt = db()->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->execute([$key,$value]);
}

function app_timezone(): string
{
    $timezone = (string)app_setting('timezone', 'Asia/Irkutsk');
    try { new DateTimeZone($timezone); return $timezone; } catch (Throwable $e) { return 'Asia/Irkutsk'; }
}

function app_currency(): string
{
    return (string)app_setting('currency', '₽');
}

function system_meta(string $key, ?string $default = null): ?string
{
    ensure_settings_tables();
    $stmt = db()->prepare('SELECT meta_value FROM system_meta WHERE meta_key=?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function set_system_meta(string $key, string $value): void
{
    ensure_settings_tables();
    $stmt = db()->prepare('INSERT INTO system_meta(meta_key,meta_value) VALUES(?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)');
    $stmt->execute([$key,$value]);
}

function migrate_evotor_times_to_irkutsk_once(): int
{
    if (system_meta('evotor_time_rebased_to_irkutsk') === '1') return 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sales = $pdo->exec("UPDATE sales SET sold_at=DATE_ADD(sold_at, INTERVAL 5 HOUR) WHERE note LIKE 'Импорт Эвотор:%'");
        try {
            $pdo->exec("UPDATE evotor_documents SET close_date=DATE_ADD(close_date, INTERVAL 5 HOUR) WHERE close_date IS NOT NULL");
        } catch (Throwable $e) {
            // Интеграция может ещё не быть установлена.
        }
        set_system_meta('evotor_time_rebased_to_irkutsk','1');
        $pdo->commit();
        return (int)$sales;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
