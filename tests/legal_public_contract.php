<?php
declare(strict_types=1);

$index=file_get_contents(__DIR__.'/../customer/index.html');
$legal=file_get_contents(__DIR__.'/../customer/legal.html');
$js=file_get_contents(__DIR__.'/../customer/assets/legal.js');
$api=file_get_contents(__DIR__.'/../api/customer_legal.php');

$checks=[
    'home footer'=>str_contains($index,'id="homeLegalFooter"'),
    'privacy link on home'=>str_contains($index,'legal.html#privacy'),
    'offer link on home'=>str_contains($index,'legal.html#offer'),
    'seller link on home'=>str_contains($index,'legal.html#seller'),
    'bank link on home'=>str_contains($index,'legal.html#bank'),
    'privacy section'=>str_contains($legal,'id="privacy"')&&str_contains($legal,'id="privacyText"'),
    'privacy renderer'=>str_contains($js,"$('privacyText')")&&str_contains($js,'renderHomeFooter'),
    'privacy API'=>str_contains($api,"customer_privacy_public_data"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Legal public contract failed: {$label}\n");exit(1);}}
echo "Legal public contract passed\n";
