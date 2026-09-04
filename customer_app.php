<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/customer_pwa.php';

function customer_app_hex(string $value,string $fallback): string{
    $value=trim($value);return preg_match('/^#[0-9a-fA-F]{6}$/',$value)?strtoupper($value):$fallback;
}
function customer_app_url(string $value): string{
    $value=trim($value);if($value===''||filter_var($value,FILTER_VALIDATE_URL))return $value;throw new RuntimeException('Проверьте ссылки: нужен полный адрес с https://');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='design'){
            $fields=['customer_app_name','customer_app_tagline','customer_hero_title','customer_hero_text','customer_about_title','customer_about_text','customer_pickup_label','customer_support_phone'];
            foreach($fields as $key)set_app_setting($key,trim((string)($_POST[$key]??'')));
            foreach(['customer_website_url','customer_telegram_url','customer_vk_url'] as $key)set_app_setting($key,customer_app_url((string)($_POST[$key]??'')));
            set_app_setting('customer_theme_accent',customer_app_hex((string)($_POST['customer_theme_accent']??''),'#F5B93F'));
            set_app_setting('customer_theme_background',customer_app_hex((string)($_POST['customer_theme_background']??''),'#111111'));
            set_app_setting('customer_theme_surface',customer_app_hex((string)($_POST['customer_theme_surface']??''),'#211B17'));
            set_app_setting('customer_theme_text',customer_app_hex((string)($_POST['customer_theme_text']??''),'#FFF7E8'));
            audit_write('customer_pwa_design_updated','Обновлены настройки внешнего вида клиентского PWA');flash('success','Внешний вид и ссылки клиентского приложения сохранены.');
        }elseif($action==='category_save'){
            $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));if($name==='')throw new RuntimeException('Укажите название категории.');
            $slug=customer_pwa_slug((string)($_POST['slug']??$name));$icon=mb_substr(trim((string)($_POST['icon']??'✨')),0,16);$sort=(int)($_POST['sort_order']??100);$active=isset($_POST['active'])?1:0;
            if($id>0){$stmt=db()->prepare('UPDATE customer_categories SET name=?,slug=?,icon=?,sort_order=?,active=? WHERE id=?');$stmt->execute([$name,$slug,$icon,$sort,$active,$id]);}
            else{$stmt=db()->prepare('INSERT INTO customer_categories(name,slug,icon,sort_order,active) VALUES(?,?,?,?,?)');$stmt->execute([$name,$slug,$icon,$sort,$active]);}
            audit_write('customer_category_saved','Категория клиентского меню: '.$name);flash('success','Категория сохранена.');
        }elseif($action==='products'){
            $products=db()->query('SELECT id FROM products')->fetchAll();$stmt=db()->prepare('INSERT INTO customer_product_settings(product_id,category_id,description,badge,featured,visible,sort_order) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),description=VALUES(description),badge=VALUES(badge),featured=VALUES(featured),visible=VALUES(visible),sort_order=VALUES(sort_order)');
            foreach($products as $p){$id=(int)$p['id'];$cat=(int)($_POST['category'][$id]??0);$stmt->execute([$id,$cat>0?$cat:null,mb_substr(trim((string)($_POST['description'][$id]??'')),0,600)?:null,mb_substr(trim((string)($_POST['badge'][$id]??'')),0,80)?:null,isset($_POST['featured'][$id])?1:0,isset($_POST['visible'][$id])?1:0,(int)($_POST['sort'][$id]??100)]);}
            audit_write('customer_products_updated','Обновлено клиентское меню PWA');flash('success','Настройки товаров клиентского меню сохранены.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_app.php');
}

