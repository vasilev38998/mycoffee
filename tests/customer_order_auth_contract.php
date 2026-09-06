<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$api=file_get_contents($root.'/api/customer_order.php');
$gate=file_get_contents($root.'/customer/assets/auth-required.js');
$config=file_get_contents($root.'/customer/config.js');
$sw=file_get_contents($root.'/customer/sw.js');

$checks=[
  'API requires current customer'=>str_contains($api,'$customer=customer_auth_current();')&&str_contains($api,"customer_api_reply(401,['ok'=>false,'error'=>'Чтобы оформить заказ"),
  'API binds order phone to profile'=>str_contains($api,"$data['phone']=$profilePhone"),
  'stale malformed profile is rejected'=>str_contains($api,'Номер профиля требует обновления'),
  'PWA blocks guest submit'=>str_contains($gate,"form.addEventListener('submit'")&&str_contains($gate,'event.stopImmediatePropagation()'),
  'PWA sends guest to profile'=>str_contains($gate,"location.hash='#profile'"),
  'PWA explains mandatory login'=>str_contains($gate,'Войдите, чтобы оформить заказ'),
  'config loads checkout gate'=>str_contains($config,'assets/auth-required.js?v=1'),
  'service worker caches gate'=>str_contains($sw,'kapouch-pwa-v30')&&str_contains($sw,'./assets/auth-required.js?v=1'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Customer order auth contract failed: {$label}\n");exit(1);}}
echo "Customer order auth contract passed\n";
