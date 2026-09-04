<?php
declare(strict_types=1);

function receipt_package_detect(string $name): ?array
{
    $s=mb_strtolower(str_replace(',', '.', $name));
    $patterns=[
        ['/(\d+(?:\.\d+)?)\s*(?:л|литр(?:а|ов)?|l)\b/u','ml',1000],
        ['/(\d+(?:\.\d+)?)\s*(?:мл|ml)\b/u','ml',1],
        ['/(\d+(?:\.\d+)?)\s*(?:кг|kg)\b/u','g',1000],
        ['/(\d+(?:\.\d+)?)\s*(?:г|гр|g)\b/u','g',1],
        ['/(\d+(?:\.\d+)?)\s*(?:шт|штук(?:а|и)?|pcs?)\b/u','pcs',1],
    ];
    foreach($patterns as [$rx,$unit,$factor]){
        if(preg_match($rx,$s,$m,PREG_OFFSET_CAPTURE)){
            $value=(float)$m[1][0];if($value<=0)continue;
            $base=round($value*$factor,4);
            $productKey=trim(preg_replace('/\s+/u',' ',preg_replace($rx,' ',$s,1)??$s)??$s);
            $productKey=receipt_normalize_name($productKey);
            return ['quantity'=>$base,'unit'=>$unit,'signature'=>$unit.':'.rtrim(rtrim(number_format($base,4,'.',''),'0'),'.'),'product_key'=>$productKey];
        }
    }
    return null;
}

function receipt_package_reconcile_draft(int $receiptId): void
{
    $draft=receipt_draft($receiptId);if(!$draft||$draft['status']!=='draft')return;
    $pdo=db();
    $upd=$pdo->prepare('UPDATE purchase_receipt_items SET package_product_key=?,detected_package_quantity=?,detected_package_unit=?,package_signature=?,package_warning=?,ingredient_id=?,quantity_per_item=?,rule_id=? WHERE id=?');
    foreach($draft['items'] as $row){
        $det=receipt_package_detect((string)$row['raw_name']);
        if(!$det){
            $upd->execute([null,null,null,null,'Объём/вес упаковки не распознан — проверь количество вручную.',$row['ingredient_id']?:null,$row['quantity_per_item']?:null,$row['rule_id']?:null,(int)$row['id']]);
            continue;
        }
        $ruleStmt=$pdo->prepare('SELECT * FROM receipt_package_rules WHERE product_key=? AND package_signature=? AND auto_apply=1 LIMIT 1');
        $ruleStmt->execute([$det['product_key'],$det['signature']]);$rule=$ruleStmt->fetch();
        $otherStmt=$pdo->prepare('SELECT package_signature,package_quantity,package_unit FROM receipt_package_rules WHERE product_key=? AND package_signature<>? AND auto_apply=1 LIMIT 1');
        $otherStmt->execute([$det['product_key'],$det['signature']]);$other=$otherStmt->fetch();
        if($rule){
            $upd->execute([$det['product_key'],$det['quantity'],$det['unit'],$det['signature'],null,(int)$rule['ingredient_id'],(float)$rule['quantity_per_item'],null,(int)$row['id']]);
        }elseif($other){
            $warning='Раньше для этого товара была другая упаковка. Найдено '.$det['quantity'].' '.$det['unit'].' — проверь коэффициент.';
            $upd->execute([$det['product_key'],$det['quantity'],$det['unit'],$det['signature'],$warning,null,null,null,(int)$row['id']]);
        }else{
            $upd->execute([$det['product_key'],$det['quantity'],$det['unit'],$det['signature'],null,$row['ingredient_id']?:null,$row['quantity_per_item']?:null,$row['rule_id']?:null,(int)$row['id']]);
        }
    }
}

function receipt_package_save_rules(int $receiptId,array $post): void
{
    $draft=receipt_draft($receiptId);if(!$draft||$draft['status']!=='draft')return;
    $pdo=db();
    foreach($draft['items'] as $row){
        $iid=(int)$row['id'];if(!isset($post['save_rule'][$iid]))continue;
        $ingredient=(int)($post['ingredient'][$iid]??0);$per=(float)str_replace(',','.',(string)($post['per_item'][$iid]??0));
        if($ingredient<=0||$per<=0)continue;
        $det=receipt_package_detect((string)$row['raw_name']);if(!$det)continue;
        $stmt=$pdo->prepare('INSERT INTO receipt_package_rules(product_key,package_signature,package_quantity,package_unit,ingredient_id,quantity_per_item,auto_apply,last_seen_at) VALUES(?,?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE ingredient_id=VALUES(ingredient_id),quantity_per_item=VALUES(quantity_per_item),package_quantity=VALUES(package_quantity),package_unit=VALUES(package_unit),auto_apply=1,last_seen_at=NOW()');
        $stmt->execute([$det['product_key'],$det['signature'],$det['quantity'],$det['unit'],$ingredient,$per]);
        $pdo->prepare('UPDATE purchase_receipt_items SET package_product_key=?,detected_package_quantity=?,detected_package_unit=?,package_signature=?,package_warning=NULL WHERE id=?')->execute([$det['product_key'],$det['quantity'],$det['unit'],$det['signature'],$iid]);
    }
}

function receipt_package_label(?float $quantity,?string $unit): string
{
    if(!$quantity||!$unit)return '';
    if($unit==='ml'&&$quantity>=1000)return rtrim(rtrim(number_format($quantity/1000,3,',',''),'0'),',').' л';
    if($unit==='g'&&$quantity>=1000)return rtrim(rtrim(number_format($quantity/1000,3,',',''),'0'),',').' кг';
    $labels=['ml'=>'мл','g'=>'г','pcs'=>'шт.'];
    return rtrim(rtrim(number_format($quantity,3,',',''),'0'),',').' '.($labels[$unit]??$unit);
}
