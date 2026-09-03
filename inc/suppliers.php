<?php
declare(strict_types=1);

function supplier_list(bool $activeOnly=false): array{
    $sql='SELECT * FROM suppliers'.($activeOnly?' WHERE active=1':'').' ORDER BY active DESC,name';
    return db()->query($sql)->fetchAll();
}

function supplier_overview(string $from,string $to): array{
    $stmt=db()->prepare("SELECT s.id,s.name,s.active,s.lead_time_days,s.min_order_amount,
        COUNT(p.id) orders,COALESCE(SUM(p.total_amount),0) spend,COUNT(DISTINCT p.ingredient_id) ingredients,
        MAX(p.purchased_at) last_purchase,
        CASE WHEN COUNT(p.id)>0 THEN SUM(p.total_amount)/COUNT(p.id) ELSE 0 END avg_order
        FROM suppliers s LEFT JOIN purchases p ON p.supplier_id=s.id AND p.purchased_at BETWEEN ? AND ?
        GROUP BY s.id,s.name,s.active,s.lead_time_days,s.min_order_amount
        ORDER BY spend DESC,s.name");
    $stmt->execute([$from,$to]);return $stmt->fetchAll();
}

function ingredient_price_intelligence(int $limit=100): array{
    $ingredients=db()->query('SELECT id,name,unit,purchase_price,purchase_quantity FROM ingredients ORDER BY name')->fetchAll();
    $purchaseStmt=db()->prepare("SELECT p.id,p.purchased_at,p.quantity,p.total_amount,p.supplier_id,COALESCE(s.name,NULLIF(p.supplier,''),'Без поставщика') supplier_name
        FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE p.ingredient_id=? AND p.quantity>0 ORDER BY p.purchased_at DESC,p.id DESC LIMIT 12");
    $rows=[];
    foreach($ingredients as $i){
        $purchaseStmt->execute([(int)$i['id']]);$hist=$purchaseStmt->fetchAll();
        if(!$hist)continue;
        foreach($hist as &$h)$h['unit_price']=(float)$h['total_amount']/(float)$h['quantity'];unset($h);
        $latest=$hist[0];$previous=$hist[1]??null;$latestPrice=(float)$latest['unit_price'];$previousPrice=$previous?(float)$previous['unit_price']:null;
        $change=$previousPrice!==null&&$previousPrice>0?($latestPrice-$previousPrice)/$previousPrice*100:null;
        $avg30=0.0;$n30=0;foreach($hist as $h){if(strtotime($h['purchased_at'])>=strtotime('-30 days')){$avg30+=(float)$h['unit_price'];$n30++;}}$avg30=$n30?$avg30/$n30:$latestPrice;
        $rows[]=['ingredient_id'=>(int)$i['id'],'name'=>$i['name'],'unit'=>$i['unit'],'latest_date'=>$latest['purchased_at'],'supplier_name'=>$latest['supplier_name'],'latest_price'=>$latestPrice,'previous_price'=>$previousPrice,'change_pct'=>$change,'avg_30'=>$avg30,'history'=>$hist];
    }
    usort($rows,function($a,$b){return abs((float)($b['change_pct']??0))<=>abs((float)($a['change_pct']??0));});
    return array_slice($rows,0,$limit);
}

function purchase_price_alerts(): array{
    $warning=(float)app_setting('purchase_price_warning_pct','10');$critical=(float)app_setting('purchase_price_critical_pct','20');$out=[];
    foreach(ingredient_price_intelligence(500) as $r){$change=$r['change_pct'];if($change===null||$change<$warning)continue;$r['severity']=$change>=$critical?'critical':'warning';$out[]=$r;}
    return $out;
}

function ingredient_menu_price_impact(int $ingredientId): array{
    $stmt=db()->prepare("SELECT p.id,p.name,p.sale_price,ri.quantity FROM recipe_items ri JOIN products p ON p.id=ri.product_id WHERE ri.ingredient_id=? AND p.active=1 ORDER BY p.name");$stmt->execute([$ingredientId]);$products=$stmt->fetchAll();
    $hist=db()->prepare('SELECT total_amount/NULLIF(quantity,0) unit_price FROM purchases WHERE ingredient_id=? AND quantity>0 ORDER BY purchased_at DESC,id DESC LIMIT 2');$hist->execute([$ingredientId]);$prices=array_map('floatval',$hist->fetchAll(PDO::FETCH_COLUMN));
    $latest=$prices[0]??0;$previous=$prices[1]??$latest;$delta=$latest-$previous;$out=[];
    foreach($products as $p){$oldCost=product_cost((int)$p['id'])-((float)$p['quantity']*$delta);$newCost=product_cost((int)$p['id']);$sale=(float)$p['sale_price'];$out[]=['id'=>(int)$p['id'],'name'=>$p['name'],'sale_price'=>$sale,'old_cost'=>$oldCost,'new_cost'=>$newCost,'cost_delta'=>$newCost-$oldCost,'old_margin'=>$sale>0?($sale-$oldCost)/$sale*100:0,'new_margin'=>$sale>0?($sale-$newCost)/$sale*100:0,'margin_delta'=>($sale>0?(($sale-$newCost)-($sale-$oldCost))/$sale*100:0)];}
    usort($out,fn($a,$b)=>abs($b['cost_delta'])<=>abs($a['cost_delta']));return $out;
}

function supplier_ingredient_comparison(int $ingredientId): array{
    $stmt=db()->prepare("SELECT COALESCE(s.id,0) supplier_id,COALESCE(s.name,NULLIF(p.supplier,''),'Без поставщика') supplier_name,
        COUNT(*) purchases,MAX(p.purchased_at) last_purchase,
        SUM(p.total_amount)/NULLIF(SUM(p.quantity),0) weighted_unit_price,
        MIN(p.total_amount/NULLIF(p.quantity,0)) best_unit_price,
        MAX(p.total_amount/NULLIF(p.quantity,0)) worst_unit_price
        FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE p.ingredient_id=? AND p.quantity>0
        GROUP BY COALESCE(s.id,0),COALESCE(s.name,NULLIF(p.supplier,''),'Без поставщика') ORDER BY weighted_unit_price ASC");
    $stmt->execute([$ingredientId]);return $stmt->fetchAll();
}
