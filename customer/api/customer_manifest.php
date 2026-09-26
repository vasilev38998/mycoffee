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
  'background_color'=>'#ffd523',
  'theme_color'=>'#ffd523',
  'icons'=>[
    ['src'=>'../assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any']
  ],
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
