<?php
declare(strict_types=1);

function db_capacity_error(Throwable $e): bool
{
    if(!$e instanceof PDOException)return false;
    $driverCode=(int)($e->errorInfo[1]??0);
    $sqlState=(string)($e->errorInfo[0]??$e->getCode());
    return in_array($driverCode,[1040,1203],true)||in_array($sqlState,['08004','HY000'],true)&&preg_match('/too many connections|max_user_connections/i',$e->getMessage())===1;
}

function db(): PDO
{
    global $config;
    if(($GLOBALS['kapouch_pdo']??null) instanceof PDO)return $GLOBALS['kapouch_pdo'];

    $db=$config['db'];
    $dsn=sprintf('mysql:host=%s;dbname=%s;charset=%s',$db['host'],$db['name'],$db['charset']??'utf8mb4');

    try{
        $GLOBALS['kapouch_pdo']=new PDO($dsn,$db['user'],$db['pass'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>5,
        ]);
    }catch(PDOException $e){
        if(db_capacity_error($e))error_log('[Kapouch DB capacity] '.$e->getMessage());
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
