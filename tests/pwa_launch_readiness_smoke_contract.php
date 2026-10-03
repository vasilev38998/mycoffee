<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$index=file_get_contents($root.'/customer/index.html');
$sw=file_get_contents($root.'/customer/sw.js');
$css=file_get_contents($root.'/customer/assets/launch-polish.css');
$update=file_get_contents($root.'/customer/assets/pwa-update.js');
$offline=file_get_contents($root.'/customer/assets/offline-resilience.js');

$checks=[
  'launch CSS is wired'=>str_contains($index,'assets/launch-polish.css?v=1'),
  'update controller is wired'=>str_contains($index,'assets/pwa-update.js?v=1'),
  'offline copy remains customer-friendly'=>str_contains($offline,'Нет подключения')&&str_contains($offline,'Повторить'),
  'pinch zoom stays available'=>!str_contains($index,'user-scalable=no')&&!str_contains($index,'maximum-scale=1'),
  'touch targets are hardened'=>str_contains($css,'min-width:44px')&&str_contains($css,'min-height:44px'),
  'new worker is staged, not forced mid-session'=>str_contains($sw,"const CACHE='kapouch-pwa-v60'")&&!str_contains($sw,'precacheShell().then(()=>self.skipWaiting())'),
  'update UI activates waiting worker'=>str_contains($update,"type:'SKIP_WAITING'"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Launch smoke contract failed: {$label}\n");exit(1);}}
echo "PWA LAUNCH SMOKE CONTRACT PASSED\n";
