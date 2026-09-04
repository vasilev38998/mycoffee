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
<div class="card"><div class="chart-head"><div><h2>Клиентское приложение</h2><p>Единый центр управления клиентским PWA: оформление, меню, объёмы, фотографии, видимость и push-рассылки.</p></div><a class="btn primary" href="customer/" target="_blank">Открыть PWA ↗</a></div></div>

<div class="pwa-settings-nav section">
  <a class="pwa-settings-tile active" href="#design"><span>01</span><strong>Оформление</strong><small>Бренд, тексты, цвета и ссылки</small></a>
  <a class="pwa-settings-tile" href="#catalog"><span>02</span><strong>Каталог</strong><small>Категории, описания и популярное</small></a>
  <a class="pwa-settings-tile" href="customer_groups.php"><span>03</span><strong>Группы и объёмы</strong><small>250 / 350 / 450 мл и варианты</small></a>
  <a class="pwa-settings-tile" href="customer_media.php"><span>04</span><strong>Фото</strong><small>Загрузка и автомасштабирование</small></a>
  <a class="pwa-settings-tile" href="pwa_visibility.php"><span>05</span><strong>Видимость</strong><small>Скрыть товар, объём или группу</small></a>
  <a class="pwa-settings-tile" href="push_notifications.php"><span>06</span><strong>Push</strong><small>Уведомления и рассылки</small></a>
</div>

<div class="alert info section"><strong>Один вход в PWA.</strong> В боковом меню «Система» теперь остаётся только «Клиентское PWA». Все остальные клиентские настройки открываются отсюда и больше не перегружают системное меню.</div>

<div class="card section" id="design"><div class="chart-head"><div><h2>Бренд и внешний вид</h2><p>Тексты, цвета, адрес самовывоза и внешние ссылки.</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="design">
<label>Название приложения<input name="customer_app_name" value="<?=e($s['app_name'])?>"></label><label>Слоган<input name="customer_app_tagline" value="<?=e($s['tagline'])?>"></label>
<label>Заголовок главного экрана<input name="customer_hero_title" value="<?=e($s['hero_title'])?>"></label><label>Текст главного экрана<input name="customer_hero_text" value="<?=e($s['hero_text'])?>"></label>
<label>Заголовок «О нас»<input name="customer_about_title" value="<?=e($s['about_title'])?>"></label><label>Текст «О нас»<textarea name="customer_about_text"><?=e($s['about_text'])?></textarea></label>
<label>Точка самовывоза<input name="customer_pickup_label" value="<?=e($s['pickup_label'])?>"></label><label>Телефон поддержки<input name="customer_support_phone" value="<?=e($s['support_phone'])?>"></label>
<label>Сайт<input type="url" name="customer_website_url" value="<?=e($s['website_url'])?>" placeholder="https://..."></label><label>Telegram<input type="url" name="customer_telegram_url" value="<?=e($s['telegram_url'])?>" placeholder="https://t.me/..."></label><label>VK<input type="url" name="customer_vk_url" value="<?=e($s['vk_url'])?>" placeholder="https://vk.com/..."></label>
<label>Акцент<input type="color" name="customer_theme_accent" value="<?=e($s['accent'])?>"></label><label>Фон<input type="color" name="customer_theme_background" value="<?=e($s['background'])?>"></label><label>Карточки<input type="color" name="customer_theme_surface" value="<?=e($s['surface'])?>"></label><label>Текст<input type="color" name="customer_theme_text" value="<?=e($s['text'])?>"></label>
<div><button class="btn primary">Сохранить оформление</button></div></form></div>

<div class="card section" id="catalog"><div class="chart-head"><div><h2>Категории меню</h2><p>Кофе, чай, лимонады, молочные коктейли и любые другие разделы.</p></div></div>
<div class="stack"><?php foreach($categories as $c):?><form method="post" class="form-grid" style="padding:12px;border:1px solid var(--line);border-radius:14px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="category_save"><input type="hidden" name="id" value="<?=$c['id']?>"><label>Название<input name="name" value="<?=e($c['name'])?>"></label><label>Slug<input name="slug" value="<?=e($c['slug'])?>"></label><label>Иконка<input name="icon" value="<?=e((string)$c['icon'])?>"></label><label>Порядок<input type="number" name="sort_order" value="<?=$c['sort_order']?>"></label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="active" value="1" style="width:auto" <?=$c['active']?'checked':''?>> Показывать</label><div><button class="btn ghost">Сохранить</button></div></form><?php endforeach;?>
<form method="post" class="form-grid" style="padding:12px;border:1px dashed var(--line);border-radius:14px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="category_save"><label>Новая категория<input name="name" placeholder="Например, Смузи" required></label><label>Slug<input name="slug" placeholder="smoothies"></label><label>Иконка<input name="icon" value="✨"></label><label>Порядок<input type="number" name="sort_order" value="100"></label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="active" value="1" style="width:auto" checked> Показывать</label><div><button class="btn primary">Добавить категорию</button></div></form></div></div>

