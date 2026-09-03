<?php
declare(strict_types=1);

require_once __DIR__.'/control.php';
require_once __DIR__.'/cash_register.php';

function owner_brief_data(?string $date=null): array
{
    $pdo=db();
    $date=$date?:date('Y-m-d',strtotime('-1 day'));
    $previous=date('Y-m-d',strtotime($date.' -1 day'));
    $day=dashboard_metrics($date,$date);
    $prev=dashboard_metrics($previous,$previous);

    $monthStart=date('Y-m-01');
    $today=date('Y-m-d');
    $month=dashboard_metrics($monthStart,$today);
    $elapsed=max(1,(int)date('j'));
    $daysInMonth=(int)date('t');
    $forecastRevenue=$month['revenue']/$elapsed*$daysInMonth;
    $forecastProfit=$month['operating_profit']/$elapsed*$daysInMonth;
    $revenueGoal=(float)app_setting('monthly_revenue_goal','0');
    $profitGoal=(float)app_setting('monthly_profit_goal','0');

    $cash=['balance'=>0.0,'shift_open'=>null];
    try{$cash=current_cash_balance();}catch(Throwable $e){}

    $cashDay=['cash_returns'=>0.0,'cash_sales'=>0.0];
    try{$cashDay=cash_period_summary($date,$date);}catch(Throwable $e){}

    $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_at=?");
    $stmt->execute([$date]);
    $manualExpenses=(float)$stmt->fetchColumn();

    $lowStock=$pdo->query("SELECT id,name,unit,stock_quantity,min_stock_quantity,purchase_price,purchase_quantity
        FROM ingredients
        WHERE min_stock_quantity>0 AND stock_quantity<=min_stock_quantity
        ORDER BY (stock_quantity/NULLIF(min_stock_quantity,0)) ASC,name LIMIT 8")->fetchAll();

    $missingCost=(int)$pdo->query("SELECT COUNT(DISTINCT p.id)
        FROM products p
        JOIN recipe_items ri ON ri.product_id=p.id
        JOIN ingredients i ON i.id=ri.ingredient_id
        WHERE p.active=1 AND (i.purchase_price<=0 OR i.purchase_quantity<=0)")->fetchColumn();

    $noRecipe=(int)$pdo->query("SELECT COUNT(*) FROM products p WHERE p.active=1 AND NOT EXISTS(SELECT 1 FROM recipe_items ri WHERE ri.product_id=p.id)")->fetchColumn();

    $alerts=[];
    try{$alerts=array_slice(control_open_alerts(),0,8);}catch(Throwable $e){}

    $actions=[];
    foreach($alerts as $alert){
        if(count($actions)>=3)break;
        $actions[]=['level'=>$alert['severity']==='critical'?'bad':'warn','title'=>$alert['title'],'text'=>$alert['recommendation']?:$alert['message'],'href'=>'control.php'];
    }
    if(count($actions)<3 && $lowStock){$actions[]=['level'=>'warn','title'=>'Пополнить критичные остатки','text'=>'Ниже минимального остатка: '.implode(', ',array_slice(array_column($lowStock,'name'),0,3)).'.','href'=>'inventory.php'];}
    if(count($actions)<3 && ($missingCost>0||$noRecipe>0)){$actions[]=['level'=>'warn','title'=>'Доделать себестоимость меню','text'=>'Без закупочной цены: '.$missingCost.' поз.; без техкарты: '.$noRecipe.' поз.','href'=>'products.php'];}
    if(count($actions)<3 && $revenueGoal>0 && $forecastRevenue<$revenueGoal){$gap=$revenueGoal-$forecastRevenue;$actions[]=['level'=>'warn','title'=>'Выручка идёт ниже плана','text'=>'Текущий прогноз ниже цели примерно на '.money($gap).'.','href'=>'budget.php'];}
    if(count($actions)<3){$actions[]=['level'=>'good','title'=>'Критичных действий не найдено','text'=>'Проверь ключевые показатели и продолжай наблюдение за реальными данными.','href'=>'index.php'];}
    $actions=array_slice($actions,0,3);

    return [
        'date'=>$date,'day'=>$day,'prev'=>$prev,'month'=>$month,'cash'=>$cash,'cash_day'=>$cashDay,
        'manual_expenses'=>$manualExpenses,'low_stock'=>$lowStock,'missing_cost'=>$missingCost,'no_recipe'=>$noRecipe,
        'alerts'=>$alerts,'actions'=>$actions,'forecast_revenue'=>$forecastRevenue,'forecast_profit'=>$forecastProfit,
        'revenue_goal'=>$revenueGoal,'profit_goal'=>$profitGoal,'elapsed'=>$elapsed,'days_in_month'=>$daysInMonth,
    ];
}
