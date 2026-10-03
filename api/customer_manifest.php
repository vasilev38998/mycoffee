<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_pwa.php';
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-cache');
$s=customer_pwa_settings();
echo json_encode([
    'id'=>'../customer/',
    'name'=>$s['app_name'],
    'short_name'=>$s['app_name'],
    'description'=>$s['tagline'],
    'start_url'=>'../customer/',
    'scope'=>'../customer/',
    'display'=>'standalone',
    'display_override'=>['standalone','minimal-ui'],
    'orientation'=>'portrait',
    'background_color'=>'#f7f1e8',
    'theme_color'=>'#f7f1e8',
    'icons'=>[
        ['src'=>'../customer/assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any maskable'],
    ],
    'shortcuts'=>[
        [
            'name'=>'Открыть меню',
            'short_name'=>'Меню',
            'description'=>'Выбрать напиток в Kapouch',
            'url'=>'../customer/#menu',
            'icons'=>[['src'=>'../customer/assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml']],
        ],
        [
            'name'=>'Открыть корзину',
            'short_name'=>'Корзина',
            'description'=>'Продолжить заказ',
            'url'=>'../customer/#cart',
            'icons'=>[['src'=>'../customer/assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml']],
        ],
        [
            'name'=>'Открыть профиль',
            'short_name'=>'Профиль',
            'description'=>'Бонусы и заказы Kapouch',
            'url'=>'../customer/#profile',
            'icons'=>[['src'=>'../customer/assets/icon.svg?v=2','sizes'=>'any','type'=>'image/svg+xml']],
        ],
    ],
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
