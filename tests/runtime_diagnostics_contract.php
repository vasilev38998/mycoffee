<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/inc/runtime_log.php';

function diag_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"Runtime diagnostics contract failed: {$message}\n");exit(1);}
    echo "OK: {$message}\n";
}

$htaccess=file_get_contents($root.'/.htaccess');
$bootstrap=file_get_contents($root.'/inc/bootstrap.php');
$db=file_get_contents($root.'/inc/db.php');
$discount=file_get_contents($root.'/api/evotor_loyalty_discount.php');
$sync=file_get_contents($root.'/cron/evotor_sync.php');

$logDir=kapouch_runtime_log_dir();
diag_ok(str_ends_with(str_replace('\\','/',$logDir),'/storage/logs'),'runtime logs use storage/logs');
diag_ok(str_contains($htaccess,'RewriteRule ^storage(?:/|$) - [F,L,NC]')&&str_contains($htaccess,'keystore|jks|log'),'runtime logs are blocked from HTTP access');
diag_ok(is_file($root.'/storage/logs/.htaccess')&&str_contains((string)file_get_contents($root.'/storage/logs/.htaccess'),'Require all denied'),'log directory has defense-in-depth deny rule');
diag_ok(str_contains($bootstrap,"require_once __DIR__.'/runtime_log.php'")&&str_contains($bootstrap,'kapouch_configure_php_error_log();'),'bootstrap configures dedicated PHP error log');
diag_ok(str_contains($bootstrap,"kapouch_runtime_log('php','fatal'")&&str_contains($bootstrap,"'uncaught_capacity':'uncaught_exception'"),'fatal and uncaught failures are traced');
diag_ok(str_contains($db,"kapouch_runtime_log('db','capacity_exception'")&&str_contains($db,"kapouch_runtime_log('db','capacity_gate'"),'database capacity incidents are traced');
diag_ok(str_contains($discount,"kapouch_runtime_log('evotor','discount_request'")&&str_contains($discount,"kapouch_runtime_log('evotor','discount_quote'")&&str_contains($discount,"kapouch_runtime_log('evotor','discount_confirm'"),'Evotor discount lifecycle is traced');
diag_ok(str_contains($sync,"kapouch_runtime_log('evotor_sync','connection_result'")&&str_contains($sync,"'gifts_restored'")&&str_contains($sync,"'refunds'"),'Evotor sync records refund reconciliation');

$_SERVER['HTTP_HOST']='contract.local';
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['REQUEST_URI']='/contract/runtime-diagnostics';
$marker='diag-'.bin2hex(random_bytes(6));
$runtimeFile=$logDir.DIRECTORY_SEPARATOR.'runtime-'.date('Y-m-d').'.log';
$before=is_file($runtimeFile)?(int)filesize($runtimeFile):0;
kapouch_runtime_log('contract','probe',['marker'=>$marker,'terminal_token'=>'secret-terminal-token','phone'=>'+79990000000','safe_value'=>42]);
clearstatcache(true,$runtimeFile);
diag_ok(is_file($runtimeFile)&&(int)filesize($runtimeFile)>$before,'runtime logger writes a JSONL event');
$raw=(string)file_get_contents($runtimeFile);
$tail=substr($raw,max(0,strrpos(substr($raw,0,-1),"\n")+1));
diag_ok(str_contains($tail,$marker)&&str_contains($tail,'"terminal_token":"[redacted]"')&&str_contains($tail,'"phone":"[redacted]"'),'runtime logger redacts sensitive fields');
diag_ok(!str_contains($tail,'secret-terminal-token')&&!str_contains($tail,'+79990000000'),'sensitive values are absent from runtime log');

@unlink($runtimeFile);
echo "RUNTIME DIAGNOSTICS CONTRACT PASSED\n";
