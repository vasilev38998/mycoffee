<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_media.php';

$name=trim((string)($_GET['f']??''));
if($name===''||$name!==basename($name)||!preg_match('/^[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$/i',$name)){
    http_response_code(404);exit;
}
$file=customer_media_root().'/'.$name;
if(!is_file($file)||!is_readable($file)){
    http_response_code(404);exit;
}
$info=@getimagesize($file);$mime=(string)($info['mime']??'');
if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){
    http_response_code(415);exit;
}
$size=(int)filesize($file);$mtime=(int)filemtime($file);$etag='"'.sha1($name.'|'.$size.'|'.$mtime).'"';
header('Content-Type: '.$mime);
header('Content-Length: '.$size);
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: '.$etag);
header('Last-Modified: '.gmdate('D, d M Y H:i:s',$mtime).' GMT');
header('X-Content-Type-Options: nosniff');
if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
readfile($file);
