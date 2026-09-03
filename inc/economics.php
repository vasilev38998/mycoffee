<?php
declare(strict_types=1);

require_once __DIR__.'/intelligence.php';
require_once __DIR__.'/automatic_expenses.php';
require_once __DIR__.'/cash_register.php';

function economics_days(string $from,string $to): int{return max(1,(int)((strtotime($to)-strtotime($from))/86400)+1);}
function economics_pct(float $current,float $base): ?float{return abs($base)>0.000001?($current-$base)/abs($base)*100:null;}

function economics_period_comparison(string $from,string $to): array
{
    $days=economics_days($from,$to);
    $prevTo=date('Y-m-d',strtotime($from.' -1 day'));
    $prevFrom=date('Y-m-d',strtotime($prevTo.' -'.($days-1).' days'));
    $cur=dashboard_metrics($from,$to);$prev=dashboard_metrics($prevFrom,$prevTo);
    return ['current'=>$cur,'previous'=>$prev,'previous_from'=>$prevFrom,'previous_to'=>$prevTo,
        'revenue_change'=>economics_pct($cur['revenue'],$prev['revenue']),
        'checks_change'=>economics_pct($cur['checks'],$prev['checks']),
        'avg_check_change'=>economics_pct($cur['avg_check'],$prev['avg_check']),
        'profit_change'=>economics_pct($cur['operating_profit'],$prev['operating_profit'])];
}

function economics_expense_structure(string $from,string $to): array
{
    refresh_automatic_expenses($from,$to);
    $stmt=db()->prepare("SELECT aer.amount,aer.basis_amount,aer.accrual_date,r.rule_type FROM automatic_expense_accruals aer JOIN automatic_expense_rules r ON r.id=aer.rule_id WHERE aer.accrual_date BETWEEN ? AND ?");
    $stmt->execute([$from,$to]);$variable=0.0;$fixed=0.0;
    foreach($stmt->fetchAll() as $r){if(in_array($r['rule_type'],['percent_revenue','percent_card_revenue'],true))$variable+=(float)$r['amount'];else $fixed+=(float)$r['amount'];}
    $m=db()->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_at BETWEEN ? AND ?');$m->execute([$from,$to]);$manual=(float)$m->fetchColumn();
    $fixed+=$manual;
    return ['variable'=>$variable,'fixed'=>$fixed,'manual'=>$manual,'total'=>$variable+$fixed];
}

function economics_break_even(string $from,string $to): array
{
    $m=dashboard_metrics($from,$to);$e=economics_expense_structure($from,$to);$days=economics_days($from,$to);
    $contribution=$m['revenue']-$m['cogs']-$e['variable'];
    $ratio=$m['revenue']>0?$contribution/$m['revenue']:0;
    $beRevenue=$ratio>0?$e['fixed']/$ratio:0;
    $avgCheck=$m['avg_check'];$beChecks=$avgCheck>0?$beRevenue/$avgCheck:0;
    $safety=$m['revenue']>0?($m['revenue']-$beRevenue)/$m['revenue']*100:0;
    return ['contribution'=>$contribution,'contribution_margin'=>$ratio*100,'fixed_costs'=>$e['fixed'],'variable_costs'=>$e['variable'],'break_even_revenue'=>$beRevenue,'break_even_checks'=>$beChecks,'break_even_revenue_day'=>$beRevenue/$days,'break_even_checks_day'=>$beChecks/$days,'safety_margin'=>$safety];
}

function economics_inventory_kpis(string $from,string $to): array
{
    $stock=(float)(db()->query("SELECT COALESCE(SUM(stock_quantity*(purchase_price/NULLIF(purchase_quantity,0))),0) FROM ingredients")->fetchColumn()?:0);
    $m=dashboard_metrics($from,$to);$days=economics_days($from,$to);$dailyCogs=$m['cogs']/$days;
    $daysOnHand=$dailyCogs>0?$stock/$dailyCogs:null;
    $turnover=$stock>0?$m['cogs']/$stock:null;
    $write=0.0;
    try{$stmt=db()->prepare("SELECT COALESCE(SUM(ABS(im.quantity_delta)*(i.purchase_price/NULLIF(i.purchase_quantity,0))),0) FROM inventory_movements im JOIN ingredients i ON i.id=im.ingredient_id WHERE im.movement_type='writeoff' AND im.occurred_at>=? AND im.occurred_at<DATE_ADD(?,INTERVAL 1 DAY)");$stmt->execute([$from.' 00:00:00',$to]);$write=(float)$stmt->fetchColumn();}catch(Throwable $e){}
    return ['stock_value'=>$stock,'days_on_hand'=>$daysOnHand,'turnover_period'=>$turnover,'writeoff_value'=>$write,'writeoff_to_cogs'=>$m['cogs']>0?$write/$m['cogs']*100:0];
}

