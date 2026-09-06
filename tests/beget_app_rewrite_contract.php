<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$htaccess=file_get_contents($root.'/.htaccess');
$customerHtaccess=file_get_contents($root.'/customer/.htaccess');
$docs=file_get_contents($root.'/docs/customer-pwa-subdomain.md');

$checks=[
  'app host condition'=>preg_match('/RewriteCond\s+%\{HTTP_HOST\}\s+\^app\\\.kapouch\\\.store/', $htaccess)===1,
  'internal customer rewrite'=>str_contains($htaccess,'RewriteRule ^(.*)$ customer/$1 [L]'),
  'rewrite loop guard'=>str_contains($htaccess,'RewriteCond %{REQUEST_URI} !^/customer(?:/|$) [NC]'),
  'no external redirect in app rule'=>!str_contains($htaccess,'customer/$1 [R='),
  'admin sensitive directories still blocked'=>str_contains($htaccess,'RewriteRule ^(?:inc|database|docs|cron)(?:/|$) - [F,L,NC]'),
  'customer root has no directory listing'=>str_contains($customerHtaccess,'Options -Indexes'),
  'beget instructions attach to existing site'=>str_contains($docs,'прикреплён к тому же сайту')&&str_contains($docs,'kapouch.store/public_html'),
  'beget instructions reject external redirect'=>str_contains($docs,'не нужно настраивать внешний 301/302 редирект'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Beget app rewrite contract failed: {$label}\n");exit(1);}}
echo "Beget app rewrite contract passed\n";
