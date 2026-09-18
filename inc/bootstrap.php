<?php
declare(strict_types=1);

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

// CLI tests/cron should receive the original throwable and stack trace. On web
// requests handle it once here; rethrowing from an exception handler can call
// the same handler recursively and turn an ordinary exception into a stack
// exhaustion fatal error.
if(PHP_SAPI!=='cli'&&empty($GLOBALS['kapouch_exception_handler_registered'])){
    $GLOBALS['kapouch_exception_handler_registered']=true;
    set_exception_handler(static function(Throwable $e): void {
        $capacity=function_exists('db_capacity_error')&&db_capacity_error($e);
        error_log(($capacity?'[Kapouch DB capacity uncaught] ':'[Kapouch uncaught] ').mb_substr($e->getMessage(),0,1200));

        if(!headers_sent()){
            if($capacity){
                http_response_code(503);
                header('Retry-After: 20');
            }else{
                http_response_code(500);
            }
            header('Cache-Control: no-store');
        }
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        $accept=(string)($_SERVER['HTTP_ACCEPT']??'');
        $json=str_contains($uri,'/api/')||str_contains($uri,'online_orders_feed.php')||str_contains(strtolower($accept),'application/json');
        if($json){
            if(!headers_sent())header('Content-Type: application/json; charset=UTF-8');
            $payload=$capacity
                ? ['ok'=>false,'error'=>'Сервис временно перегружен. Повторите через несколько секунд.','retry_after'=>20]
                : ['ok'=>false,'error'=>'Временная ошибка сервиса. Повторите запрос.'];
            echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            return;
        }

        if(!headers_sent())header('Content-Type: text/html; charset=UTF-8');
        if($capacity){
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kapouch</title><style>body{font-family:system-ui,-apple-system,sans-serif;background:#f6efe7;color:#2d1c15;display:grid;place-items:center;min-height:100vh;margin:0}.box{max-width:520px;padding:32px;text-align:center}.box h1{font-size:28px;margin:0 0 10px}.box p{line-height:1.55;color:#765e52}.box button{margin-top:12px;border:0;border-radius:14px;padding:13px 18px;background:#2d1c15;color:#fff;font-weight:700}</style><div class="box"><h1>Kapouch временно занят</h1><p>Сервер базы данных достиг лимита одновременных подключений. Подождите несколько секунд и повторите.</p><button onclick="location.reload()">Повторить</button></div>';
        }else{
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kapouch</title><style>body{font-family:system-ui,-apple-system,sans-serif;background:#f6efe7;color:#2d1c15;display:grid;place-items:center;min-height:100vh;margin:0}.box{max-width:520px;padding:32px;text-align:center}.box h1{font-size:28px;margin:0 0 10px}.box p{line-height:1.55;color:#765e52}.box button{margin-top:12px;border:0;border-radius:14px;padding:13px 18px;background:#2d1c15;color:#fff;font-weight:700}</style><div class="box"><h1>Kapouch временно недоступен</h1><p>Произошла внутренняя ошибка. Повторите попытку через несколько секунд.</p><button onclick="location.reload()">Повторить</button></div>';
        }
    });
}

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
    if(db_capacity_error($e))throw $e;
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