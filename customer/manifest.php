<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_pwa.php';
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-cache, max-age=0');
$s=customer_pwa_settings();
echo json_encode([
  'name'=>(string)($s['app_name']??'Kapouch'),
  'short_name'=>(string)($s['app_name']??'Kapouch'),
  'description'=>(string)($s['tagline']??'Кофе с собой'),
  'start_url'=>'./',
  'scope'=>'./',
  'display'=>'standalone',
  'background_color'=>(string)($s['background']??'#111111'),
  'theme_color'=>(string)($s['background']??'#111111'),
  'icons'=>[
    ['src'=>'assets/icon.svg','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any maskable']
  ],
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
