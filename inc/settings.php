<?php
declare(strict_types=1);

function kapouch_settings_missing_table(Throwable $e): bool
{
    return $e instanceof PDOException && (int)($e->errorInfo[1]??0)===1146;
}

function kapouch_settings_apply_legacy_migration(string $file): void
{
    $migration=file_get_contents(__DIR__.'/../database/migrations/'.$file);
    if($migration===false)throw new RuntimeException('Не удалось прочитать служебную миграцию '.$file.'.');
    db()->exec($migration);
}

function ensure_settings_tables(): void
{
    static $ready=false;if($ready)return;
    try{
        db()->query('SELECT setting_key FROM app_settings LIMIT 1');
        db()->query('SELECT meta_key FROM system_meta LIMIT 1');
    }catch(Throwable $e){
        // A lost/deadlocked database connection must never be mistaken for a
        // missing table: running DDL during a transient failure can amplify an
        // outage. Legacy bootstrap SQL is allowed only for MySQL error 1146.
        if(!kapouch_settings_missing_table($e))throw $e;
        kapouch_settings_apply_legacy_migration('005_settings.sql');
    }
    try{
        db()->query('SELECT id FROM notification_settings LIMIT 1');
    }catch(Throwable $e){
        if(!kapouch_settings_missing_table($e))throw $e;
        kapouch_settings_apply_legacy_migration('006_kapouch_intelligence.sql');
    }
    $ready=true;
}

function app_setting(string $key,mixed $default=null): mixed
{
    ensure_settings_tables();
    if(!isset($GLOBALS['kapouch_app_setting_cache'])||!is_array($GLOBALS['kapouch_app_setting_cache']))$GLOBALS['kapouch_app_setting_cache']=[];
    $cache=&$GLOBALS['kapouch_app_setting_cache'];
    if(array_key_exists($key,$cache))return $cache[$key];
    $stmt=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$stmt->execute([$key]);$value=$stmt->fetchColumn();
    return $cache[$key]=$value===false?$default:$value;
}

function set_app_setting(string $key,string $value): void
{
    ensure_settings_tables();$stmt=db()->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$stmt->execute([$key,$value]);
    if(!isset($GLOBALS['kapouch_app_setting_cache'])||!is_array($GLOBALS['kapouch_app_setting_cache']))$GLOBALS['kapouch_app_setting_cache']=[];
    $GLOBALS['kapouch_app_setting_cache'][$key]=$value;
}

function app_timezone(): string
{
    $timezone=(string)app_setting('timezone','Asia/Irkutsk');try{new DateTimeZone($timezone);return $timezone;}catch(Throwable $e){return 'Asia/Irkutsk';}
}
function app_currency(): string{return (string)app_setting('currency','₽');}

function system_meta(string $key,?string $default=null): ?string
{
    ensure_settings_tables();$stmt=db()->prepare('SELECT meta_value FROM system_meta WHERE meta_key=?');$stmt->execute([$key]);$value=$stmt->fetchColumn();return $value===false?$default:(string)$value;
}
function set_system_meta(string $key,string $value): void
{
    ensure_settings_tables();$stmt=db()->prepare('INSERT INTO system_meta(meta_key,meta_value) VALUES(?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)');$stmt->execute([$key,$value]);
}

function migrate_evotor_times_to_irkutsk_once(): int
{
    static $checked=false;
    if($checked)return 0;
    $checked=true;
    ensure_settings_tables();
    // Almost every request reaches bootstrap. Check the one-time marker before
    // taking any advisory lock, otherwise concurrent requests can queue behind
    // a migration that finished long ago.
    if(system_meta('evotor_time_rebased_to_irkutsk')==='1')return 0;

    $pdo=db();$lockName='kapouch_evotor_time_rebase';$lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)return 0;
    try{
        // Another request may have completed the migration before we acquired
        // the lock, so verify the marker again.
        if(system_meta('evotor_time_rebased_to_irkutsk')==='1')return 0;
        $pdo->beginTransaction();
        try{
            $sales=$pdo->exec("UPDATE sales SET sold_at=DATE_ADD(sold_at, INTERVAL 5 HOUR) WHERE note LIKE 'Импорт Эвотор:%'");
            try{$pdo->exec("UPDATE evotor_documents SET close_date=DATE_ADD(close_date, INTERVAL 5 HOUR) WHERE close_date IS NOT NULL");}catch(Throwable $e){}
            try{$pdo->exec("UPDATE inventory_movements SET occurred_at=DATE_ADD(occurred_at, INTERVAL 5 HOUR) WHERE reference_type='sale_item'");}catch(Throwable $e){}
            $meta=$pdo->prepare('INSERT INTO system_meta(meta_key,meta_value) VALUES(?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)');$meta->execute(['evotor_time_rebased_to_irkutsk','1']);
            $pdo->commit();return (int)$sales;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }finally{
        try{$unlock=$pdo->prepare('SELECT RELEASE_LOCK(?)');$unlock->execute([$lockName]);}catch(Throwable $e){}
    }
}
