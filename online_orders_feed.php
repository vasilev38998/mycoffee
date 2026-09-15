<?php
declare(strict_types=1);

$filter=(string)($_GET['filter']??'active');
if(!in_array($filter,['active','done','all'],true))$filter='active';

// This endpoint is polled frequently by the order board. A small authenticated
// file cache means most polls do not open MySQL at all, which is important on
// shared hosting with a low max_user_connections quota.
if(session_status()===PHP_SESSION_NONE){
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.cookie_httponly','1');
    ini_set('session.cookie_samesite','Lax');
    $secure=!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off';
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
$sessionUserId=(int)($_SESSION['user_id']??0);
$cacheFile=$sessionUserId>0?sys_get_temp_dir().'/kapouch_orders_feed_'.$sessionUserId.'_'.$filter.'.json':'';
$cacheAge=$cacheFile!==''&&is_file($cacheFile)?time()-(int)filemtime($cacheFile):PHP_INT_MAX;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if($sessionUserId>0&&$cacheAge<=8){
    $cached=@file_get_contents($cacheFile);
    if(is_string($cached)&&$cached!==''){
        header('X-Kapouch-Feed: cache');
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        echo $cached;
        exit;
    }
}

try{
    require __DIR__.'/inc/bootstrap.php';
    require_auth();
    require_once __DIR__.'/inc/online_orders.php';
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();

    $payload=json_encode([
        'ok'=>true,
        'orders'=>online_orders_fetch($filter),
        'server_time'=>date('c'),
        'cached'=>false,
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if($cacheFile!==''){
        $tmp=$cacheFile.'.'.bin2hex(random_bytes(3)).'.tmp';
        if(@file_put_contents($tmp,$payload,LOCK_EX)!==false)@rename($tmp,$cacheFile);
        else @unlink($tmp);
    }
    header('X-Kapouch-Feed: database');
    echo $payload;
}catch(Throwable $e){
    if($cacheFile!==''&&is_file($cacheFile)&&$cacheAge<=120){
        $cached=@file_get_contents($cacheFile);
        if(is_string($cached)&&$cached!==''){
            header('X-Kapouch-Feed: stale');
            header('Retry-After: 15');
            echo $cached;
            exit;
        }
    }
    $capacity=function_exists('db_capacity_error')&&db_capacity_error($e);
    http_response_code($capacity?503:500);
    if($capacity)header('Retry-After: 20');
    error_log('[Kapouch orders feed] '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>$capacity?'Сервер базы временно перегружен.':'Не удалось получить онлайн-заказы.','retry_after'=>$capacity?20:5],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
