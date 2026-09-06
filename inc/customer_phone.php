<?php
declare(strict_types=1);

function customer_phone_canonical_ru(string $raw): string
{
    $digits=preg_replace('/\D+/','',$raw)??'';
    if(strlen($digits)===10)$digits='7'.$digits;
    elseif(strlen($digits)===11&&$digits[0]==='8')$digits='7'.substr($digits,1);
    if(strlen($digits)!==11||$digits[0]!=='7')throw new RuntimeException('Введите номер в формате +7 (999) 999-99-99.');
    return '+'.$digits;
}
