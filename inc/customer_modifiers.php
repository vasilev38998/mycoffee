<?php
declare(strict_types=1);

function customer_modifier_groups(bool $activeOnly=false): array
{
    $where=$activeOnly?'WHERE active=1':'';
    return db()->query("SELECT * FROM customer_modifier_groups {$where} ORDER BY sort_order,name,id")->fetchAll();
}

function customer_modifier_options(int $groupId,bool $activeOnly=false): array
{
    $sql="SELECT o.*,p.name product_name,p.sale_price,p.active product_active,(SELECT ep.evotor_product_id FROM evotor_products ep WHERE ep.local_product_id=p.id ORDER BY ep.id LIMIT 1) evotor_product_id FROM customer_modifier_options o JOIN products p ON p.id=o.product_id WHERE o.modifier_group_id=?";
    if($activeOnly)$sql.=' AND o.active=1 AND p.active=1 AND p.sale_price>=0';
    $sql.=' ORDER BY o.sort_order,p.name,o.id';
    $stmt=db()->prepare($sql);$stmt->execute([$groupId]);return $stmt->fetchAll();
}

function customer_modifier_display_group_for_product(int $productId): ?int
{
    $stmt=db()->prepare('SELECT group_id FROM customer_product_group_variants WHERE product_id=? LIMIT 1');$stmt->execute([$productId]);$id=(int)($stmt->fetchColumn()?:0);return $id>0?$id:null;
}

function customer_modifier_group_ids_for_product(int $productId,?int $displayGroupId=null): array
{
    if($displayGroupId===null)$displayGroupId=customer_modifier_display_group_for_product($productId);
    $ids=[];
    if($displayGroupId){$stmt=db()->prepare('SELECT modifier_group_id FROM customer_display_group_modifier_groups WHERE product_group_id=? ORDER BY sort_order,modifier_group_id');$stmt->execute([$displayGroupId]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[(int)$id]=true;}
    $stmt=db()->prepare('SELECT modifier_group_id FROM customer_product_modifier_groups WHERE product_id=? ORDER BY sort_order,modifier_group_id');$stmt->execute([$productId]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[(int)$id]=true;
    return array_keys($ids);
}

function customer_modifier_groups_for_product(int $productId,?int $displayGroupId=null): array
{
    $ids=customer_modifier_group_ids_for_product($productId,$displayGroupId);if(!$ids)return [];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT * FROM customer_modifier_groups WHERE active=1 AND id IN ({$ph}) ORDER BY sort_order,name,id");$stmt->execute($ids);$groups=[];
    foreach($stmt->fetchAll() as $g){
        $options=[];foreach(customer_modifier_options((int)$g['id'],true) as $o){$options[]=['id'=>(int)$o['id'],'product_id'=>(int)$o['product_id'],'evotor_product_id'=>$o['evotor_product_id']!==null?(string)$o['evotor_product_id']:null,'label'=>trim((string)($o['label']??''))?:((string)$o['product_name']),'product_name'=>(string)$o['product_name'],'price'=>(float)$o['sale_price']];}
        if(!$options)continue;$min=max(0,(int)$g['min_select']);$max=max(1,(int)$g['max_select']);$max=min($max,count($options));$min=min($min,$max);
        $groups[]=['id'=>(int)$g['id'],'name'=>(string)$g['name'],'min_select'=>$min,'max_select'=>$max,'required'=>$min>0,'options'=>$options];
    }
    return $groups;
}

function customer_modifier_catalog_map(array $products): array
{
    $map=[];foreach($products as $p){$displayGroupId=!empty($p['group_id'])?(int)$p['group_id']:null;foreach(($p['variants']??[]) as $v){$pid=(int)$v['id'];$map[(string)$pid]=customer_modifier_groups_for_product($pid,$displayGroupId);}}return $map;
}

function customer_modifier_validate_selection(int $baseProductId,array $selectedProductIds): array
{
    $displayGroupId=customer_modifier_display_group_for_product($baseProductId);$groups=customer_modifier_groups_for_product($baseProductId,$displayGroupId);$selected=array_values(array_unique(array_filter(array_map('intval',$selectedProductIds),static fn($v)=>$v>0)));$optionByProduct=[];$groupById=[];
    foreach($groups as $g){$groupById[(int)$g['id']]=$g;foreach($g['options'] as $o)$optionByProduct[(int)$o['product_id']]=['group_id'=>(int)$g['id'],'option'=>$o];}
    foreach($selected as $pid)if(!isset($optionByProduct[$pid]))throw new RuntimeException('Один из выбранных модификаторов недоступен для этого напитка. Обновите меню.');
    $counts=[];foreach($selected as $pid){$gid=$optionByProduct[$pid]['group_id'];$counts[$gid]=($counts[$gid]??0)+1;}
    foreach($groups as $g){$gid=(int)$g['id'];$count=(int)($counts[$gid]??0);if($count<(int)$g['min_select'])throw new RuntimeException('Выберите «'.$g['name'].'».');if($count>(int)$g['max_select'])throw new RuntimeException('Для «'.$g['name'].'» можно выбрать не больше '.$g['max_select'].'.');}
    $validated=[];foreach($selected as $pid){$entry=$optionByProduct[$pid];$validated[]=['group_id'=>$entry['group_id'],'group_name'=>(string)$groupById[$entry['group_id']]['name'],'product_id'=>$pid,'evotor_product_id'=>$entry['option']['evotor_product_id']??null,'label'=>(string)$entry['option']['label'],'product_name'=>(string)$entry['option']['product_name'],'price'=>(float)$entry['option']['price']];}
    return $validated;
}
