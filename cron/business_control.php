<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/control.php';
require_once dirname(__DIR__).'/inc/notifications.php';

try{
    $alerts=evaluate_business_control();
    echo '['.date('c').'] active alerts: '.count($alerts)."\n";

    if((string)app_setting('control_telegram_critical','1')==='1'){
        $critical=db()->query("SELECT * FROM control_alerts WHERE severity='critical' AND status<>'resolved' AND (last_notified_at IS NULL OR last_notified_at<DATE_SUB(NOW(),INTERVAL 12 HOUR)) ORDER BY last_seen_at DESC LIMIT 10")->fetchAll();
        if($critical){
            $telegram=telegram_notification_settings();
            if($telegram && (int)$telegram['enabled']){
                $lines=[(string)app_setting('coffee_name','Kapouch').' · критичные сигналы'];
                foreach($critical as $a){$lines[]='';$lines[]='⚠ '.$a['title'];$lines[]=$a['message'];if($a['recommendation'])$lines[]='Что сделать: '.$a['recommendation'];}
                send_telegram_message(implode("\n",$lines));
                $ids=array_map('intval',array_column($critical,'id'));
                if($ids){db()->exec('UPDATE control_alerts SET last_notified_at=NOW() WHERE id IN ('.implode(',',$ids).')');}
                echo '['.date('c').'] Telegram critical alert sent.' . "\n";
            }
        }
    }
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}