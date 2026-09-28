<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_media.php';

customer_api_headers();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if($method!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $customer=customer_auth_require();$customerId=(int)$customer['id'];$pdo=db();
    customer_media_ensure_avatar_schema($pdo);
    $stmt=$pdo->prepare('SELECT avatar_path FROM customer_accounts WHERE id=? LIMIT 1');$stmt->execute([$customerId]);$oldPath=trim((string)($stmt->fetchColumn()?:''));
    $action=trim((string)($_POST['action']??'upload'));
    if($action==='delete'){
        $pdo->prepare('UPDATE customer_accounts SET avatar_path=NULL WHERE id=?')->execute([$customerId]);
        if($oldPath!=='')customer_media_delete($oldPath);
        customer_api_reply(200,['ok'=>true,'avatar_path'=>'','avatar_url'=>'']);
    }
    if($action!=='upload')customer_api_reply(422,['ok'=>false,'error'=>'Неизвестное действие.']);
    if(!isset($_FILES['avatar'])||!is_array($_FILES['avatar']))customer_api_reply(422,['ok'=>false,'error'=>'Выберите фотографию профиля.']);

    // Save the new file first without touching the current avatar. Only delete
    // the old image after the database points at the new one successfully.
    $path=customer_media_save_upload($_FILES['avatar'],'avatar-'.$customerId,null);
    try{
        $pdo->prepare('UPDATE customer_accounts SET avatar_path=? WHERE id=?')->execute([$path,$customerId]);
    }catch(Throwable $e){
        customer_media_delete($path);
        throw $e;
    }
    if($oldPath!==''&&!hash_equals($oldPath,$path))customer_media_delete($oldPath);
    $publicPath=customer_media_public_path($path)??$path;
    customer_api_reply(200,['ok'=>true,'avatar_path'=>$publicPath,'avatar_url'=>$publicPath]);
}catch(RuntimeException $e){
    if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Требуется вход.']);
    customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('[Kapouch customer avatar] '.$e->getMessage());
    customer_api_reply(500,['ok'=>false,'error'=>'Не удалось обновить фотографию профиля.']);
}
