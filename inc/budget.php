<?php
declare(strict_types=1);

require_once __DIR__.'/automatic_expenses.php';

function budget_month_start(?string $month=null): string{
    $month=$month?:date('Y-m');
    return preg_match('/^\d{4}-\d{2}$/',$month)?$month.'-01':date('Y-m-01');
}

function budget_get(string $monthStart): array{
    $stmt=db()->prepare('SELECT * FROM monthly_budgets WHERE month_start=? LIMIT 1');$stmt->execute([$monthStart]);$b=$stmt->fetch();
    if(!$b)return ['id'=>0,'month_start'=>$monthStart,'revenue_plan'=>(float)app_setting('monthly_revenue_goal','0'),'profit_plan'=>(float)app_setting('monthly_profit_goal','0'),'purchases_plan'=>0.0,'notes'=>''];
    return $b;
}

function budget_expense_lines(int $budgetId): array{
    if($budgetId<=0)return [];
    $stmt=db()->prepare('SELECT * FROM budget_expense_lines WHERE budget_id=? ORDER BY category');$stmt->execute([$budgetId]);return $stmt->fetchAll();
}

function budget_save(string $monthStart,float $revenue,float $profit,float $purchases,string $notes,array $categories,array $amounts): int{
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('INSERT INTO monthly_budgets(month_start,revenue_plan,profit_plan,purchases_plan,notes) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE revenue_plan=VALUES(revenue_plan),profit_plan=VALUES(profit_plan),purchases_plan=VALUES(purchases_plan),notes=VALUES(notes),updated_at=CURRENT_TIMESTAMP');
        $stmt->execute([$monthStart,max(0,$revenue),$profit,max(0,$purchases),$notes?:null]);
        $id=(int)($pdo->lastInsertId()?:0);if(!$id){$q=$pdo->prepare('SELECT id FROM monthly_budgets WHERE month_start=?');$q->execute([$monthStart]);$id=(int)$q->fetchColumn();}
        $pdo->prepare('DELETE FROM budget_expense_lines WHERE budget_id=?')->execute([$id]);
        $ins=$pdo->prepare('INSERT INTO budget_expense_lines(budget_id,category,planned_amount) VALUES(?,?,?)');
        foreach($categories as $i=>$cat){$cat=trim((string)$cat);$amount=max(0,(float)($amounts[$i]??0));if($cat===''||$amount<=0)continue;$ins->execute([$id,$cat,$amount]);}
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function budget_actual_expense_categories(string $from,string $to): array{
    $stmt=db()->prepare('SELECT category,COALESCE(SUM(amount),0) amount FROM expenses WHERE spent_at BETWEEN ? AND ? GROUP BY category ORDER BY amount DESC');$stmt->execute([$from,$to]);$rows=$stmt->fetchAll();
    $out=[];foreach($rows as $r)$out[(string)$r['category']]=(float)$r['amount'];
    try{
        refresh_automatic_expenses($from,$to);
        $auto=db()->prepare('SELECT r.category,COALESCE(SUM(a.amount),0) amount FROM automatic_expense_accruals a JOIN automatic_expense_rules r ON r.id=a.rule_id WHERE a.accrual_date BETWEEN ? AND ? GROUP BY r.category');$auto->execute([$from,$to]);
        foreach($auto->fetchAll() as $r)$out[(string)$r['category']]=($out[(string)$r['category']]??0)+(float)$r['amount'];
    }catch(Throwable $e){}
    arsort($out,SORT_NUMERIC);return $out;
}

function budget_fact(string $monthStart,?string $asOf=null): array{
    $monthEnd=date('Y-m-t',strtotime($monthStart));$today=$asOf?:date('Y-m-d');$to=min($monthEnd,$today);if($to<$monthStart)$to=$monthStart;
    $metrics=dashboard_metrics($monthStart,$to);
    $p=db()->prepare('SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchased_at BETWEEN ? AND ?');$p->execute([$monthStart,$to]);$purchases=(float)$p->fetchColumn();
    $categories=budget_actual_expense_categories($monthStart,$to);
    return ['from'=>$monthStart,'to'=>$to,'metrics'=>$metrics,'purchases'=>$purchases,'expense_categories'=>$categories];
}

function budget_plan_fact(string $monthStart): array{
    $budget=budget_get($monthStart);$lines=budget_expense_lines((int)$budget['id']);$fact=budget_fact($monthStart);$daysTotal=(int)date('t',strtotime($monthStart));$elapsed=max(1,(int)date('j',strtotime($fact['to'])));if(substr($fact['to'],0,7)!==substr($monthStart,0,7))$elapsed=$daysTotal;
    $progress=min(1,$elapsed/max(1,$daysTotal));
    $forecastRevenue=$progress>0?$fact['metrics']['revenue']/$progress:0;$forecastProfit=$progress>0?$fact['metrics']['operating_profit']/$progress:0;$forecastPurchases=$progress>0?$fact['purchases']/$progress:0;
    $expenseRows=[];$actualCats=$fact['expense_categories'];
    foreach($lines as $line){$plan=(float)$line['planned_amount'];$actual=(float)($actualCats[$line['category']]??0);$forecast=$progress>0?$actual/$progress:0;$expenseRows[]=['category'=>$line['category'],'plan'=>$plan,'actual'=>$actual,'forecast'=>$forecast,'used_pct'=>$plan>0?$actual/$plan*100:null,'forecast_pct'=>$plan>0?$forecast/$plan*100:null];unset($actualCats[$line['category']]);}
    foreach($actualCats as $cat=>$actual)$expenseRows[]=['category'=>$cat,'plan'=>0.0,'actual'=>$actual,'forecast'=>$progress>0?$actual/$progress:0,'used_pct'=>null,'forecast_pct'=>null];
    usort($expenseRows,fn($a,$b)=>$b['forecast']<=>$a['forecast']);
    return ['budget'=>$budget,'fact'=>$fact,'progress'=>$progress,'days_total'=>$daysTotal,'elapsed_days'=>$elapsed,'forecast_revenue'=>$forecastRevenue,'forecast_profit'=>$forecastProfit,'forecast_purchases'=>$forecastPurchases,'revenue_attainment'=>(float)$budget['revenue_plan']>0?$fact['metrics']['revenue']/(float)$budget['revenue_plan']*100:null,'profit_attainment'=>(float)$budget['profit_plan']!=0?$fact['metrics']['operating_profit']/(float)$budget['profit_plan']*100:null,'purchases_used'=>(float)$budget['purchases_plan']>0?$fact['purchases']/(float)$budget['purchases_plan']*100:null,'expense_rows'=>$expenseRows];
}

function budget_scenario(array $pf,float $revenueChangePct,float $expenseChangePct): array{
    $rev=$pf['forecast_revenue']*(1+$revenueChangePct/100);$baseRev=max(0.01,$pf['forecast_revenue']);$baseProfit=$pf['forecast_profit'];$baseCosts=$baseRev-$baseProfit;$costs=$baseCosts*(1+$expenseChangePct/100);$profit=$rev-$costs;
    return ['revenue'=>$rev,'profit'=>$profit,'margin'=>$rev>0?$profit/$rev*100:0,'revenue_change'=>$revenueChangePct,'expense_change'=>$expenseChangePct];
}

function budget_risks(array $pf): array{
    $warning=(float)app_setting('budget_warning_pct','90');$critical=(float)app_setting('budget_critical_pct','110');$risks=[];
    foreach($pf['expense_rows'] as $r){if($r['plan']<=0)continue;$pct=(float)$r['forecast_pct'];if($pct>=$warning)$risks[]=['severity'=>$pct>=$critical?'critical':'warning','title'=>'Риск перерасхода: '.$r['category'],'message'=>'Прогноз '.money($r['forecast']).' при бюджете '.money($r['plan']).' ('.number_format($pct,0,',',' ').'%).'];}
    if((float)$pf['budget']['purchases_plan']>0&&$pf['forecast_purchases']>(float)$pf['budget']['purchases_plan'])$risks[]=['severity'=>$pf['forecast_purchases']>(float)$pf['budget']['purchases_plan']*1.1?'critical':'warning','title'=>'Прогноз закупок выше плана','message'=>'Прогноз '.money($pf['forecast_purchases']).' при плане '.money((float)$pf['budget']['purchases_plan']).'.'];
    if((float)$pf['budget']['revenue_plan']>0&&$pf['forecast_revenue']<(float)$pf['budget']['revenue_plan'])$risks[]=['severity'=>'warning','title'=>'Прогноз выручки ниже плана','message'=>'Прогноз '.money($pf['forecast_revenue']).' при плане '.money((float)$pf['budget']['revenue_plan']).'.'];
    if((float)$pf['budget']['profit_plan']>0&&$pf['forecast_profit']<(float)$pf['budget']['profit_plan'])$risks[]=['severity'=>$pf['forecast_profit']<0?'critical':'warning','title'=>'Прогноз прибыли ниже плана','message'=>'Прогноз '.money($pf['forecast_profit']).' при плане '.money((float)$pf['budget']['profit_plan']).'.'];
    return $risks;
}
