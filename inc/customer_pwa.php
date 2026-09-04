<?php
declare(strict_types=1);

function customer_pwa_settings(): array
{
    return [
        'app_name'=>(string)app_setting('customer_app_name',(string)app_setting('coffee_name','Kapouch')),
        'tagline'=>(string)app_setting('customer_app_tagline','Кофе с собой, который делает день'),
        'hero_title'=>(string)app_setting('customer_hero_title','Закажи любимый кофе заранее'),
        'hero_text'=>(string)app_setting('customer_hero_text','Выбирай напитки, копи бонусы и забирай заказ без ожидания.'),
        'about_title'=>(string)app_setting('customer_about_title','Мы про кофе и людей'),
        'about_text'=>(string)app_setting('customer_about_text','Мы тщательно отбираем зерно, готовим с любовью и заботимся о каждой детали.'),
        'pickup_label'=>(string)app_setting('customer_pickup_label','Самовывоз из кофейни'),
        'support_phone'=>(string)app_setting('customer_support_phone',''),
        'website_url'=>(string)app_setting('customer_website_url',''),
        'telegram_url'=>(string)app_setting('customer_telegram_url',''),
        'vk_url'=>(string)app_setting('customer_vk_url',''),
        'accent'=>(string)app_setting('customer_theme_accent','#F5B93F'),
        'background'=>(string)app_setting('customer_theme_background','#111111'),
        'surface'=>(string)app_setting('customer_theme_surface','#211B17'),
        'text'=>(string)app_setting('customer_theme_text','#FFF7E8'),
    ];
}

