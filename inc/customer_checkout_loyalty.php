<?php
declare(strict_types=1);

function customer_checkout_loyalty_mode(array $data): string
{
    $mode=mb_strtolower(trim((string)($data['loyalty_mode']??'')));
    if(in_array($mode,['gift','points','none'],true))return $mode;

    $requested=(float)($data['loyalty_spend']??0);
    return $requested>0?'points':'gift';
}