<div class="card section"><div class="chart-head"><div><h2>Товары в приложении</h2><p>Категория, описание, бейдж, популярные позиции и порядок отображения.</p></div><div class="actions"><a class="btn ghost" href="customer_groups.php">Объёмы</a><a class="btn ghost" href="customer_media.php">Фото</a><a class="btn ghost" href="pwa_visibility.php">Видимость</a></div></div><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="products"><div style="overflow:auto"><table><thead><tr><th>Товар</th><th>Категория PWA</th><th>Описание</th><th>Бейдж</th><th>Порядок</th><th>Популярное</th><th>Показывать</th></tr></thead><tbody><?php foreach($products as $p):?><tr><td><strong><?=e($p['name'])?></strong><div class="muted"><?=money((float)$p['sale_price'])?> · <?=e((string)$p['category'])?></div></td><td><select name="category[<?=$p['id']?>]"><option value="0">Автоматически</option><?php foreach($categories as $c):?><option value="<?=$c['id']?>" <?=((int)$p['category_id']===(int)$c['id'])?'selected':''?>><?=e($c['icon'].' '.$c['name'])?></option><?php endforeach;?></select></td><td><textarea name="description[<?=$p['id']?>]" rows="2"><?=e((string)$p['description'])?></textarea></td><td><input name="badge[<?=$p['id']?>]" value="<?=e((string)$p['badge'])?>" placeholder="Хит"></td><td><input type="number" name="sort[<?=$p['id']?>]" value="<?=e((string)($p['sort_order']??100))?>" style="width:90px"></td><td><input type="checkbox" name="featured[<?=$p['id']?>]" value="1" <?=!empty($p['featured'])?'checked':''?>></td><td><input type="checkbox" name="visible[<?=$p['id']?>]" value="1" <?=($p['visible']===null||(int)$p['visible']===1)?'checked':''?>></td></tr><?php endforeach;?></tbody></table></div><div style="margin-top:14px"><button class="btn primary">Сохранить товары</button></div></form></div>

<div class="card section"><div class="chart-head"><div><h2>Дополнительные настройки PWA</h2><p>Они остаются отдельными экранами внутри единого раздела, чтобы большие таблицы и загрузка фото не делали одну страницу слишком тяжёлой.</p></div></div><div class="pwa-settings-actions"><a href="customer_groups.php"><strong>Группы и объёмы</strong><span>Объединить отдельные карточки Evotor в один напиток с выбором размера →</span></a><a href="customer_media.php"><strong>Фотографии</strong><span>Загрузить фото товаров и групп с автоматическим масштабированием →</span></a><a href="pwa_visibility.php"><strong>Видимость меню</strong><span>Скрыть отдельную позицию, объём или группу →</span></a><a href="push_notifications.php"><strong>Push-уведомления</strong><span>Автоматические уведомления и сегментированные рассылки →</span></a></div></div>

<div class="alert warning section"><strong>PWA:</strong> приложение устанавливается на главный экран, работает в standalone-режиме и кеширует интерфейс. Каталог при наличии сети всегда обновляется с Kapouch.</div>
<style>.pwa-settings-nav{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.pwa-settings-tile{display:grid;gap:5px;padding:16px;border:1px solid var(--line);border-radius:16px;background:var(--surface);color:inherit;text-decoration:none}.pwa-settings-tile span{font-size:10px;font-weight:900;color:var(--accent,#8b5e3c);letter-spacing:.12em}.pwa-settings-tile strong{font-size:15px}.pwa-settings-tile small{color:var(--muted);line-height:1.4}.pwa-settings-tile:hover{border-color:rgba(126,84,52,.45);transform:translateY(-1px)}.pwa-settings-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.pwa-settings-actions a{display:grid;gap:5px;padding:14px;border:1px solid var(--line);border-radius:14px;color:inherit;text-decoration:none}.pwa-settings-actions span{color:var(--muted);font-size:12px;line-height:1.45}@media(max-width:900px){.pwa-settings-nav{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.pwa-settings-nav,.pwa-settings-actions{grid-template-columns:1fr}}</style>
<?php page_footer();?>
