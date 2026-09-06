<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/api/customer_catalog.php');
$config=file_get_contents($root.'/customer/config.js');
$mask=file_get_contents($root.'/customer/assets/phone-mask.js');
$sw=file_get_contents($root.'/customer/sw.js');
$order=file_get_contents($root.'/api/customer_order.php');
$authRequest=file_get_contents($root.'/api/customer_auth_request.php');
$authVerify=file_get_contents($root.'/api/customer_auth_verify.php');

$checks=[
  'catalog rewrites images to app origin'=>str_contains($catalog,"customer_public_app_origin().'/uploads/products/'")&&str_contains($catalog,'customer_api_request_origin()'),
  'phone mask is loaded'=>str_contains($config,'assets/phone-mask.js?v=1'),
  'phone mask forces +7'=>str_contains($mask,"let out='+7'")&&str_contains($mask,"placeholder='+7 (999) 999-99-99'"),
  'auth request canonicalizes phone'=>str_contains($authRequest,'customer_phone_canonical_ru'),
  'auth verify canonicalizes phone'=>str_contains($authVerify,'customer_phone_canonical_ru'),
  'checkout canonicalizes phone'=>str_contains($order,'$profilePhone=customer_phone_canonical_ru')&&str_contains($order,'$data[\'phone\']=$profilePhone'),
  'service worker caches mask'=>str_contains($sw,'kapouch-pwa-v31')&&str_contains($sw,'./assets/phone-mask.js?v=1'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA subdomain fix contract failed: {$label}\n");exit(1);}}
echo "PWA subdomain fix contract passed\n";
