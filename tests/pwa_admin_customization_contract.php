<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$loyalty=file_get_contents($root.'/inc/customer_loyalty.php');
$loyaltyAdmin=file_get_contents($root.'/customer_loyalty_settings.php');
$appAdmin=file_get_contents($root.'/customer_app.php');
$media=file_get_contents($root.'/inc/customer_media.php');
$catalog=file_get_contents($root.'/api/customer_catalog.php');
$polish=file_get_contents($root.'/customer/assets/pwa-polish.js');
$payments=file_get_contents($root.'/customer/assets/payments.js');
$index=file_get_contents($root.'/customer/index.html');
$css=file_get_contents($root.'/customer/assets/pwa-polish.css');
$config=file_get_contents($root.'/customer/config.js');
$sw=file_get_contents($root.'/customer/sw.js');

$checks=[
  'admin can set spend percentage'=>str_contains($loyaltyAdmin,'customer_loyalty_spend_percent')&&str_contains($loyaltyAdmin,'Максимум списания от суммы заказа, %'),
  'server enforces spend percentage'=>str_contains($loyalty,'function customer_loyalty_spend_percent')&&str_contains($loyalty,'customer_loyalty_spend_limit($due)')&&str_contains($loyalty,'min($wanted,$balance,$due,$limit)'),
  'hero form accepts image upload'=>str_contains($appAdmin,'enctype="multipart/form-data"')&&str_contains($appAdmin,'name="customer_hero_image"')&&str_contains($appAdmin,'customer_media_save_hero_upload'),
  'hero can be reset to SVG'=>str_contains($appAdmin,'customer_hero_image_remove')&&str_contains($appAdmin,'вернуть стандартный SVG-стакан'),
  'hero upload preserves proportions'=>str_contains($media,'function customer_media_save_hero_upload')&&str_contains($media,'$ratio=min(1.0,1600/$sw,1200/$sh)')&&!str_contains($media,'hero-crop'),
  'catalog exposes stable hero image'=>str_contains($catalog,"app_setting('customer_hero_image','')")&&str_contains($catalog,"'hero_image'=>\$heroImage"),
  'PWA fits hero media without cropping'=>str_contains($css,'object-fit:contain')&&str_contains($polish,'d.shop?.hero_image'),
  'pickup capacity copy removed at source'=>!str_contains($payments,'свободно')&&str_contains($payments,'<select id="pickupAt">'),
  'pickup cleanup watches real select id'=>str_contains($polish,"const select=\$('pickupAt')")&&!str_contains($polish,"\$('pickupDelay')"),
  'menu uses variant wording with Russian pluralization'=>str_contains($polish,'function variantWord')&&str_contains($polish,"return 'варианта'")&&str_contains($polish,"return 'вариантов'")&&str_contains($index,'Выберите вариант'),
  'fresh polish assets are loaded'=>str_contains($config,'assets/pwa-polish.js?v=3')&&str_contains($index,'config.js?v=12')&&str_contains($index,'payments.js?v=8'),
  'PWA shell is v41'=>str_contains($sw,"kapouch-pwa-v41")&&str_contains($sw,'./assets/pwa-polish.js?v=3')&&str_contains($sw,'./assets/payments.js?v=8'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA admin customization contract failed: {$label}\n");exit(1);}}
echo "PWA ADMIN CUSTOMIZATION CONTRACT PASSED\n";
