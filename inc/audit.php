<?php
declare(strict_types=1);

function audit_sanitize(array $data): array{
    $blocked=['password','password_hash','db_pass','token','evotor_token','telegram_token','csrf'];
    $out=[];foreach($data as $k=>$v){$key=mb_strtolower((string)$k);$sensitive=false;foreach($blocked as $b){if(str_contains($key,$b)){$sensitive=true;break;}}if($sensitive){$out[$k]='[скрыто]';continue;}$out[$k]=is_array($v)?audit_sanitize($v):(is_scalar($v)?mb_substr((string)$v,0,500):'[сложное значение]');}return $out;
}
function audit_write(string $action,?string $description=null,?string $entityType=null,?string $entityId=null,array $context=[]): void{
    try{$u=current_user();$stmt=db()->prepare('INSERT INTO audit_log(user_id,user_name,user_role,action,entity_type,entity_id,request_method,request_path,description,context_json,ip_address) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$u['id']??null,$u['name']??null,$u['role']??null,$action,$entityType,$entityId,$_SERVER['REQUEST_METHOD']??null,mb_substr($_SERVER['REQUEST_URI']??'',0,255),$description?mb_substr($description,0,255):null,$context?json_encode(audit_sanitize($context),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,mb_substr($_SERVER['REMOTE_ADDR']??'',0,64)]);}catch(Throwable $e){}
}
function audit_auto_register(): void{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!current_user())return;
    $page=basename($_SERVER['SCRIPT_NAME']??'unknown');$payload=$_POST;
    register_shutdown_function(function()use($page,$payload){audit_write('post_change','Изменение через '.$page,'page',$page,['post'=>$payload,'http_status'=>http_response_code()]);});
}
function audit_recent(int $limit=100): array{$limit=max(1,min(500,$limit));return db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT '.$limit)->fetchAll();}
