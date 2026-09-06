<?php
declare(strict_types=1);

function customer_url_normalize_origin(string $value,string $fallback): string
{
    $value=trim($value);if($value==='')$value=$fallback;
    if(!filter_var($value,FILTER_VALIDATE_URL))$value=$fallback;
    $parts=parse_url($value);
    if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host']))$value=$fallback;
    return rtrim($value,'/');
}

function customer_public_app_origin(): string
{
    return customer_url_normalize_origin((string)app_setting('customer_app_public_url','https://app.kapouch.store/'),'https://app.kapouch.store');
}

function customer_public_app_url(string $path=''): string
{
    $base=customer_public_app_origin().'/';
    $path=ltrim($path,'/');
    return $path===''?$base:$base.$path;
}

function customer_public_api_base(): string
{
    return customer_url_normalize_origin((string)app_setting('customer_api_public_url','https://kapouch.store/api/'),'https://kapouch.store/api');
}
