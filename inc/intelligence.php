<?php
declare(strict_types=1);

function median(array $values): float
{
    $values=array_values(array_filter(array_map('floatval',$values),fn($v)=>is_finite($v)));
    if(!$values) return 0;
    sort($values,SORT_NUMERIC);$n=count($values);$m=intdiv($n,2);
    return $n%2?$values[$m]:($values[$m-1]+$values[$m])/2;
}

function shift_analytics(string $from,string $to): array
{
    $sql="SELECT ed.session_id,ed.session_number,MIN(ed.close_date) started_at,MAX(ed.close_date) finished_at,
        COUNT(ed.imported_sale_id) checks,
        COALESCE(SUM(s.total_amount),0) revenue,
        COALESCE(SUM((SELECT SUM(si.quantity*si.unit_cost) FROM sale_items si WHERE si.sale_id=s.id)),0) cogs
        FROM evotor_documents ed
        LEFT JOIN sales s ON s.id=ed.imported_sale_id
        WHERE ed.document_type IN ('SELL','PAYBACK')
          AND ed.close_date>=? AND ed.close_date<DATE_ADD(?,INTERVAL 1 DAY)
          AND ed.imported_sale_id IS NOT NULL
        GROUP BY ed.session_id,ed.session_number
        ORDER BY started_at DESC";
    try{$stmt=db()->prepare($sql);$stmt->execute([$from.' 00:00:00',$to]);$rows=$stmt->fetchAll();}
    catch(Throwable $e){return [];}
    foreach($rows as &$r){$r['revenue']=(float)$r['revenue'];$r['cogs']=(float)$r['cogs'];$r['checks']=(int)$r['checks'];$r['avg_check']=$r['checks']>0?$r['revenue']/$r['checks']:0;$r['gross_profit']=$r['revenue']-$r['cogs'];$r['food_cost']=$r['revenue']>0?$r['cogs']/$r['revenue']*100:0;}
    return $rows;
}

function inventory_variances(int $limit=30): array
{
    $sql="SELECT ic.id count_id,ic.counted_at,i.name,i.unit,ici.expected_quantity,ici.actual_quantity,ici.difference_quantity,
        (i.purchase_price/NULLIF(i.purchase_quantity,0)) unit_cost,
        ABS(ici.difference_quantity)*(i.purchase_price/NULLIF(i.purchase_quantity,0)) variance_value
        FROM inventory_count_items ici
        JOIN inventory_counts ic ON ic.id=ici.inventory_count_id
        JOIN ingredients i ON i.id=ici.ingredient_id
        WHERE ABS(ici.difference_quantity)>0.0001
        ORDER BY ic.counted_at DESC,variance_value DESC LIMIT ".(int)$limit;
    try{return db()->query($sql)->fetchAll();}catch(Throwable $e){return [];}
}

function menu_engineering(string $from,string $to): array
{
    $sql="SELECT p.id,p.name,p.sale_price,COALESCE(SUM(si.quantity),0) qty,
        COALESCE(SUM(si.quantity*si.unit_price),0) revenue,
        COALESCE(SUM(si.quantity*si.unit_cost),0) cogs,
        COALESCE(SUM(si.quantity*(si.unit_price-si.unit_cost)),0) gross_profit
        FROM products p JOIN sale_items si ON si.product_id=p.id JOIN sales s ON s.id=si.sale_id
        WHERE s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY)
        GROUP BY p.id,p.name,p.sale_price HAVING qty>0 ORDER BY gross_profit DESC";
    $stmt=db()->prepare($sql);$stmt->execute([$from.' 00:00:00',$to]);$rows=$stmt->fetchAll();
    $qtyMedian=median(array_column($rows,'qty'));
    $contribMedian=median(array_map(fn($r)=>(float)$r['qty']>0?(float)$r['gross_profit']/(float)$r['qty']:0,$rows));
    foreach($rows as &$r){$r['qty']=(float)$r['qty'];$r['revenue']=(float)$r['revenue'];$r['cogs']=(float)$r['cogs'];$r['gross_profit']=(float)$r['gross_profit'];$r['contribution']=$r['qty']>0?$r['gross_profit']/$r['qty']:0;$popular=$r['qty']>=$qtyMedian;$profitable=$r['contribution']>=$contribMedian;$r['class']=$popular&&$profitable?'Звезда':($popular&&!$profitable?'Рабочая лошадка':(!$popular&&$profitable?'Загадка':'Слабая позиция'));}
    return $rows;
}

function purchase_forecast(int $lookbackDays=14,int $targetCoverDays=7): array
{
    $from=date('Y-m-d H:i:s',strtotime('-'.max(1,$lookbackDays).' days'));
    $sql="SELECT i.id,i.name,i.unit,i.stock_quantity,i.min_stock_quantity,
        COALESCE(SUM(CASE WHEN m.quantity_delta<0 AND m.movement_type='sale' THEN -m.quantity_delta ELSE 0 END),0) used_qty,
        (i.purchase_price/NULLIF(i.purchase_quantity,0)) unit_cost
        FROM ingredients i LEFT JOIN inventory_movements m ON m.ingredient_id=i.id AND m.occurred_at>=?
        GROUP BY i.id,i.name,i.unit,i.stock_quantity,i.min_stock_quantity,i.purchase_price,i.purchase_quantity ORDER BY i.name";
    try{$stmt=db()->prepare($sql);$stmt->execute([$from]);$rows=$stmt->fetchAll();}catch(Throwable $e){return [];}
    foreach($rows as &$r){$daily=(float)$r['used_qty']/max(1,$lookbackDays);$stock=(float)$r['stock_quantity'];$r['daily_usage']=$daily;$r['days_left']=$daily>0?$stock/$daily:null;$target=$daily*$targetCoverDays;$r['suggested_order']=max(0,$target-$stock);$r['suggested_order_value']=$r['suggested_order']*(float)$r['unit_cost'];}
    usort($rows,function($a,$b){$ad=$a['days_left']??999999;$bd=$b['days_left']??999999;return $ad<=>$bd;});
    return $rows;
}
