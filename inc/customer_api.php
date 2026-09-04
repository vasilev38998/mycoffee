<?php
declare(strict_types=1);

function customer_api_origin(): string
{
    return trim((string)app_setting('customer_api_allowed_origin',''));
}

function customer_api_headers(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $allowed=customer_api_origin();
    $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
    if($allowed!==''&&$origin!==''&&hash_equals(rtrim($allowed,'/'),rtrim($origin,'/'))){
        header('Access-Control-Allow-Origin: '.$origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
}

function customer_api_guard_origin(): void
{
    $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
    if($origin==='')return;
    $host=(string)($_SERVER['HTTP_HOST']??'');
    $same=false;
    $parts=parse_url($origin);
    if(is_array($parts)&&isset($parts['host'])){
        $originHost=(string)$parts['host'];$originPort=isset($parts['port'])?':'.(int)$parts['port']:'';
        $same=strcasecmp($originHost.$originPort,$host)===0||strcasecmp($originHost,preg_replace('/:\d+$/','',$host)??$host)===0;
    }
    $allowed=customer_api_origin();
    if(!$same&&($allowed===''||!hash_equals(rtrim($allowed,'/'),rtrim($origin,'/'))))customer_api_reply(403,['ok'=>false,'error'=>'Origin not allowed']);
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
    $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('Некорректный JSON.');
    return $data;
}
