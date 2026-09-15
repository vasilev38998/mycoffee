<?php
declare(strict_types=1);

// Shared hosting often hides PHP fatals unless log_errors is explicitly on.
// Keep a minimal shutdown logger registered before database/bootstrap work so
// the next incident leaves a useful trace without exposing it to visitors.
@ini_set('log_errors','1');
if(empty($GLOBALS['kapouch_fatal_logger_registered'])){
    $GLOBALS['kapouch_fatal_logger_registered']=true;
    register_shutdown_function(static function(): void {
        $error=error_get_last();
        if(!$error||!in_array((int)($error['type']??0),[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        $path=(string)(parse_url($uri,PHP_URL_PATH)??'');
        $message=mb_substr((string)($error['message']??'fatal error'),0,1200);
        $file=basename((string)($error['file']??''));
        $line=(int)($error['line']??0);
        error_log('[Kapouch fatal] '.$message.' · '.$file.':'.$line.($path!==''?' · '.$path:''));
    });
}

$configFile=__DIR__.'/../config.php';
if(!file_exists($configFile)){
    if(basename($_SERVER['SCRIPT_NAME']??'')!=='install.php'){header('Location: install.php');exit;}
    return;
}
$config=require $configFile;

date_default_timezone_set('Asia/Irkutsk');
require_once __DIR__.'/db.php';
require_once __DIR__.'/access.php';
require_once __DIR__.'/security.php';

$page=basename($_SERVER['SCRIPT_NAME']??'');
$needsSession=PHP_SAPI!=='cli'&&!in_array($page,kapouch_sessionless_pages(),true);
if($needsSession&&session_status()===PHP_SESSION_NONE){
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.cookie_httponly','1');
    ini_set('session.cookie_samesite','Lax');
    session_set_cookie_params([
        'lifetime'=>0,'path'=>'/','domain'=>'','secure'=>kapouch_is_https_request(),'httponly'=>true,'samesite'=>'Lax',
    ]);
    session_start();
}

require_once __DIR__.'/updater.php';
$GLOBALS['kapouch_update_result']=['applied'=>[],'failed'=>null,'busy'=>false];
$GLOBALS['kapouch_update_error']=null;
try{
    $GLOBALS['kapouch_update_result']=kapouch_apply_pending_migrations(db(),true);
    if(!empty($GLOBALS['kapouch_update_result']['failed'])){
        $failed=$GLOBALS['kapouch_update_result']['failed'];
        $GLOBALS['kapouch_update_error']='Миграция '.(string)($failed['name']??'').' требует ручного повтора: '.(string)($failed['message']??'Ошибка миграции');
    }
}catch(Throwable $e){
    $GLOBALS['kapouch_update_error']=$e->getMessage();
    error_log('[Kapouch migration bootstrap] '.$e->getMessage());
}

require_once __DIR__.'/settings.php';
ensure_settings_tables();
migrate_evotor_times_to_irkutsk_once();
$config['app']['timezone']=app_timezone();
$config['app']['currency']=app_currency();
date_default_timezone_set($config['app']['timezone']);
require_once __DIR__.'/functions.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/audit.php';
require_page_access();
audit_auto_register();
