<?php
declare(strict_types=1);

require_once __DIR__.'/intelligence.php';
require_once __DIR__.'/cash_register.php';

function ensure_control_tables(): void
{
    static $ready=false;
    if($ready)return;
    try{db()->query('SELECT id FROM control_alerts LIMIT 1');$ready=true;return;}catch(Throwable $e){}
    $sql=file_get_contents(__DIR__.'/../database/migrations/008_business_control.sql');
    if($sql!==false)db()->exec($sql);
    $ready=true;
}

function control_upsert(array $a): void
{
    ensure_control_tables();
    $stmt=db()->prepare("INSERT INTO control_alerts(alert_key,severity,category,title,message,recommendation,metric_value,threshold_value,status,first_seen_at,last_seen_at,occurrences,context_json) VALUES(?,?,?,?,?,?,?,?,'open',NOW(),NOW(),1,?) ON DUPLICATE KEY UPDATE severity=VALUES(severity),category=VALUES(category),title=VALUES(title),message=VALUES(message),recommendation=VALUES(recommendation),metric_value=VALUES(metric_value),threshold_value=VALUES(threshold_value),status=IF(status='resolved','open',status),resolved_at=NULL,last_seen_at=NOW(),occurrences=occurrences+1,context_json=VALUES(context_json)");
    $stmt->execute([$a['key'],$a['severity'],$a['category'],$a['title'],$a['message'],$a['recommendation']??null,$a['value']??null,$a['threshold']??null,json_encode($a['context']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function control_metric_day(string $date): array
{
    return dashboard_metrics($date,$date);
}

function control_weekday_baseline(string $date): array
{
    $values=[];
    for($i=1;$i<=4;$i++){
        $d=date('Y-m-d',strtotime($date.' -'.(7*$i).' days'));
        $m=control_metric_day($d);
        if($m['checks']>0)$values[]=$m;
    }
    if(!$values)return ['revenue'=>0.0,'avg_check'=>0.0,'checks'=>0];
    return [
        'revenue'=>array_sum(array_column($values,'revenue'))/count($values),
        'avg_check'=>array_sum(array_column($values,'avg_check'))/count($values),
        'checks'=>(int)round(array_sum(array_column($values,'checks'))/count($values)),
    ];
}

function evaluate_business_control(?string $date=null): array
{
    ensure_control_tables();
    $date=$date?:date('Y-m-d',strtotime('-1 day'));
    $seen=[];$alerts=[];
    $push=function(array $a)use(&$seen,&$alerts){$seen[]=$a['key'];$alerts[]=$a;control_upsert($a);};

    $day=control_metric_day($date);$base=control_weekday_baseline($date);
    $revenueDrop=(float)app_setting('control_revenue_drop_pct','15');
    $avgDrop=(float)app_setting('control_avg_check_drop_pct','10');
    if($base['revenue']>0){$drop=($base['revenue']-$day['revenue'])/$base['revenue']*100;if($drop>=$revenueDrop)$push(['key'=>'revenue_drop_'.$date,'severity'=>$drop>=30?'critical':'warning','category'=>'sales','title'=>'Выручка заметно ниже обычной','message'=>'Выручка за '.date('d.m',strtotime($date)).' ниже среднего такого же дня недели за 4 недели на '.number_format($drop,1,',',' ').'%.','recommendation'=>'Проверь трафик, часы работы, доступность популярных позиций и работу смены.','value'=>$drop,'threshold'=>$revenueDrop]);}
    if($base['avg_check']>0){$drop=($base['avg_check']-$day['avg_check'])/$base['avg_check']*100;if($drop>=$avgDrop)$push(['key'=>'avg_check_drop_'.$date,'severity'=>'warning','category'=>'sales','title'=>'Снижение среднего чека','message'=>'Средний чек ниже своей 4-недельной базы на '.number_format($drop,1,',',' ').'%.','recommendation'=>'Посмотри структуру заказов, допродажи и долю дешёвых позиций.','value'=>$drop,'threshold'=>$avgDrop]);}

    $targetFood=(float)app_setting('target_food_cost','30');
    $food=$day['revenue']>0?$day['cogs']/$day['revenue']*100:0;
    if($day['revenue']>0&&$food>$targetFood)$push(['key'=>'food_cost_'.$date,'severity'=>$food>$targetFood+10?'critical':'warning','category'=>'margin','title'=>'Food cost выше нормы','message'=>'Food cost за день — '.number_format($food,1,',',' ').'% при цели '.number_format($targetFood,1,',',' ').'%.','recommendation'=>'Проверь закупочные цены, техкарты, списания и позиции с низкой маржой.','value'=>$food,'threshold'=>$targetFood]);

    try{
        $cash=cash_period_summary($date,$date);$gross=$cash['cash_sales']+$cash['cash_returns'];$refundShare=$gross>0?$cash['cash_returns']/$gross*100:0;$refundLimit=(float)app_setting('control_refund_share_pct','8');
        if($refundShare>$refundLimit)$push(['key'=>'cash_refunds_'.$date,'severity'=>$refundShare>$refundLimit*2?'critical':'warning','category'=>'cash','title'=>'Высокая доля наличных возвратов','message'=>'Возвраты наличными составили '.number_format($refundShare,1,',',' ').'% наличного оборота.','recommendation'=>'Проверь причины возвратов и конкретные чеки/смены.','value'=>$refundShare,'threshold'=>$refundLimit]);
        $balance=current_cash_balance();$cashLimit=(float)app_setting('control_cash_limit','20000');
        if($cashLimit>0&&$balance['balance']>$cashLimit)$push(['key'=>'cash_limit','severity'=>'warning','category'=>'cash','title'=>'В кассе много наличных','message'=>'Расчётный остаток '.money((float)$balance['balance']).' выше лимита '.money($cashLimit).'.','recommendation'=>'Рассмотри инкассацию и проверь фактический остаток.','value'=>$balance['balance'],'threshold'=>$cashLimit]);
    }catch(Throwable $e){}

    $stockDays=(float)app_setting('control_stock_days_warning','3');
    foreach(array_slice(purchase_forecast(14,7),0,30) as $row){if($row['days_left']!==null&&$row['days_left']<$stockDays)$push(['key'=>'stock_'.(int)$row['id'],'severity'=>$row['days_left']<1?'critical':'warning','category'=>'stock','title'=>'Заканчивается '.$row['name'],'message'=>'Остатка хватит примерно на '.number_format((float)$row['days_left'],1,',',' ').' дн.','recommendation'=>'Рекомендуемый заказ: '.number_format((float)$row['suggested_order'],2,',',' ').' '.$row['unit'].'.','value'=>$row['days_left'],'threshold'=>$stockDays]);}

    $varianceLimit=(float)app_setting('control_inventory_variance_value','1000');
    foreach(inventory_variances(20) as $row){$value=(float)$row['variance_value'];if($value>=$varianceLimit)$push(['key'=>'inventory_variance_'.(int)$row['count_id'].'_'.md5($row['name']),'severity'=>$value>=$varianceLimit*3?'critical':'warning','category'=>'stock','title'=>'Крупное расхождение: '.$row['name'],'message'=>'Расхождение по инвентаризации оценивается в '.money($value).'.','recommendation'=>'Проверь списания, техкарту, единицы измерения и фактический учёт.','value'=>$value,'threshold'=>$varianceLimit]);}

    try{
        $open=(string)app_setting('opening_hour','07:00');$close=(string)app_setting('closing_hour','21:00');$now=date('H:i');
        if($now>=$open&&$now<=$close){$last=(int)db()->query('SELECT COALESCE(MAX(last_documents_sync_ms),0) FROM evotor_connections WHERE enabled=1')->fetchColumn();if($last>0&&time()-(int)($last/1000)>7200)$push(['key'=>'evotor_stale','severity'=>'critical','category'=>'integration','title'=>'Эвотор давно не синхронизировался','message'=>'Новые документы не загружались больше 2 часов в рабочее время.','recommendation'=>'Проверь cron, токен и раздел «Интеграции».','value'=>(time()-(int)($last/1000))/3600,'threshold'=>2]);}
    }catch(Throwable $e){}

    if($seen){$marks=implode(',',array_fill(0,count($seen),'?'));$stmt=db()->prepare("UPDATE control_alerts SET status='resolved',resolved_at=NOW() WHERE status<>'resolved' AND alert_key NOT IN ($marks) AND alert_key NOT LIKE '%_'.? ");$params=$seen;$params[]=$date;$stmt->execute($params);}else db()->exec("UPDATE control_alerts SET status='resolved',resolved_at=NOW() WHERE status<>'resolved'");
    return $alerts;
}

function control_open_alerts(): array
{
    ensure_control_tables();
    return db()->query("SELECT * FROM control_alerts WHERE status<>'resolved' ORDER BY FIELD(severity,'critical','warning','info'),last_seen_at DESC")->fetchAll();
}

function control_summary(): array
{
    ensure_control_tables();
    $r=db()->query("SELECT SUM(severity='critical' AND status<>'resolved') critical,SUM(severity='warning' AND status<>'resolved') warning,SUM(status<>'resolved') total FROM control_alerts")->fetch();
    return ['critical'=>(int)($r['critical']??0),'warning'=>(int)($r['warning']??0),'total'=>(int)($r['total']??0)];
}
