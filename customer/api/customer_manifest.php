<?php
declare(strict_types=1);
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-cache, max-age=0');
echo json_encode([
  'id'=>'../',
  'name'=>'Kapouch',
  'short_name'=>'Kapouch',
  'description'=>'Кофе с собой, заказы и лояльность Kapouch',
  'start_url'=>'../',
  'scope'=>'../',
  'display'=>'standalone',
  'display_override'=>['standalone','minimal-ui'],
  'orientation'=>'portrait',
  'background_color'=>'#f7f1e8',
  'theme_color'=>'#f7f1e8',
  'icons'=>[
    ['src'=>'../assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any maskable']
  ],
  'shortcuts'=>[
    ['name'=>'Открыть меню','short_name'=>'Меню','url'=>'../#menu','icons'=>[['src'=>'../assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml']]],
    ['name'=>'Открыть корзину','short_name'=>'Корзина','url'=>'../#cart','icons'=>[['src'=>'../assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml']]],
    ['name'=>'Открыть профиль','short_name'=>'Профиль','url'=>'../#profile','icons'=>[['src'=>'../assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml']]],
  ],
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
