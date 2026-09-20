<?php
declare(strict_types=1);

final class KapouchDatabaseBusyException extends RuntimeException {}

function db_capacity_cooldown_file(): string
{
    return rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'kapouch_db_capacity_until';
}

function db_capacity_cooldown_remaining(): int
{
    $file=db_capacity_cooldown_file();
    if(!is_file($file))return 0;
    $raw=@file_get_contents($file);
    $until=is_string($raw)?(int)trim($raw):0;
    if($until<=time()){
        @unlink($file);
        return 0;
    }
    return max(1,$until-time());
}

function db_capacity_mark_busy(int $seconds=20): void
{
    $seconds=max(5,min(60,$seconds));
    $GLOBALS['kapouch_db_capacity_active']=true;
    @file_put_contents(db_capacity_cooldown_file(),(string)(time()+$seconds),LOCK_EX);
}

function db_capacity_clear_busy(): void
{
    $GLOBALS['kapouch_db_capacity_active']=false;
    $file=db_capacity_cooldown_file();
    if(is_file($file))@unlink($file);
}

function db_capacity_active(): bool
{
    return !empty($GLOBALS['kapouch_db_capacity_active'])||db_capacity_cooldown_remaining()>0;
}

function db_capacity_error(Throwable $e): bool
{
    if($e instanceof KapouchDatabaseBusyException)return true;
    if(!$e instanceof PDOException)return false;
    $driverCode=(int)($e->errorInfo[1]??0);
    $sqlState=(string)($e->errorInfo[0]??$e->getCode());
    return in_array($driverCode,[1040,1203],true)||in_array($sqlState,['08004','HY000'],true)&&preg_match('/too many connections|max_user_connections/i',$e->getMessage())===1;
}

function db_missing_table_error(Throwable $e): bool
{
    return $e instanceof PDOException && (int)($e->errorInfo[1]??0)===1146;
}

function db_missing_column_error(Throwable $e): bool
{
    return $e instanceof PDOException && (int)($e->errorInfo[1]??0)===1054;
}

function db(): PDO
{
    global $config;
    if(($GLOBALS['kapouch_pdo']??null) instanceof PDO)return $GLOBALS['kapouch_pdo'];

    $remaining=db_capacity_cooldown_remaining();
    if($remaining>0){
        $GLOBALS['kapouch_db_capacity_active']=true;
        throw new KapouchDatabaseBusyException('MySQL capacity cooldown active for '.$remaining.'s');
    }

    $db=$config['db'];
    $dsn=sprintf('mysql:host=%s;dbname=%s;charset=%s',$db['host'],$db['name'],$db['charset']??'utf8mb4');

    try{
        $GLOBALS['kapouch_pdo']=new PDO($dsn,$db['user'],$db['pass'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>5,
        ]);
        db_capacity_clear_busy();
    }catch(PDOException $e){
        if(db_capacity_error($e)){
            db_capacity_mark_busy(20);
            error_log('[Kapouch DB capacity] '.$e->getMessage());
        }
        throw $e;
    }
    return $GLOBALS['kapouch_pdo'];
}

function db_disconnect(): void
{
    $pdo=$GLOBALS['kapouch_pdo']??null;
    if($pdo instanceof PDO&&$pdo->inTransaction())return;
    $GLOBALS['kapouch_pdo']=null;
}
