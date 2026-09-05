<?php
declare(strict_types=1);

function ensure_settings_tables(): void
{
    static $ready=false;if($ready)return;
    try{db()->query('SELECT setting_key FROM app_settings LIMIT 1');db()->query('SELECT meta_key FROM system_meta LIMIT 1');}
    catch(Throwable $e){$migration=file_get_contents(__DIR__.'/../database/migrations/005_settings.sql');if($migration!==false)db()->exec($migration);}
    try{db()->query('SELECT id FROM notification_settings LIMIT 1');}
    catch(Throwable $e){$migration=file_get_contents(__DIR__.'/../database/migrations/006_kapouch_intelligence.sql');if($migration!==false)db()->exec($migration);}
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
    ensure_settings_tables();$pdo=db();$lockName='kapouch_evotor_time_rebase';$lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Не удалось получить блокировку миграции времени Эвотор.');
    try{
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
