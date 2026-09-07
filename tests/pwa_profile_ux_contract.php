<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$profile=file_get_contents($root.'/customer/assets/profile-plus.js');
$loyalty=file_get_contents($root.'/customer/assets/loyalty-card.js');
$status=file_get_contents($root.'/customer/assets/status-once.js');
$config=file_get_contents($root.'/customer/config.js');
$sw=file_get_contents($root.'/customer/sw.js');

$checks=[
    'separate profile data dropdown'=>str_contains($profile,"id='profileDataFold'")||str_contains($profile,"'profileDataFold'"),
    'separate profile statistics dropdown'=>str_contains($profile,"id='profileStatsFold'")||str_contains($profile,"'profileStatsFold'"),
    'old combined profile dropdown removed'=>!str_contains($profile,'Данные и статистика'),
    'QR addressed to barista'=>str_contains($loyalty,'Покажи QR бариста'),
    'old scanner copy removed'=>!str_contains($loyalty,'Покажи QR сканеру на кассе'),
    'one-time notice storage'=>str_contains($status,'kapouch_status_strip_seen_v1'),
    'one-time completed notice'=>str_contains($status,'бонусы начислены'),
    'one-time ready notice'=>str_contains($status,'можно\\s+забирать'),
    'one-time notice auto hide'=>str_contains($status,'DISPLAY_MS=8000'),
    'status-once asset loaded'=>str_contains($config,'assets/status-once.js?v=1'),
    'fresh QR asset loaded'=>str_contains($config,'assets/loyalty-card.js?v=5'),
    'service worker cache bumped'=>str_contains($sw,"kapouch-pwa-v32")&&str_contains($sw,'./assets/status-once.js?v=1'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA profile UX contract failed: {$label}\n");exit(1);}}
echo "PWA profile UX contract passed\n";
