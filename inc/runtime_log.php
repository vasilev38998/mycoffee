<?php
declare(strict_types=1);

function kapouch_runtime_log_dir(): string
{
    return dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs';
}

function kapouch_runtime_ensure_log_dir(): ?string
{
    $dir=kapouch_runtime_log_dir();
    if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return null;
    return is_writable($dir)?$dir:null;
}

function kapouch_runtime_request_id(): string
{
    static $id=null;
    if(is_string($id)&&$id!=='')return $id;
    $incoming=trim((string)($_SERVER['HTTP_X_REQUEST_ID']??''));
    if($incoming!==''&&preg_match('/^[A-Za-z0-9._:-]{6,80}$/D',$incoming))return $id=$incoming;
    try{$id=bin2hex(random_bytes(8));}catch(Throwable $e){$id=str_replace('.','',uniqid('kap',true));}
    return $id;
}

function kapouch_runtime_redact($value,$key='')
{
    $key=mb_strtolower((string)$key);
    foreach(['password','passwd','pass','secret','token','authorization','cookie','code','phone','email','cipher','iv','tag'] as $needle){
        if($key!==''&&str_contains($key,$needle))return '[redacted]';
    }
    if(is_array($value)){
        $out=[];$count=0;
        foreach($value as $k=>$v){if($count++>=40){$out['_truncated']=true;break;}$out[$k]=kapouch_runtime_redact($v,(string)$k);}
        return $out;
    }
    if(is_object($value))return '[object '.get_class($value).']';
    if(is_resource($value))return '[resource]';
    if(is_string($value))return mb_substr($value,0,500);
    return $value;
}

function kapouch_configure_php_error_log(): void
{
    @ini_set('log_errors','1');
    @ini_set('display_errors','0');
    $dir=kapouch_runtime_ensure_log_dir();
    if($dir===null)return;
    @ini_set('error_log',$dir.DIRECTORY_SEPARATOR.'php_errors-'.date('Y-m-d').'.log');
}

function kapouch_runtime_log(string $channel,string $event,array $context=[]): void
{
    $dir=kapouch_runtime_ensure_log_dir();
    if($dir===null)return;
    $channel=preg_replace('/[^a-z0-9_.-]+/i','_',trim($channel))?:'app';
    $event=preg_replace('/[^a-z0-9_.:-]+/i','_',trim($event))?:'event';
    $uri=(string)($_SERVER['REQUEST_URI']??'');
    $path=(string)(parse_url($uri,PHP_URL_PATH)??'');
    $row=[
        'ts'=>date('c'),
        'channel'=>$channel,
        'event'=>$event,
        'request_id'=>kapouch_runtime_request_id(),
        'pid'=>function_exists('getmypid')?(int)getmypid():0,
        'sapi'=>PHP_SAPI,
        'host'=>mb_substr((string)($_SERVER['HTTP_HOST']??''),0,160),
        'method'=>mb_substr((string)($_SERVER['REQUEST_METHOD']??''),0,16),
        'path'=>mb_substr($path,0,300),
        'memory_mb'=>round(memory_get_usage(true)/1048576,2),
        'context'=>kapouch_runtime_redact($context),
    ];
    $json=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if(!is_string($json))return;
    @file_put_contents($dir.DIRECTORY_SEPARATOR.'runtime-'.date('Y-m-d').'.log',$json.PHP_EOL,FILE_APPEND|LOCK_EX);
}
