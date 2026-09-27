<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$config=file_get_contents($root.'/customer/config.js');
$premium=file_get_contents($root.'/customer/assets/wheel-premium.js');
$css=file_get_contents($root.'/customer/assets/wheel.css');
$checks=[
 'premium wheel layer is loaded'=>str_contains($config,"wheelPremium.src='assets/wheel-premium.js?v=1'")&&str_contains($config,"wheelStyle.href='assets/wheel.css?v=2'"),
 'wheel is rendered as vector SVG'=>str_contains($premium,'buildPremiumWheel')&&str_contains($premium,'viewBox="0 0 400 400"')&&str_contains($premium,'wedgePath')&&str_contains($premium,'rimLights'),
 'prize icons are custom vector drawings'=>str_contains($premium,'function iconMarkup')&&str_contains($premium,"discount:`<g")&&str_contains($premium,"crown:`<g")&&str_contains($premium,"cup:`<g")&&str_contains($premium,"bean:`<g"),
 'premium wheel uses layered gradients and shadow'=>str_contains($premium,'linearGradient')&&str_contains($premium,'radialGradient')&&str_contains($premium,'feDropShadow'),
 'home teaser uses vector mini wheel'=>str_contains($premium,'buildMiniWheel')&&str_contains($premium,'wheel-home-mini'),
 'pointer and spin hub are custom vectors'=>str_contains($premium,'pointerSvg')&&str_contains($premium,'hubIcon'),
 'manual attempts are visibly surfaced'=>str_contains($premium,'manual_attempts')&&str_contains($premium,'Подарочных вращений'),
 'premium layout retains long spin animation'=>str_contains($css,'5.1s cubic-bezier(.12,.78,.12,1)')&&str_contains($css,'.wheel-stage.spinning .wheel-pointer'),
 'premium layout remains reduced-motion friendly'=>str_contains($css,'prefers-reduced-motion'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Wheel visual contract failed: {$label}\n");exit(1);}echo "OK: {$label}\n";}
echo "CUSTOMER WHEEL VISUAL CONTRACT PASSED\n";
