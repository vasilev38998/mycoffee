<?php
declare(strict_types=1);

function kapouch_security_secret(): string
{
    global $config;
    $seed=(string)($config['security']['encryption_key']??(($config['db']['name']??'').'|'.($config['db']['user']??'').'|'.($config['db']['pass']??'')));
    return hash('sha256',$seed,true);
}

function kapouch_is_https_request(): bool
{
    return !empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off';
}

function kapouch_client_ip(): string
{
    $ip=trim((string)($_SERVER['REMOTE_ADDR']??''));
    return filter_var($ip,FILTER_VALIDATE_IP)?$ip:'unknown';
}

function kapouch_rate_limit_identity(string $value): string
{
    return hash_hmac('sha256',$value,kapouch_security_secret());
}

function kapouch_rate_limit_hit(string $scope,string $identity,int $limit,int $windowSeconds): array
{
    $scope=preg_replace('/[^a-z0-9:_-]+/i','_',trim($scope))?:'generic';
    $scope=substr($scope,0,80);$limit=max(1,min(100000,$limit));$windowSeconds=max(1,min(86400,$windowSeconds));
    $hash=kapouch_rate_limit_identity($identity!==''?$identity:'unknown');
    try{
        $pdo=db();
        $sql="INSERT INTO request_rate_limits(scope,identity_hash,window_started_at,hits) VALUES(?,?,NOW(),1) ON DUPLICATE KEY UPDATE hits=IF(window_started_at<=DATE_SUB(NOW(),INTERVAL {$windowSeconds} SECOND),1,hits+1),window_started_at=IF(window_started_at<=DATE_SUB(NOW(),INTERVAL {$windowSeconds} SECOND),NOW(),window_started_at),updated_at=CURRENT_TIMESTAMP";
        $pdo->prepare($sql)->execute([$scope,$hash]);
        $stmt=$pdo->prepare('SELECT hits,GREATEST(1,?-TIMESTAMPDIFF(SECOND,window_started_at,NOW())) retry_after FROM request_rate_limits WHERE scope=? AND identity_hash=?');
        $stmt->execute([$windowSeconds,$scope,$hash]);$row=$stmt->fetch()?:[];$hits=(int)($row['hits']??1);$retry=max(1,(int)($row['retry_after']??$windowSeconds));
        if(random_int(1,200)===1)$pdo->exec('DELETE FROM request_rate_limits WHERE updated_at<DATE_SUB(NOW(),INTERVAL 2 DAY)');
        return ['allowed'=>$hits<=$limit,'hits'=>$hits,'limit'=>$limit,'retry_after'=>$retry];
    }catch(Throwable $e){
        error_log('[Kapouch rate limit] '.$e->getMessage());
        return ['allowed'=>true,'hits'=>0,'limit'=>$limit,'retry_after'=>$windowSeconds];
    }
}

function kapouch_rate_limit_reset(string $scope,string $identity): void
{
    $scope=preg_replace('/[^a-z0-9:_-]+/i','_',trim($scope))?:'generic';$scope=substr($scope,0,80);
    try{$stmt=db()->prepare('DELETE FROM request_rate_limits WHERE scope=? AND identity_hash=?');$stmt->execute([$scope,kapouch_rate_limit_identity($identity!==''?$identity:'unknown')]);}catch(Throwable $e){}
}

function kapouch_ip_is_public(string $ip): bool
{
    return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
}

function kapouch_public_https_target(string $url): array
{
    $url=trim($url);if($url===''||!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('Некорректный URL.');
    $parts=parse_url($url);if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host']))throw new RuntimeException('Разрешены только полные HTTPS URL.');
    if(isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('URL не должен содержать логин или пароль.');
    $host=trim((string)$parts['host'],'[]');$port=(int)($parts['port']??443);if($port<1||$port>65535)throw new RuntimeException('Некорректный порт URL.');
    $ips=[];
    if(filter_var($host,FILTER_VALIDATE_IP))$ips[]=$host;
    else{
        if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',$host))throw new RuntimeException('Некорректное имя хоста.');
        if(function_exists('dns_get_record')){
            $records=@dns_get_record($host,DNS_A|DNS_AAAA)?:[];
            foreach($records as $record){$ip=(string)($record['ip']??$record['ipv6']??'');if($ip!==''&&filter_var($ip,FILTER_VALIDATE_IP))$ips[$ip]=$ip;}
        }
        if(!$ips){foreach(@gethostbynamel($host)?:[] as $ip)if(filter_var($ip,FILTER_VALIDATE_IP))$ips[$ip]=$ip;}
        $ips=array_values($ips);
    }
    if(!$ips)throw new RuntimeException('Не удалось определить IP адрес внешнего сервиса.');
    foreach($ips as $ip)if(!kapouch_ip_is_public($ip))throw new RuntimeException('Внешний URL не может вести во внутреннюю или служебную сеть.');
    $ip=(string)$ips[0];$resolved=str_contains($ip,':')?'['.$ip.']':$ip;
    return ['url'=>$url,'host'=>$host,'port'=>$port,'ip'=>$ip,'resolve'=>$host.':'.$port.':'.$resolved];
}

function kapouch_curl_pin_public_target($ch,array $target): void
{
    if(!filter_var((string)$target['host'],FILTER_VALIDATE_IP))curl_setopt($ch,CURLOPT_RESOLVE,[(string)$target['resolve']]);
    curl_setopt($ch,CURLOPT_FOLLOWLOCATION,false);
    if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))curl_setopt($ch,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
    if(defined('CURLOPT_REDIR_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))curl_setopt($ch,CURLOPT_REDIR_PROTOCOLS,CURLPROTO_HTTPS);
}

function kapouch_advisory_lock_name(string $purpose): string{return 'kapouch_'.substr(hash('sha256',$purpose),0,48);}
function kapouch_advisory_lock(string $purpose,int $timeoutSeconds=0): bool
{
    $stmt=db()->prepare('SELECT GET_LOCK(?,?)');$stmt->execute([kapouch_advisory_lock_name($purpose),max(0,min(60,$timeoutSeconds))]);return (int)$stmt->fetchColumn()===1;
}
function kapouch_advisory_unlock(string $purpose): void
{
    try{$stmt=db()->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([kapouch_advisory_lock_name($purpose)]);}catch(Throwable $e){}
}
