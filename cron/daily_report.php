<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/reporting.php';
require_once dirname(__DIR__).'/inc/notifications.php';

$row=telegram_notification_settings();
if(!$row||!(int)$row['enabled']){echo "Telegram disabled.\n";exit(0);}
$currentHour=(int)date('G');$targetHour=(int)$row['send_hour'];$today=date('Y-m-d');
if($currentHour!==$targetHour){echo "Not scheduled hour.\n";exit(0);}
if(($row['last_sent_date']??null)===$today){echo "Already sent today.\n";exit(0);}

try{
    $reportDate=date('Y-m-d',strtotime('-1 day'));
    send_telegram_message(daily_owner_report_text($reportDate));
    $stmt=db()->prepare("UPDATE notification_settings SET last_sent_date=? WHERE channel='telegram'");$stmt->execute([$today]);
    echo "Daily report sent for {$reportDate}.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}