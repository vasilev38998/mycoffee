<?php
declare(strict_types=1);

require __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/evotor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pc_fail(string $message,int $status=400): never
{
    http_response_code($status);
    echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function pc_authorization_header(): string
{
    $candidates=[
        $_SERVER['HTTP_AUTHORIZATION']??'',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'',
        $_SERVER['Authorization']??'',
    ];
    if(function_exists('getallheaders')){
        $headers=getallheaders();
        if(is_array($headers)){
            foreach($headers as $name=>$value){
                if(strcasecmp((string)$name,'Authorization')===0)$candidates[]=(string)$value;
            }
        }
    }
    foreach($candidates as $candidate){$candidate=trim((string)$candidate);if($candidate!=='')return $candidate;}
    return '';
}

function pc_proxy_key(): string
{
    return hash_hmac('sha256','kapouch-proverkacheka-proxy-v1',evotor_crypto_key());
}

if($_SERVER['REQUEST_METHOD']!=='GET')pc_fail('Метод не поддерживается.',405);

$connection=db()->query("SELECT * FROM receipt_data_connections WHERE enabled=1 AND name='ПроверкаЧека.com' ORDER BY id LIMIT 1")->fetch();
if(!$connection)pc_fail('Интеграция ПроверкаЧека.com не настроена.',503);
try{$saved=evotor_decrypt_token($connection);}catch(Throwable $e){pc_fail('Не удалось прочитать сохранённый токен интеграции.',500);}

// Beget/Apache can strip Authorization before PHP-FPM. Prefer a signed internal key
// embedded in the stored endpoint; keep Bearer as a backwards-compatible fallback.
$authorized=false;
$internalKey=trim((string)($_GET['k']??''));
if($internalKey!==''&&hash_equals(pc_proxy_key(),$internalKey))$authorized=true;
if(!$authorized){
    $auth=pc_authorization_header();
    if(preg_match('/^Bearer\s+(.+)$/i',$auth,$m)){
        $bearer=trim((string)$m[1]);
        if($bearer!==''&&hash_equals($saved,$bearer))$authorized=true;
    }
}
if(!$authorized)pc_fail('Внутренняя авторизация источника чеков не прошла.',401);

$fn=preg_replace('/\D+/','',(string)($_GET['fn']??''));
$fd=preg_replace('/\D+/','',(string)($_GET['fd']??''));
$fp=preg_replace('/\D+/','',(string)($_GET['fp']??''));
$t=preg_replace('/[^0-9T]/','',(string)($_GET['t']??''));
$s=str_replace(',','.',trim((string)($_GET['s']??'')));
$n=(int)($_GET['n']??1);
if($fn===''||$fd===''||$fp===''||$t===''||$s==='')pc_fail('Не хватает реквизитов фискального чека.');
if(!in_array($n,[1,2,3,4],true))$n=1;
if(strlen($t)>=15)$t=substr($t,0,13);

$post=[
    'fn'=>$fn,
    'fd'=>$fd,
    'fp'=>$fp,
    't'=>$t,
    'n'=>(string)$n,
    's'=>$s,
    'qr'=>'1',
    'token'=>$saved,
];

$ch=curl_init('https://proverkacheka.com/api/v1/check/get');
curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$post,
    CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_TIMEOUT=>45,
    CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_HTTPHEADER=>['Accept: application/json'],
    CURLOPT_USERAGENT=>'Kapouch/1.0 receipt-import',
]);
$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
if($body===false||$error!=='')pc_fail('Ошибка связи с ПроверкаЧека.com: '.$error,502);
$json=json_decode((string)$body,true);
if($status<200||$status>=300||!is_array($json))pc_fail('ПроверкаЧека.com вернул HTTP '.$status.' или некорректный JSON.',502);
if((int)($json['code']??0)!==1){
    $message=trim((string)($json['message']??$json['data']['message']??$json['error']??''));
    pc_fail($message!==''?'ПроверкаЧека.com: '.$message:'ПроверкаЧека.com не вернул чек. Проверь токен и реквизиты QR.',422);
}
$receipt=$json['data']['json']??null;
if(!is_array($receipt))pc_fail('ПроверкаЧека.com вернул ответ без данных чека.',502);
echo json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
