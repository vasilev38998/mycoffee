<?php
declare(strict_types=1);

function customer_operations_default_hours(): array
{
    return [
        '1'=>['enabled'=>true,'open'=>'08:00','close'=>'21:00'],
        '2'=>['enabled'=>true,'open'=>'08:00','close'=>'21:00'],
        '3'=>['enabled'=>true,'open'=>'08:00','close'=>'21:00'],
        '4'=>['enabled'=>true,'open'=>'08:00','close'=>'21:00'],
        '5'=>['enabled'=>true,'open'=>'08:00','close'=>'21:00'],
        '6'=>['enabled'=>true,'open'=>'09:00','close'=>'21:00'],
        '7'=>['enabled'=>true,'open'=>'09:00','close'=>'21:00'],
    ];
}

function customer_operations_time(string $value,string $label): string
{
    $value=trim($value);
    if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value))throw new RuntimeException('Проверьте время «'.$label.'».');
    return $value;
}

function customer_operations_hours(): array
{
    $defaults=customer_operations_default_hours();
    $raw=trim((string)app_setting('customer_order_hours_json',''));
    if($raw==='')return $defaults;
    $decoded=json_decode($raw,true);
    if(!is_array($decoded))return $defaults;
    $out=[];
    foreach($defaults as $day=>$fallback){
        $row=is_array($decoded[$day]??null)?$decoded[$day]:$fallback;
        $open=(string)($row['open']??$fallback['open']);$close=(string)($row['close']??$fallback['close']);
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$open))$open=$fallback['open'];
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$close))$close=$fallback['close'];
        $out[$day]=['enabled'=>!empty($row['enabled']),'open'=>$open,'close'=>$close];
    }
    return $out;
}

function customer_operations_settings(): array
{
    return [
        'accepting'=>(string)app_setting('customer_orders_accepting','1')==='1',
        'pause_reason'=>mb_substr(trim((string)app_setting('customer_orders_pause_reason','')),0,255),
        'schedule_enabled'=>(string)app_setting('customer_order_schedule_enabled','0')==='1',
        'prep_minutes'=>max(5,min(180,(int)app_setting('customer_order_prep_minutes','15'))),
        'slot_interval'=>max(5,min(60,(int)app_setting('customer_order_slot_interval','15'))),
        'slot_capacity'=>max(1,min(100,(int)app_setting('customer_order_slot_capacity','6'))),
        'last_order_minutes'=>max(0,min(180,(int)app_setting('customer_order_last_before_close','15'))),
        'horizon_hours'=>max(1,min(48,(int)app_setting('customer_order_horizon_hours','8'))),
        'hours'=>customer_operations_hours(),
    ];
}

