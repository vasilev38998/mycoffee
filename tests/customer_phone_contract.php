<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/customer_phone.php';

$cases=[
  ['9011234567','+79011234567'],
  ['8 (901) 123-45-67','+79011234567'],
  ['+7 (901) 123-45-67','+79011234567'],
  ['79011234567','+79011234567'],
];
foreach($cases as [$input,$expected]){$actual=customer_phone_canonical_ru($input);if($actual!==$expected){fwrite(STDERR,"Phone normalization failed: {$input} -> {$actual}\n");exit(1);}}
foreach(['','12345','+1 202 555 0100','790112345678'] as $invalid){try{customer_phone_canonical_ru($invalid);fwrite(STDERR,"Invalid phone accepted: {$invalid}\n");exit(1);}catch(RuntimeException $e){}}
echo "Customer phone contract passed\n";
