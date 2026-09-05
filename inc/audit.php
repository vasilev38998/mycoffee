<?php
declare(strict_types=1);

function audit_sensitive_key(string $key): bool{
    $key=mb_strtolower($key);foreach(['password','passphrase','token','secret','api_id','api_key','authorization','cookie','csrf','encryption_key','private_key','credential'] as $needle)if(str_contains($key,$needle))return true;return false;
}
function audit_sanitize(array $data,int $depth=0): array{
    if($depth>=4)return ['_truncated'=>'[слишком глубокая структура]'];
    $out=[];$seen=0;
    foreach($data as $k=>$v){
        if(++$seen>200){$out['_truncated']='[остальные поля скрыты]';break;}
        if(audit_sensitive_key((string)$k)){$out[$k]='[скрыто]';continue;}
        if(is_array($v)){$out[$k]=audit_sanitize($v,$depth+1);continue;}
        if(is_scalar($v)||$v===null)$out[$k]=mb_substr((string)$v,0,500);else $out[$k]='[сложное значение]';
    }
    return $out;
}
function audit_safe_request_path(): ?string{
    $uri=(string)($_SERVER['REQUEST_URI']??'');if($uri==='')return null;$parts=parse_url($uri);$path=(string)($parts['path']??'');return mb_substr($path!==''?$path:$uri,0,255);
}
function audit_write(string $action,?string $description=null,?string $entityType=null,?string $entityId=null,array $context=[]): void{
    try{$u=current_user();$stmt=db()->prepare('INSERT INTO audit_log(user_id,user_name,user_role,action,entity_type,entity_id,request_method,request_path,description,context_json,ip_address) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$safe=$context?json_encode(audit_sanitize($context),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;if(is_string($safe)&&strlen($safe)>65535)$safe=json_encode(['_truncated'=>'[контекст слишком большой]'],JSON_UNESCAPED_UNICODE);$stmt->execute([$u['id']??null,$u['name']??null,$u['role']??null,mb_substr($action,0,80),$entityType?mb_substr($entityType,0,80):null,$entityId?mb_substr($entityId,0,190):null,$_SERVER['REQUEST_METHOD']??null,audit_safe_request_path(),$description?mb_substr($description,0,255):null,$safe,mb_substr($_SERVER['REMOTE_ADDR']??'',0,64)]);}catch(Throwable $e){}
}
function audit_auto_register(): void{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!current_user())return;
    $page=basename($_SERVER['SCRIPT_NAME']??'unknown');$payload=audit_sanitize($_POST);
    register_shutdown_function(function()use($page,$payload){audit_write('post_change','Изменение через '.$page,'page',$page,['post'=>$payload,'http_status'=>http_response_code()]);});
}
function audit_recent(int $limit=100): array{$limit=max(1,min(500,$limit));return db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT '.$limit)->fetchAll();}
