<?php
declare(strict_types=1);

function recipe_templates(): array
{
    return [
        'cappuccino'=>[
            'label'=>'Капучино',
            'sizes'=>[
                250=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>18],['ingredient'=>'Молоко','unit'=>'ml','qty'=>170]],
                350=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>18],['ingredient'=>'Молоко','unit'=>'ml','qty'=>260]],
                450=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>36],['ingredient'=>'Молоко','unit'=>'ml','qty'=>330]],
            ],
        ],
        'latte'=>[
            'label'=>'Латте',
            'sizes'=>[
                250=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>18],['ingredient'=>'Молоко','unit'=>'ml','qty'=>190]],
                350=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>18],['ingredient'=>'Молоко','unit'=>'ml','qty'=>285]],
                450=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>36],['ingredient'=>'Молоко','unit'=>'ml','qty'=>350]],
            ],
        ],
        'americano'=>[
            'label'=>'Американо',
            'sizes'=>[
                250=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>18],['ingredient'=>'Вода','unit'=>'ml','qty'=>200]],
                350=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>18],['ingredient'=>'Вода','unit'=>'ml','qty'=>300]],
                450=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>36],['ingredient'=>'Вода','unit'=>'ml','qty'=>380]],
            ],
        ],
        'flat_white'=>[
            'label'=>'Флэт уайт',
            'sizes'=>[
                250=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>36],['ingredient'=>'Молоко','unit'=>'ml','qty'=>160]],
                350=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>36],['ingredient'=>'Молоко','unit'=>'ml','qty'=>250]],
                450=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>54],['ingredient'=>'Молоко','unit'=>'ml','qty'=>320]],
            ],
        ],
        'raf'=>[
            'label'=>'Раф',
            'sizes'=>[
                250=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>18],['ingredient'=>'Сливки 10%','unit'=>'ml','qty'=>150],['ingredient'=>'Ванильный сахар','unit'=>'g','qty'=>10]],
                350=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>18],['ingredient'=>'Сливки 10%','unit'=>'ml','qty'=>240],['ingredient'=>'Ванильный сахар','unit'=>'g','qty'=>15]],
                450=>[['ingredient'=>'Кофе зерно','unit'=>'g','qty'=>36],['ingredient'=>'Сливки 10%','unit'=>'ml','qty'=>320],['ingredient'=>'Ванильный сахар','unit'=>'g','qty'=>20]],
            ],
        ],
    ];
}

function recipe_template_aliases(string $canonical): array
{
    return match($canonical){
        'Кофе зерно'=>['кофе зерно','кофе в зернах','зерно','кофейное зерно'],
        'Молоко'=>['молоко'],
        'Вода'=>['вода'],
        'Сливки 10%'=>['сливки 10%','сливки'],
        'Ванильный сахар'=>['ванильный сахар'],
        default=>[mb_strtolower($canonical)],
    };
}

function recipe_template_find_ingredient(string $canonical,string $unit): ?array
{
    $rows=db()->query('SELECT * FROM ingredients ORDER BY id')->fetchAll();
    $aliases=recipe_template_aliases($canonical);
    foreach($rows as $row){
        if((string)$row['unit']!==$unit)continue;
        $name=mb_strtolower(trim((string)$row['name']));
        foreach($aliases as $alias){if($name===mb_strtolower($alias))return $row;}
    }
    return null;
}

function recipe_template_ensure_ingredient(string $canonical,string $unit): array
{
    $found=recipe_template_find_ingredient($canonical,$unit);
    if($found)return $found;
    $stmt=db()->prepare('INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity) VALUES(?,?,0,1,0)');
    $stmt->execute([$canonical,$unit]);
    $id=(int)db()->lastInsertId();
    $stmt=db()->prepare('SELECT * FROM ingredients WHERE id=?');$stmt->execute([$id]);
    return $stmt->fetch();
}

function recipe_template_apply(int $productId,string $templateKey,int $size,bool $includeCup=true,bool $includeStraw=true,bool $replace=false): array
{
    $templates=recipe_templates();
    if(!isset($templates[$templateKey]['sizes'][$size]))throw new RuntimeException('Не найден выбранный шаблон техкарты.');
    $items=$templates[$templateKey]['sizes'][$size];
    if($includeCup)$items[]=['ingredient'=>'Стакан '.$size.' мл','unit'=>'pcs','qty'=>1];
    if($includeStraw)$items[]=['ingredient'=>'Трубочка','unit'=>'pcs','qty'=>1];

    $pdo=db();$pdo->beginTransaction();
    try{
        if($replace){$stmt=$pdo->prepare('DELETE FROM recipe_items WHERE product_id=?');$stmt->execute([$productId]);}
        $saved=[];
        foreach($items as $item){
            $ingredient=recipe_template_ensure_ingredient((string)$item['ingredient'],(string)$item['unit']);
            $stmt=$pdo->prepare('INSERT INTO recipe_items(product_id,ingredient_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)');
            $stmt->execute([$productId,(int)$ingredient['id'],(float)$item['qty']]);
            $saved[]=['name'=>$ingredient['name'],'unit'=>$ingredient['unit'],'qty'=>(float)$item['qty']];
        }
        $pdo->commit();
        return ['template'=>$templates[$templateKey]['label'],'size'=>$size,'items'=>$saved];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