function customer_operations_save(array $data): array
{
    $prep=max(5,min(180,(int)($data['prep_minutes']??15)));
    $interval=max(5,min(60,(int)($data['slot_interval']??15)));
    $capacity=max(1,min(100,(int)($data['slot_capacity']??6)));
    $last=max(0,min(180,(int)($data['last_order_minutes']??15)));
    $horizon=max(1,min(48,(int)($data['horizon_hours']??8)));
    if(60%$interval!==0)throw new RuntimeException('Интервал слотов должен делить час без остатка: 5, 10, 15, 20, 30 или 60 минут.');
    $hours=[];
    for($day=1;$day<=7;$day++){
        $enabled=!empty($data['day_'.$day.'_enabled']);
        $open=customer_operations_time((string)($data['day_'.$day.'_open']??'08:00'),'открытие');
        $close=customer_operations_time((string)($data['day_'.$day.'_close']??'21:00'),'закрытие');
        if($enabled&&$open>=$close)throw new RuntimeException('Время закрытия должно быть позже времени открытия. Ночные смены пока не поддерживаются.');
        $hours[(string)$day]=['enabled'=>$enabled,'open'=>$open,'close'=>$close];
    }
    set_app_setting('customer_orders_accepting',!empty($data['accepting'])?'1':'0');
    set_app_setting('customer_orders_pause_reason',mb_substr(trim((string)($data['pause_reason']??'')),0,255));
    set_app_setting('customer_order_schedule_enabled',!empty($data['schedule_enabled'])?'1':'0');
    set_app_setting('customer_order_prep_minutes',(string)$prep);
    set_app_setting('customer_order_slot_interval',(string)$interval);
    set_app_setting('customer_order_slot_capacity',(string)$capacity);
    set_app_setting('customer_order_last_before_close',(string)$last);
    set_app_setting('customer_order_horizon_hours',(string)$horizon);
    set_app_setting('customer_order_hours_json',json_encode($hours,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return customer_operations_settings();
}

function customer_operations_round_up(DateTimeImmutable $time,int $interval): DateTimeImmutable
{
    $minutes=((int)$time->format('H'))*60+(int)$time->format('i');
    $rounded=(int)(ceil($minutes/$interval)*$interval);
    $dayShift=intdiv($rounded,1440);$rounded%=1440;
    $base=$time->setTime(intdiv($rounded,60),$rounded%60,0);
    if($dayShift>0)$base=$base->modify('+'.$dayShift.' day');
    if($base<$time)$base=$base->modify('+'.$interval.' minutes');
    return $base;
}

function customer_operations_window(DateTimeImmutable $slot,array $settings): ?array
{
    if(!$settings['schedule_enabled'])return ['open'=>$slot->setTime(0,0),'close'=>$slot->setTime(23,59,59)];
    $day=(string)$slot->format('N');$row=$settings['hours'][$day]??null;
    if(!$row||empty($row['enabled']))return null;
    [$oh,$om]=array_map('intval',explode(':',(string)$row['open']));[$ch,$cm]=array_map('intval',explode(':',(string)$row['close']));
    $open=$slot->setTime($oh,$om,0);$close=$slot->setTime($ch,$cm,0)->modify('-'.(int)$settings['last_order_minutes'].' minutes');
    if($close<$open)return null;
    return ['open'=>$open,'close'=>$close];
}

function customer_operations_slot_load(DateTimeImmutable $slot,int $interval): int
{
    $end=$slot->modify('+'.$interval.' minutes');
    $stmt=db()->prepare("SELECT COUNT(*) FROM online_orders WHERE promised_at>=? AND promised_at<? AND status IN ('awaiting_payment','new','preparing','ready')");
    $stmt->execute([$slot->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
    return (int)$stmt->fetchColumn();
}

function customer_operations_slots(?DateTimeImmutable $now=null): array
{
    $settings=customer_operations_settings();$tz=new DateTimeZone(app_timezone());$now=$now?->setTimezone($tz)??new DateTimeImmutable('now',$tz);
    if(!$settings['accepting'])return ['accepting'=>false,'message'=>$settings['pause_reason']!==''?$settings['pause_reason']:'Приём заказов временно приостановлен.','slots'=>[],'settings'=>$settings];
    $first=customer_operations_round_up($now->modify('+'.$settings['prep_minutes'].' minutes'),$settings['slot_interval']);
    $until=$now->modify('+'.$settings['horizon_hours'].' hours');$slots=[];$cursor=$first;$guard=0;
    while($cursor<=$until&&$guard++<600){
        $window=customer_operations_window($cursor,$settings);
        if($window&&$cursor>=$window['open']&&$cursor<=$window['close']){
            $load=customer_operations_slot_load($cursor,$settings['slot_interval']);
            if($load<$settings['slot_capacity']){
                $slots[]=['value'=>$cursor->format('Y-m-d H:i:s'),'label'=>($cursor->format('Y-m-d')===$now->format('Y-m-d')?'Сегодня ':'Завтра ').$cursor->format('H:i'),'remaining'=>max(0,$settings['slot_capacity']-$load)];
            }
        }
        $cursor=$cursor->modify('+'.$settings['slot_interval'].' minutes');
    }
    if($slots)return ['accepting'=>true,'message'=>'','slots'=>$slots,'settings'=>$settings];
    $message=$settings['schedule_enabled']?'Сейчас нет доступных времён для заказа. Проверьте расписание кофейни или попробуйте позже.':'Ближайшие слоты заняты. Попробуйте немного позже.';
    return ['accepting'=>false,'message'=>$message,'slots'=>[],'settings'=>$settings];
}

function customer_operations_public_state(): array
{
    $state=customer_operations_slots();$s=$state['settings'];
    return [
        'accepting'=>$state['accepting'],'message'=>$state['message'],'slots'=>$state['slots'],
        'prep_minutes'=>$s['prep_minutes'],'slot_interval'=>$s['slot_interval'],'slot_capacity'=>$s['slot_capacity'],'schedule_enabled'=>$s['schedule_enabled'],
    ];
}

function customer_operations_validate_slot(string $value): DateTimeImmutable
{
    $value=trim($value);$tz=new DateTimeZone(app_timezone());
    if($value==='')throw new RuntimeException('Выберите время получения заказа.');
    $slot=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,$tz);
    if(!$slot||$slot->format('Y-m-d H:i:s')!==$value)throw new RuntimeException('Некорректное время получения. Обновите доступные интервалы.');
    $state=customer_operations_slots();
    if(!$state['accepting'])throw new RuntimeException($state['message']?:'Приём заказов сейчас недоступен.');
    foreach($state['slots'] as $available)if(hash_equals((string)$available['value'],$value))return $slot;
    throw new RuntimeException('Это время уже недоступно. Обновите корзину и выберите другой интервал.');
}

function customer_operations_legacy_slot(int $delay): string
{
    $state=customer_operations_slots();if(!$state['accepting'])throw new RuntimeException($state['message']?:'Приём заказов сейчас недоступен.');
    if($delay<=0)return (string)($state['slots'][0]['value']??'');
    $target=(new DateTimeImmutable('now',new DateTimeZone(app_timezone())))->modify('+'.$delay.' minutes');$best='';$bestDiff=PHP_INT_MAX;
    foreach($state['slots'] as $slot){$ts=strtotime((string)$slot['value']);if($ts===false)continue;$diff=$ts-$target->getTimestamp();if($diff>=0&&$diff<$bestDiff){$bestDiff=$diff;$best=(string)$slot['value'];}}
    return $best!==''?$best:(string)($state['slots'][0]['value']??'');
}

function customer_operations_metrics(): array
{
    $today=date('Y-m-d');
    $active=(int)db()->query("SELECT COUNT(*) FROM online_orders WHERE status IN ('awaiting_payment','new','preparing','ready')")->fetchColumn();
    $todayCount=(int)db()->query("SELECT COUNT(*) FROM online_orders WHERE DATE(COALESCE(external_created_at,created_at))=CURDATE()")->fetchColumn();
    $overdue=(int)db()->query("SELECT COUNT(*) FROM online_orders WHERE status IN ('new','preparing') AND promised_at IS NOT NULL AND promised_at<NOW()")->fetchColumn();
    $awaiting=(int)db()->query("SELECT COUNT(*) FROM online_orders WHERE status='awaiting_payment'")->fetchColumn();
    $state=customer_operations_public_state();
    return ['active'=>$active,'today'=>$todayCount,'overdue'=>$overdue,'awaiting_payment'=>$awaiting,'next_slot'=>$state['slots'][0]??null,'accepting'=>$state['accepting'],'message'=>$state['message']];
}
