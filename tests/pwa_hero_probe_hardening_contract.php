<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$ht=file_get_contents($root.'/.htaccess');
$config=file_get_contents($root.'/customer/config.js');
$index=file_get_contents($root.'/customer/index.html');
$polish=file_get_contents($root.'/customer/assets/pwa-polish.js');
$svg=file_get_contents($root.'/customer/assets/hero-cup.svg');
$sw=file_get_contents($root.'/customer/sw.js');

$checks=[
    'common phpinfo probes are blocked before rewrites'=>str_contains($ht,'phpinfo|info|php-info|phpversion|old_phpinfo|server-info|server-status|debug|test|php|pi|p|i'),
    'sensitive dotfiles and composer probes are blocked'=>str_contains($ht,'\\.env')&&str_contains($ht,'\\.git')&&str_contains($ht,'composer\\.(?:json|lock)'),
    'backup files are denied'=>str_contains($ht,'bak|old|orig|save|swp'),
    'initial hero uses polished SVG asset'=>str_contains($index,'src="assets/hero-cup.svg?v=2"')&&!str_contains($index,'hero-cup-shell'),
    'runtime hero uses polished SVG asset'=>str_contains($polish,'assets/hero-cup.svg?v=2'),
    'hero no longer scans catalog product photos'=>!str_contains($polish,'bestCoffeeImage')&&!str_contains($polish,'img.product-photo'),
    'polish assets are cache-busted'=>str_contains($config,'assets/pwa-polish.css?v=2')&&str_contains($config,'assets/pwa-polish.js?v=2'),
    'SVG has accessible title and no external image/script'=>str_contains($svg,'<title id="title">')&&!preg_match('/<(?:image|script)\b[^>]*(?:href|src)=["\']https?:\/\//i',$svg),
    'SVG renders layered latte and Kapouch branding'=>str_contains($svg,'id="coffee"')&&str_contains($svg,'id="foam"')&&str_contains($svg,'>KAPOUCH</text>'),
    'service worker caches polished SVG hero'=>str_contains($sw,"kapouch-pwa-v39")&&str_contains($sw,'./assets/hero-cup.svg?v=2'),
];
foreach($checks as $label=>$ok){
    if(!$ok){fwrite(STDERR,"PWA hero/probe hardening contract failed: {$label}\n");exit(1);}
}
echo "PWA HERO / PROBE HARDENING CONTRACT PASSED\n";