function economics_menu_abc_xyz(string $from,string $to): array
{
    $rows=menu_engineering($from,$to);if(!$rows)return [];
    $total=array_sum(array_column($rows,'gross_profit'));$cum=0.0;
    $dailyStmt=db()->prepare("SELECT si.product_id,DATE(s.sold_at) d,SUM(si.quantity) qty FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY si.product_id,DATE(s.sold_at)");
    $dailyStmt->execute([$from.' 00:00:00',$to]);$daily=[];foreach($dailyStmt->fetchAll() as $d)$daily[(int)$d['product_id']][]=(float)$d['qty'];
    foreach($rows as &$r){$cum+=(float)$r['gross_profit'];$share=$total>0?$cum/$total:1;$r['abc']=$share<=.80?'A':($share<=.95?'B':'C');$vals=$daily[(int)$r['id']]??[];$avg=$vals?array_sum($vals)/count($vals):0;$variance=0.0;if($avg>0&&count($vals)>1){foreach($vals as $v)$variance+=($v-$avg)**2;$std=sqrt($variance/count($vals));$cv=$std/$avg;}else{$cv=0;}$r['xyz']=$cv<=.5?'X':($cv<=1?'Y':'Z');$r['abc_xyz']=$r['abc'].$r['xyz'];$r['food_cost']=$r['revenue']>0?$r['cogs']/$r['revenue']*100:0;}
    return $rows;
}

function economics_category_profitability(string $from,string $to): array
{
    $costExpr=sale_item_effective_unit_cost_sql('si');
    $stmt=db()->prepare("SELECT COALESCE(NULLIF(p.category,''),'Без категории') category,
        SUM(si.quantity) qty,
        SUM(si.quantity*si.unit_price) revenue,
        SUM(si.quantity*({$costExpr})) cogs
        FROM sale_items si
        JOIN sales s ON s.id=si.sale_id
        JOIN products p ON p.id=si.product_id
        WHERE s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY)
        GROUP BY COALESCE(NULLIF(p.category,''),'Без категории')
        ORDER BY revenue DESC");
    $stmt->execute([$from.' 00:00:00',$to]);$rows=$stmt->fetchAll();foreach($rows as &$r){$r['revenue']=(float)$r['revenue'];$r['cogs']=(float)$r['cogs'];$r['gross_profit']=$r['revenue']-$r['cogs'];$r['margin']=$r['revenue']>0?$r['gross_profit']/$r['revenue']*100:0;}
    return $rows;
}

function economics_cash_reserve(string $from,string $to): array
{
    try{$cash=current_cash_balance()['balance'];}catch(Throwable $e){$cash=0.0;}
    $m=dashboard_metrics($from,$to);$days=economics_days($from,$to);$dailyOut=($m['cogs']+$m['expenses'])/$days;
    return ['cash'=>$cash,'daily_outflow'=>$dailyOut,'reserve_days'=>$dailyOut>0?$cash/$dailyOut:null];
}

function economics_month_forecast(): array
{
    $today=date('Y-m-d');$start=date('Y-m-01');$elapsed=(int)date('j');$total=(int)date('t');$m=dashboard_metrics($start,$today);
    $revenue=$elapsed>0?$m['revenue']/$elapsed*$total:0;$profit=$elapsed>0?$m['operating_profit']/$elapsed*$total:0;
    $revGoal=(float)app_setting('monthly_revenue_goal','0');$profitGoal=(float)app_setting('monthly_profit_goal','0');
    return ['revenue'=>$revenue,'profit'=>$profit,'revenue_goal'=>$revGoal,'profit_goal'=>$profitGoal,'revenue_goal_pct'=>$revGoal>0?$revenue/$revGoal*100:null,'profit_goal_pct'=>$profitGoal>0?$profit/$profitGoal*100:null];
}