function customer_pwa_slug(string $value): string
{
    $value=mb_strtolower(trim($value));
    $map=['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
    $value=strtr($value,$map);$value=preg_replace('/[^a-z0-9]+/','-',$value)??'';return trim($value,'-')?:'category';
}

function customer_pwa_categories(bool $onlyActive=true): array
{
    $where=$onlyActive?'WHERE active=1':'';
    return db()->query("SELECT * FROM customer_categories {$where} ORDER BY sort_order,name,id")->fetchAll();
}

function customer_pwa_groups(bool $onlyVisible=false): array
{
    $where=$onlyVisible?'WHERE visible=1':'';
    return db()->query("SELECT * FROM customer_product_groups {$where} ORDER BY sort_order,name,id")->fetchAll();
}

function customer_pwa_guess_category(string $name): string
{
    $s=mb_strtolower($name);
    if(str_contains($s,'коф')||str_contains($s,'капуч')||str_contains($s,'латт')||str_contains($s,'раф')||str_contains($s,'эспресс')||str_contains($s,'америк'))return 'coffee';
    if(str_contains($s,'чай'))return 'tea';
    if(str_contains($s,'лимонад'))return 'lemonades';
    if(str_contains($s,'коктейл')||str_contains($s,'милкшейк'))return 'milkshakes';
    if(str_contains($s,'выпеч')||str_contains($s,'круас')||str_contains($s,'десерт'))return 'bakery';
    return 'other';
}

function customer_pwa_category_for(?int $categoryId,string $fallback,array $byId,array $bySlug): ?array
{
    if($categoryId){$cat=$byId[$categoryId]??null;return $cat?:null;}
    $slug=customer_pwa_guess_category($fallback);return $bySlug[$slug]??($bySlug['other']??null);
}

function customer_pwa_catalog(): array
{
    $settings=customer_pwa_settings();$categories=customer_pwa_categories(true);$byId=[];$bySlug=[];
    foreach($categories as $c){$byId[(int)$c['id']]=$c;$bySlug[(string)$c['slug']]=$c;}

    $variantRows=db()->query("SELECT g.id group_id,g.name group_name,g.category_id group_category_id,g.description group_description,g.badge group_badge,g.featured group_featured,g.visible group_visible,g.sort_order group_sort,
        v.product_id,v.variant_label,v.sort_order variant_sort,v.is_default,
        p.name product_name,p.category product_category,p.sale_price,p.active,
        s.visible product_visible
        FROM customer_product_groups g
        JOIN customer_product_group_variants v ON v.group_id=g.id
        JOIN products p ON p.id=v.product_id
        LEFT JOIN customer_product_settings s ON s.product_id=p.id
        WHERE g.visible=1 AND p.active=1 AND p.sale_price>0 AND COALESCE(s.visible,1)=1
        ORDER BY g.sort_order,g.id,v.is_default DESC,v.sort_order,p.sale_price,p.name")->fetchAll();

    $products=[];$groupedProductIds=[];$groups=[];
    foreach($variantRows as $r){
        $gid=(int)$r['group_id'];$pid=(int)$r['product_id'];$groupedProductIds[$pid]=true;
        if(!isset($groups[$gid])){
            $cat=customer_pwa_category_for($r['group_category_id']?(int)$r['group_category_id']:null,(string)($r['product_category']?:$r['group_name']),$byId,$bySlug);
            if(!$cat){$groups[$gid]=null;continue;}
            $groups[$gid]=[
                'key'=>'g'.$gid,'group_id'=>$gid,'id'=>$pid,'name'=>(string)$r['group_name'],'price'=>(float)$r['sale_price'],
                'description'=>trim((string)($r['group_description']??''))?:'Выберите подходящий объём.',
                'badge'=>trim((string)($r['group_badge']??'')),'featured'=>(bool)$r['group_featured'],
                'category_id'=>(int)$cat['id'],'category'=>(string)$cat['name'],'category_slug'=>(string)$cat['slug'],'category_icon'=>(string)$cat['icon'],
                'variants'=>[],'default_product_id'=>$pid,'sort_order'=>(int)$r['group_sort']
            ];
        }
        if($groups[$gid]===null)continue;
        $variant=['id'=>$pid,'label'=>(string)$r['variant_label'],'price'=>(float)$r['sale_price'],'product_name'=>(string)$r['product_name'],'is_default'=>(bool)$r['is_default']];
        $groups[$gid]['variants'][]=$variant;
        if((bool)$r['is_default']||count($groups[$gid]['variants'])===1){$groups[$gid]['id']=$pid;$groups[$gid]['default_product_id']=$pid;$groups[$gid]['price']=(float)$r['sale_price'];}
    }
    foreach($groups as $group)if(is_array($group)&&!empty($group['variants']))$products[]=$group;

    $rows=db()->query("SELECT p.id,p.name,p.category,p.sale_price,s.category_id,s.description,s.badge,s.featured,s.visible,s.sort_order
        FROM products p LEFT JOIN customer_product_settings s ON s.product_id=p.id
        WHERE p.active=1 AND p.sale_price>0 AND COALESCE(s.visible,1)=1
        ORDER BY COALESCE(s.featured,0) DESC,COALESCE(s.sort_order,100),p.name")->fetchAll();
    foreach($rows as $r){
        $pid=(int)$r['id'];if(isset($groupedProductIds[$pid]))continue;
        $cat=customer_pwa_category_for($r['category_id']?(int)$r['category_id']:null,(string)($r['category']?:$r['name']),$byId,$bySlug);if(!$cat)continue;
        $products[]=[
            'key'=>'p'.$pid,'group_id'=>null,'id'=>$pid,'default_product_id'=>$pid,'name'=>(string)$r['name'],'price'=>(float)$r['sale_price'],
            'description'=>trim((string)($r['description']??''))?:'Любимый напиток, приготовленный специально для вас.',
            'badge'=>trim((string)($r['badge']??'')),'featured'=>(bool)$r['featured'],'sort_order'=>(int)($r['sort_order']??100),
            'category_id'=>(int)$cat['id'],'category'=>(string)$cat['name'],'category_slug'=>(string)$cat['slug'],'category_icon'=>(string)$cat['icon'],
            'variants'=>[['id'=>$pid,'label'=>'Стандарт','price'=>(float)$r['sale_price'],'product_name'=>(string)$r['name'],'is_default'=>true]],
        ];
    }
    usort($products,static function(array $a,array $b): int{return ((int)!$b['featured']<=>(int)!$a['featured'])?:((int)$a['sort_order']<=>(int)$b['sort_order'])?:strnatcasecmp((string)$a['name'],(string)$b['name']);});
    return ['settings'=>$settings,'categories'=>array_map(static fn($c)=>['id'=>(int)$c['id'],'name'=>(string)$c['name'],'slug'=>(string)$c['slug'],'icon'=>(string)$c['icon']],$categories),'products'=>$products];
}
