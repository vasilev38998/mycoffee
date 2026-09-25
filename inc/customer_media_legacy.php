<?php
declare(strict_types=1);
require_once __DIR__.'/customer_media.php';

/**
 * Product photos have lived in two directories over the lifetime of the PWA.
 * New uploads are written to customer/uploads/products, but older Beget
 * deployments may still keep their files in /uploads/products. Reads must
 * accept both locations so a deploy never makes existing product photos vanish.
 */
function customer_media_read_roots(): array
{
    $roots=[
        customer_media_root(),
        dirname(__DIR__).'/uploads/products',
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
