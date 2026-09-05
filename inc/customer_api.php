<?php
declare(strict_types=1);

function customer_api_origin(): string
{
    return rtrim(trim((string)app_setting('customer_api_allowed_origin','')),'/');
}

function customer_api_request_origin(): string
{
    return rtrim(trim((string)($_SERVER['HTTP_ORIGIN']??'')),'/');
}

function customer_api_server_origin(): string
{
    $host=preg_replace('/[^A-Za-z0-9.:[\]-]/','',(string)($_SERVER['HTTP_HOST']??''));
    if($host==='')return '';
    $forwarded=mb_strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??''));
    $scheme=in_array($forwarded,['http','https'],true)?$forwarded:(kapouch_is_https_request()?'https':'http');
    return $scheme.'://'.$host;
}

function customer_api_headers(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $allowed=customer_api_origin();$origin=customer_api_request_origin();
    if($allowed!==''&&$origin!==''&&hash_equals($allowed,$origin)){
        header('Access-Control-Allow-Origin: '.$origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Customer-Token');
        header('Access-Control-Max-Age: 600');
    }
}

function customer_api_guard_origin(): void
{
    $origin=customer_api_request_origin();if($origin==='')return;
    $same=customer_api_server_origin();$allowed=customer_api_origin();
    if(($same===''||!hash_equals(mb_strtolower($same),mb_strtolower($origin)))&&($allowed===''||!hash_equals($allowed,$origin)))customer_api_reply(403,['ok'=>false,'error'=>'Origin not allowed']);
}

function customer_api_reply(int $status,array $payload): never
{
    customer_api_headers();http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function customer_api_json(): array
{
    $raw=file_get_contents('php://input');
    if($raw===false||trim($raw)==='')throw new RuntimeException('Пустой запрос.');
    if(strlen($raw)>1048576)throw new RuntimeException('Слишком большой запрос.');
    $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('Некорректный JSON.');
    return $data;
}
