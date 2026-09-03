<?php
declare(strict_types=1);

require_once __DIR__.'/intelligence.php';
require_once __DIR__.'/control.php';

function daily_owner_report(string $date): array
{
    $m=dashboard_metrics($date,$date);
    $prevDate=date('Y-m-d',strtotime($date.' -1 day'));
    $prev=dashboard_metrics($prevDate,$prevDate);

    $top=db()->prepare("SELECT p.name,SUM(si.quantity) qty,SUM(si.quantity*(si.unit_price-si.unit_cost)) profit FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE DATE(s.sold_at)=? GROUP BY p.id,p.name ORDER BY profit DESC LIMIT 1");
    $top->execute([$date]);$topRow=$top->fetch()?:null;

    $hour=db()->prepare("SELECT HOUR(sold_at) h,SUM(total_amount) revenue FROM sales WHERE DATE(sold_at)=? GROUP BY HOUR(sold_at) ORDER BY revenue DESC LIMIT 1");
    $hour->execute([$date]);$peak=$hour->fetch()?:null;

    $foodCost=$m['revenue']>0?$m['cogs']/$m['revenue']*100:0;
    $expenseLoad=$m['revenue']>0?$m['expenses']/$m['revenue']*100:0;
    $targetFood=(float)app_setting('target_food_cost','30');
    $targetExpenses=(float)app_setting('target_expense_load','30');
    $alerts=[];
    if($prev['revenue']>0){$delta=($m['revenue']-$prev['revenue'])/$prev['revenue']*100;if($delta<-10)$alerts[]='Выручка ниже вчерашней на '.number_format(abs($delta),1,',',' ').'%';}
    if($foodCost>$targetFood)$alerts[]='Food cost '.number_format($foodCost,1,',',' ').'% выше цели '.number_format($targetFood,1,',',' ').'%';
    if($expenseLoad>$targetExpenses)$alerts[]='Расходы занимают '.number_format($expenseLoad,1,',',' ').'% выручки';
    $urgent=array_values(array_filter(purchase_forecast(14,7),fn($r)=>$r['days_left']!==null&&$r['days_left']<3));
    if($urgent)$alerts[]='Скоро закончится: '.implode(', ',array_slice(array_column($urgent,'name'),0,3));
    $controlAlerts=array_slice(control_open_alerts(),0,5);

    return ['date'=>$date,'metrics'=>$m,'previous'=>$prev,'food_cost'=>$foodCost,'expense_load'=>$expenseLoad,'top'=>$topRow,'peak'=>$peak,'alerts'=>$alerts,'urgent'=>$urgent,'control_alerts'=>$controlAlerts];
}

function daily_owner_report_text(string $date): string
{
    $r=daily_owner_report($date);$m=$r['metrics'];$name=(string)app_setting('coffee_name','Kapouch');
    $lines=[];$lines[]=$name.' · отчёт за '.date('d.m.Y',strtotime($date));
    $lines[]='Выручка: '.money($m['revenue']);
    $lines[]='Чеков: '.$m['checks'].' · средний чек: '.money($m['avg_check']);
    $lines[]='Валовая прибыль: '.money($m['gross_profit']).' · food cost: '.number_format($r['food_cost'],1,',',' ').'%';
    $lines[]='Операционная прибыль: '.money($m['operating_profit']).' · маржа: '.number_format($m['margin'],1,',',' ').'%';
    if($r['top'])$lines[]='Лидер: '.$r['top']['name'].' · валовая прибыль '.money((float)$r['top']['profit']);
    if($r['peak'])$lines[]='Пиковый час: '.sprintf('%02d:00–%02d:00',(int)$r['peak']['h'],((int)$r['peak']['h']+1)%24).' · '.money((float)$r['peak']['revenue']);
    if($r['alerts']){$lines[]='';$lines[]='Внимание:';foreach($r['alerts'] as $a)$lines[]='• '.$a;}
    if($r['control_alerts']){$lines[]='';$lines[]='Центр контроля:';foreach($r['control_alerts'] as $a)$lines[]='• '.($a['severity']==='critical'?'КРИТИЧНО: ':'').$a['title'].' — '.$a['message'];}
    if(!$r['alerts']&&!$r['control_alerts']){$lines[]='';$lines[]='Критичных отклонений не обнаружено.';}
    return implode("\n",$lines);
}
