<?php
declare(strict_types=1);

require_once __DIR__.'/customer_push.php';

function customer_birthday_push_settings(): array
{
    $time=trim((string)app_setting('customer_birthday_push_time','10:00'));
    if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time))$time='10:00';
    return [
        'enabled'=>(string)app_setting('customer_birthday_push_enabled','1')==='1',
        'time'=>$time,
        'title'=>trim((string)app_setting('customer_birthday_push_title','С днём рождения! 🎉'))?:'С днём рождения! 🎉',
        'body'=>trim((string)app_setting('customer_birthday_push_body','Поздравляем с днём рождения! Ждём вас в Kapouch ☕'))?:'Поздравляем с днём рождения! Ждём вас в Kapouch ☕',
    ];
}

function customer_birthday_push_text(string $template,array $customer): string
{
    $name=trim((string)($customer['name']??''));
    return strtr($template,[
        '{name}'=>$name!==''?$name:'Друг',
        '{date}'=>date('d.m.Y'),
        '{coffee}'=>(string)app_setting('coffee_name','Kapouch'),
    ]);
}

function customer_birthday_push_enqueue_due(?DateTimeImmutable $now=null): array
{
    $settings=customer_birthday_push_settings();
    if(!$settings['enabled'])return ['matched'=>0,'queued'=>0,'skipped'=>'disabled'];
    $now=$now??new DateTimeImmutable('now',new DateTimeZone(date_default_timezone_get()));
    if($now->format('H:i')<$settings['time'])return ['matched'=>0,'queued'=>0,'skipped'=>'before_time'];

    $monthDay=$now->format('m-d');
    $stmt=db()->prepare("SELECT DISTINCT c.id,c.name,c.birth_date FROM customer_accounts c JOIN customer_push_subscriptions s ON s.customer_id=c.id AND s.active=1 WHERE c.birth_date IS NOT NULL AND DATE_FORMAT(c.birth_date,'%m-%d')=? ORDER BY c.id LIMIT 1000");
    $stmt->execute([$monthDay]);$rows=$stmt->fetchAll();$queued=0;
    foreach($rows as $row){
        $customerId=(int)$row['id'];if($customerId<=0)continue;
        $title=customer_birthday_push_text($settings['title'],$row);
        $body=customer_birthday_push_text($settings['body'],$row);
        if(customer_push_enqueue($customerId,'birthday',$title,$body,'./#profile','birthday:'.$customerId.':'.$now->format('Y'))) $queued++;
    }
    return ['matched'=>count($rows),'queued'=>$queued,'skipped'=>null];
}
