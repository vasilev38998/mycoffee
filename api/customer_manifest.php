<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_pwa.php';
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-cache');
$s=customer_pwa_settings();
echo json_encode([
    'name'=>$s['app_name'],
    'short_name'=>$s['app_name'],
    'description'=>$s['tagline'],
    'start_url'=>'../customer/',
    'scope'=>'../customer/',
    'display'=>'standalone',
    'orientation'=>'portrait',
    'background_color'=>'#ffd523',
    'theme_color'=>'#ffd523',
    'icons'=>[
        ['src'=>'../customer/assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any'],
    ],
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
