<?php
declare(strict_types=1);
require_once __DIR__.'/customer_media.php';

/**
 * Product photos have lived in several directories over the lifetime of the
 * customer PWA. New uploads are written to customer/uploads/products, while
 * older Beget layouts may keep the same files in the main domain root or in
 * the dedicated app.kapouch.store document root. Reads accept all known roots
 * so a code deploy never makes an already uploaded product photo disappear.
 */
function customer_media_read_roots(): array
{
    $publicRoot=dirname(__DIR__);
    $accountRoot=dirname(dirname(dirname(__DIR__)));
    $roots=[
        customer_media_root(),
        $publicRoot.'/uploads/products',
        $publicRoot.'/customer/uploads/products',
        $accountRoot.'/app.kapouch.store/public_html/uploads/products',
        $accountRoot.'/app.kapouch.store/public_html/customer/uploads/products',
    ];
    $out=[];
    foreach($roots as $root){
        $root=rtrim((string)$root,'/');
        if($root!==''&&!in_array($root,$out,true))$out[]=$root;
    }
    return $out;
}

function customer_media_existing_file(?string $path): ?string
{
    $name=customer_media_filename($path);
    if($name===null)return null;
    foreach(customer_media_read_roots() as $root){
        $file=$root.'/'.$name;
        if(is_file($file)&&is_readable($file))return $file;
    }
    return null;
}
