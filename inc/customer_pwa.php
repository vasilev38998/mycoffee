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

function customer_pwa_catalog(): array
{
    $settings=customer_pwa_settings();$categories=customer_pwa_categories(true);$byId=[];$bySlug=[];
    foreach($categories as $c){$byId[(int)$c['id']]=$c;$bySlug[(string)$c['slug']]=$c;}
    $rows=db()->query("SELECT p.id,p.name,p.category,p.sale_price,s.category_id,s.description,s.badge,s.featured,s.visible,s.sort_order
        FROM products p LEFT JOIN customer_product_settings s ON s.product_id=p.id
        WHERE p.active=1 AND p.sale_price>0 AND COALESCE(s.visible,1)=1
        ORDER BY COALESCE(s.featured,0) DESC,COALESCE(s.sort_order,100),p.name")->fetchAll();
    $products=[];
    foreach($rows as $r){
        $cat=null;
        if(!empty($r['category_id'])){
            $cat=$byId[(int)$r['category_id']]??null;
            if(!$cat)continue;
        }
        if(!$cat){$slug=customer_pwa_guess_category((string)($r['category']?:$r['name']));$cat=$bySlug[$slug]??($bySlug['other']??null);}
        if(!$cat)continue;
        $products[]=[
            'id'=>(int)$r['id'],'name'=>(string)$r['name'],'price'=>(float)$r['sale_price'],
            'description'=>trim((string)($r['description']??''))?:'Любимый напиток, приготовленный специально для вас.',
            'badge'=>trim((string)($r['badge']??'')),'featured'=>(bool)$r['featured'],
            'category_id'=>(int)$cat['id'],'category'=>(string)$cat['name'],'category_slug'=>(string)$cat['slug'],'category_icon'=>(string)$cat['icon'],
        ];
    }
    return ['settings'=>$settings,'categories'=>array_map(static fn($c)=>['id'=>(int)$c['id'],'name'=>(string)$c['name'],'slug'=>(string)$c['slug'],'icon'=>(string)$c['icon']],$categories),'products'=>$products];
}
