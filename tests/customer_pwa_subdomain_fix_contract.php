<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/api/customer_catalog.php');
$pwa=file_get_contents($root.'/inc/customer_pwa.php');
$media=file_get_contents($root.'/inc/customer_media.php');
$imageApi=file_get_contents($root.'/api/customer_product_image.php');
$access=file_get_contents($root.'/inc/access.php');
$config=file_get_contents($root.'/customer/config.js');
$mask=file_get_contents($root.'/customer/assets/phone-mask.js');
$sw=file_get_contents($root.'/customer/sw.js');
$order=file_get_contents($root.'/api/customer_order.php');
$authRequest=file_get_contents($root.'/api/customer_auth_request.php');
$authVerify=file_get_contents($root.'/api/customer_auth_verify.php');

$checks=[
  'catalog proxies product images through API origin'=>str_contains($catalog,'customer_product_image.php?f=')&&str_contains($catalog,'customer_media_filename($value)'),
  'PWA image normalization uses shared media helper'=>str_contains($pwa,'customer_media_public_path($path)'),
  'media helper accepts old prefixed paths'=>str_contains($media,"str_starts_with(\$value,'customer/')")&&str_contains($media,'customer_media_filename'),
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