$s=customer_pwa_settings();$categories=customer_pwa_categories(false);
$products=db()->query("SELECT p.id,p.name,p.category,p.sale_price,s.category_id,s.description,s.badge,s.featured,s.visible,s.sort_order FROM products p LEFT JOIN customer_product_settings s ON s.product_id=p.id ORDER BY p.category,p.name")->fetchAll();
page_header('Клиентское PWA');
?>
<div class="card"><div class="chart-head"><div><h2>Клиентское приложение</h2><p>Полноценная PWA-витрина. Изменения применяются в клиентском приложении без изменения кода.</p></div><a class="btn primary" href="customer/" target="_blank">Открыть PWA ↗</a></div></div>
<div class="card section"><div class="chart-head"><div><h2>Бренд и внешний вид</h2><p>Тексты, цвета, адрес самовывоза и внешние ссылки.</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="design">
<label>Название приложения<input name="customer_app_name" value="<?=e($s['app_name'])?>"></label><label>Слоган<input name="customer_app_tagline" value="<?=e($s['tagline'])?>"></label>
<label>Заголовок главного экрана<input name="customer_hero_title" value="<?=e($s['hero_title'])?>"></label><label>Текст главного экрана<input name="customer_hero_text" value="<?=e($s['hero_text'])?>"></label>
<label>Заголовок «О нас»<input name="customer_about_title" value="<?=e($s['about_title'])?>"></label><label>Текст «О нас»<textarea name="customer_about_text"><?=e($s['about_text'])?></textarea></label>
<label>Точка самовывоза<input name="customer_pickup_label" value="<?=e($s['pickup_label'])?>"></label><label>Телефон поддержки<input name="customer_support_phone" value="<?=e($s['support_phone'])?>"></label>
<label>Сайт<input type="url" name="customer_website_url" value="<?=e($s['website_url'])?>" placeholder="https://..."></label><label>Telegram<input type="url" name="customer_telegram_url" value="<?=e($s['telegram_url'])?>" placeholder="https://t.me/..."></label><label>VK<input type="url" name="customer_vk_url" value="<?=e($s['vk_url'])?>" placeholder="https://vk.com/..."></label>
<label>Акцент<input type="color" name="customer_theme_accent" value="<?=e($s['accent'])?>"></label><label>Фон<input type="color" name="customer_theme_background" value="<?=e($s['background'])?>"></label><label>Карточки<input type="color" name="customer_theme_surface" value="<?=e($s['surface'])?>"></label><label>Текст<input type="color" name="customer_theme_text" value="<?=e($s['text'])?>"></label>
<div><button class="btn primary">Сохранить оформление</button></div></form></div>

<div class="card section"><div class="chart-head"><div><h2>Категории меню</h2><p>Кофе, чай, лимонады, молочные коктейли и любые другие разделы.</p></div></div>
<div class="stack"><?php foreach($categories as $c):?><form method="post" class="form-grid" style="padding:12px;border:1px solid var(--line);border-radius:14px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="category_save"><input type="hidden" name="id" value="<?=$c['id']?>"><label>Название<input name="name" value="<?=e($c['name'])?>"></label><label>Slug<input name="slug" value="<?=e($c['slug'])?>"></label><label>Иконка<input name="icon" value="<?=e((string)$c['icon'])?>"></label><label>Порядок<input type="number" name="sort_order" value="<?=$c['sort_order']?>"></label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="active" value="1" style="width:auto" <?=$c['active']?'checked':''?>> Показывать</label><div><button class="btn ghost">Сохранить</button></div></form><?php endforeach;?>
<form method="post" class="form-grid" style="padding:12px;border:1px dashed var(--line);border-radius:14px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="category_save"><label>Новая категория<input name="name" placeholder="Например, Смузи" required></label><label>Slug<input name="slug" placeholder="smoothies"></label><label>Иконка<input name="icon" value="✨"></label><label>Порядок<input type="number" name="sort_order" value="100"></label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="active" value="1" style="width:auto" checked> Показывать</label><div><button class="btn primary">Добавить категорию</button></div></form></div></div>

<div class="card section"><div class="chart-head"><div><h2>Товары в приложении</h2><p>Категория, описание, бейдж, популярные позиции и порядок отображения.</p></div></div><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="products"><div style="overflow:auto"><table><thead><tr><th>Товар</th><th>Категория PWA</th><th>Описание</th><th>Бейдж</th><th>Порядок</th><th>Популярное</th><th>Показывать</th></tr></thead><tbody><?php foreach($products as $p):?><tr><td><strong><?=e($p['name'])?></strong><div class="muted"><?=money((float)$p['sale_price'])?> · <?=e((string)$p['category'])?></div></td><td><select name="category[<?=$p['id']?>]"><option value="0">Автоматически</option><?php foreach($categories as $c):?><option value="<?=$c['id']?>" <?=((int)$p['category_id']===(int)$c['id'])?'selected':''?>><?=e($c['icon'].' '.$c['name'])?></option><?php endforeach;?></select></td><td><textarea name="description[<?=$p['id']?>]" rows="2"><?=e((string)$p['description'])?></textarea></td><td><input name="badge[<?=$p['id']?>]" value="<?=e((string)$p['badge'])?>" placeholder="Хит"></td><td><input type="number" name="sort[<?=$p['id']?>]" value="<?=e((string)($p['sort_order']??100))?>" style="width:90px"></td><td><input type="checkbox" name="featured[<?=$p['id']?>]" value="1" <?=!empty($p['featured'])?'checked':''?>></td><td><input type="checkbox" name="visible[<?=$p['id']?>]" value="1" <?=($p['visible']===null||(int)$p['visible']===1)?'checked':''?>></td></tr><?php endforeach;?></tbody></table></div><div style="margin-top:14px"><button class="btn primary">Сохранить товары</button></div></form></div>
<div class="alert warning section"><strong>PWA:</strong> приложение устанавливается на главный экран, работает в standalone-режиме и кеширует интерфейс. Каталог при наличии сети всегда обновляется с Kapouch.</div>
<?php page_footer();?>
