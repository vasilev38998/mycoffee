<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/api/customer_catalog.php');
$imageApi=file_get_contents($root.'/api/customer_product_image.php');
$access=file_get_contents($root.'/inc/access.php');
$config=file_get_contents($root.'/customer/config.js');
$mask=file_get_contents($root.'/customer/assets/phone-mask.js');
$sw=file_get_contents($root.'/customer/sw.js');
$order=file_get_contents($root.'/api/customer_order.php');
$authRequest=file_get_contents($root.'/api/customer_auth_request.php');
$authVerify=file_get_contents($root.'/api/customer_auth_verify.php');

$checks=[
  'catalog proxies product images through API origin'=>str_contains($catalog,'customer_product_image.php?f=')&&str_contains($catalog,'customer_media_root()'),
  'image proxy only serves validated upload files'=>str_contains($imageApi,'customer_media_root()')&&str_contains($imageApi,'image/webp')&&str_contains($imageApi,'immutable'),
  'image proxy is public'=>str_contains($access,"'customer_product_image.php'"),
  'phone mask is loaded'=>str_contains($config,'assets/phone-mask.js?v=1'),
  'phone mask forces +7'=>str_contains($mask,"let out='+7'")&&str_contains($mask,"placeholder='+7 (999) 999-99-99'"),
  'auth request canonicalizes phone'=>str_contains($authRequest,'customer_phone_canonical_ru'),
  'auth verify canonicalizes phone'=>str_contains($authVerify,'customer_phone_canonical_ru'),
  'checkout canonicalizes phone'=>str_contains($order,'$profilePhone=customer_phone_canonical_ru')&&str_contains($order,'$data[\'phone\']=$profilePhone'),
  'service worker caches mask'=>str_contains($sw,'kapouch-pwa-v32')&&str_contains($sw,'./assets/phone-mask.js?v=1'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA subdomain fix contract failed: {$label}\n");exit(1);}}
echo "PWA subdomain fix contract passed\n";
