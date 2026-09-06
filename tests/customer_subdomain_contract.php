<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$config=file_get_contents($root.'/customer/config.js');
$legal=file_get_contents($root.'/customer/assets/legal.js');
$sw=file_get_contents($root.'/customer/sw.js');
$api=file_get_contents($root.'/inc/customer_api.php');
$urls=file_get_contents($root.'/inc/customer_urls.php');
$migration=file_get_contents($root.'/database/migrations/036_customer_pwa_subdomain.sql');
$manifest=file_get_contents($root.'/customer/api/customer_manifest.php');
$htaccess=file_get_contents($root.'/customer/.htaccess');

$checks=[
  'PWA API points to main domain'=>str_contains($config,"apiBase: 'https://kapouch.store/api'"),
  'PWA public base is app subdomain'=>str_contains($config,"appBase: 'https://app.kapouch.store/'"),
  'legal page uses configured API'=>str_contains($legal,"KAPOUCH_CUSTOMER_CONFIG")&&str_contains($legal,"apiBase+'/customer_legal.php"),
  'API explicitly allows configured app origin'=>str_contains($api,'customer_public_app_origin')&&str_contains($api,'customer_api_is_allowed_origin'),
  'canonical URL helper uses app subdomain'=>str_contains($urls,'https://app.kapouch.store'),
  'migration seeds app and API URLs'=>str_contains($migration,'customer_app_public_url')&&str_contains($migration,'customer_api_public_url')&&str_contains($migration,'customer_api_allowed_origin'),
  'subdomain has same-origin manifest'=>str_contains($manifest,"'start_url'=>'../'")&&str_contains($manifest,"'scope'=>'../'"),
  'service worker supports root scope'=>str_contains($sw,'SCOPE_PATH')&&str_contains($sw,'inAppScope')&&str_contains($sw,"kapouch-pwa-v28"),
  'customer document root disables indexes'=>str_contains($htaccess,'Options -Indexes')&&str_contains($htaccess,'DirectoryIndex index.html'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Customer subdomain contract failed: {$label}\n");exit(1);}}

function app_setting(string $key,string $default=''): string
{
    $values=[
      'customer_app_public_url'=>'https://app.kapouch.store/',
      'customer_api_allowed_origin'=>'https://preview.example https://app.kapouch.store',
    ];
    return $values[$key]??$default;
}
function kapouch_is_https_request(): bool{return true;}
require_once $root.'/inc/customer_api.php';
if(!customer_api_is_allowed_origin('https://app.kapouch.store')){fwrite(STDERR,"Customer subdomain contract failed: app origin rejected\n");exit(1);}
if(customer_api_is_allowed_origin('https://evil.example')){fwrite(STDERR,"Customer subdomain contract failed: foreign origin accepted\n");exit(1);}

echo "Customer subdomain contract passed\n";
