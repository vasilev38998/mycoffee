<?php
declare(strict_types=1);

// Product media is already validated and normalized on upload. Serving an
// image must not bootstrap the database/migrations: one PWA screen can request
// many images in parallel and those static reads must stay independent of MySQL.
require_once dirname(__DIR__).'/inc/customer_media.php';
require_once dirname(__DIR__).'/inc/customer_media_legacy.php';

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if(!in_array($method,['GET','HEAD'],true)){
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$name=trim((string)($_GET['f']??''));
if($name===''||$name!==basename($name)||!preg_match('/^[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$/i',$name)){
    http_response_code(404);exit;
}
$file=customer_media_existing_file('uploads/products/'.$name);
if($file===null){
    http_response_code(404);exit;
}
$info=@getimagesize($file);$mime=(string)($info['mime']??'');
if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){
    http_response_code(415);exit;
}
$size=(int)filesize($file);$mtime=(int)filemtime($file);$etag='"'.sha1($name.'|'.$size.'|'.$mtime).'"';
header('Content-Type: '.$mime);
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: '.$etag);
header('Last-Modified: '.gmdate('D, d M Y H:i:s',$mtime).' GMT');
header('X-Content-Type-Options: nosniff');

$notModified=trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag;
if(!$notModified&&isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])){
    $since=strtotime((string)$_SERVER['HTTP_IF_MODIFIED_SINCE']);
    $notModified=$since!==false&&$since>=$mtime;
}
if($notModified){http_response_code(304);exit;}

header('Content-Length: '.$size);
if($method==='HEAD')exit;
readfile($file);
