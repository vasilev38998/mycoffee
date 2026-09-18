<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$disclaimer=file_get_contents($root.'/customer/assets/product-disclaimer.js');
$disclaimerCss=file_get_contents($root.'/customer/assets/product-disclaimer.css');
$config=file_get_contents($root.'/customer/config.js');
$sw=file_get_contents($root.'/customer/sw.js');
$choice=file_get_contents($root.'/inc/customer_checkout_loyalty.php');
$orders=file_get_contents($root.'/inc/customer_orders.php');
$sameOrder=file_get_contents($root.'/inc/customer_same_order_gift.php');
$quote=file_get_contents($root.'/api/customer_order_quote.php');
$ui=file_get_contents($root.'/customer/assets/sixth-drink-checkout.js');

$checks=[
    'disclaimer uses requested wording'=>str_contains($disclaimer,'Продукт может отличаться от изображения'),
    'menu and popular product photos receive disclaimer'=>str_contains($disclaimer,".product-card .visual.has-photo")&&str_contains($disclaimer,'product-image-disclaimer'),
    'product detail photo receives disclaimer'=>str_contains($disclaimer,"document.getElementById('productModal')")&&str_contains($disclaimer,"document.getElementById('productImage')")&&str_contains($disclaimer,'product-image-disclaimer detail'),
    'cart product photo receives disclaimer'=>str_contains($disclaimer,".cart-item")&&str_contains($disclaimer,".mini-visual img")&&str_contains($disclaimer,'product-disclaimer-line'),
    'disclaimer is not shown for fallback art'=>str_contains($disclaimer,"image.getAttribute('src')&&!image.hidden")&&str_contains($disclaimer,"hero.querySelector('.product-image-disclaimer.detail')?.remove()"),
    'disclaimer assets are styled'=>str_contains($disclaimerCss,'.product-image-disclaimer')&&str_contains($disclaimerCss,'.product-disclaimer-line'),
    'PWA loads disclaimer assets'=>str_contains($config,'product-disclaimer.css?v=1')&&str_contains($config,'product-disclaimer.js?v=1'),
    'service worker caches disclaimer assets'=>str_contains($sw,"kapouch-pwa-v44")&&str_contains($sw,'./assets/product-disclaimer.css?v=1')&&str_contains($sw,'./assets/product-disclaimer.js?v=1'),
    'checkout mode supports exclusive loyalty benefits'=>str_contains($choice,"['gift','points','none']")&&str_contains($orders,"if(\$loyaltyMode==='gift')")&&str_contains($orders,"elseif(\$loyaltyMode==='points')"),
    'points mode preserves same-order gift'=>str_contains($sameOrder,"customer_checkout_loyalty_mode(\$data)!=='gift'")&&str_contains($ui,'Подарок «6-й напиток» сохранён'),
    'quote exposes gift as an alternative when points selected'=>str_contains($quote,"\$quote['gift_offer']=\$giftOffer")&&str_contains($quote,"\$quote['gift']=null")&&str_contains($quote,"\$requestedSpend=\$mode==='points'"),
    'PWA presents an explicit either-or choice'=>str_contains($ui,'Как использовать лояльность?')&&str_contains($ui,'Можно выбрать только один вариант на заказ')&&str_contains($ui,'data-loyalty-mode="gift"')&&str_contains($ui,'data-loyalty-mode="points"'),
];

foreach($checks as $label=>$ok){
    if(!$ok){fwrite(STDERR,"PWA loyalty/disclaimer contract failed: {$label}\n");exit(1);}
}

echo "PWA LOYALTY CHOICE / PRODUCT DISCLAIMER CONTRACT PASSED\n";