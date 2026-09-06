<?php
declare(strict_types=1);
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-cache, max-age=0');
echo json_encode([
  'name'=>'Kapouch',
  'short_name'=>'Kapouch',
  'description'=>'Кофе с собой, заказы и лояльность Kapouch',
  'start_url'=>'../',
  'scope'=>'../',
  'display'=>'standalone',
  'background_color'=>'#111111',
  'theme_color'=>'#111111',
  'icons'=>[
    ['src'=>'../assets/icon.svg','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any maskable']
  ],
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
