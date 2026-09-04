<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/customer_media.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $type=(string)($_POST['entity_type']??'');$id=(int)($_POST['entity_id']??0);$action=(string)($_POST['action']??'upload');
        if($id<=0||!in_array($type,['product','group'],true))throw new RuntimeException('Не удалось определить позицию.');
        if($type==='product'){
            $stmt=db()->prepare('SELECT image_path FROM customer_product_settings WHERE product_id=?');$stmt->execute([$id]);$old=(string)($stmt->fetchColumn()?:'');
            if($action==='delete'){$path=null;customer_media_delete($old);}else{$path=customer_media_save_upload($_FILES['image']??[],'product-'.$id,$old);}
            $stmt=db()->prepare('INSERT INTO customer_product_settings(product_id,image_path) VALUES(?,?) ON DUPLICATE KEY UPDATE image_path=VALUES(image_path)');$stmt->execute([$id,$path]);
            audit_write('customer_product_image',($path?'Обновлено':'Удалено').' фото PWA товара #'.$id,'product',(string)$id);
        }else{
            $stmt=db()->prepare('SELECT image_path FROM customer_product_groups WHERE id=?');$stmt->execute([$id]);$old=(string)($stmt->fetchColumn()?:'');
            if($action==='delete'){$path=null;customer_media_delete($old);}else{$path=customer_media_save_upload($_FILES['image']??[],'group-'.$id,$old);}
            db()->prepare('UPDATE customer_product_groups SET image_path=? WHERE id=?')->execute([$path,$id]);
            audit_write('customer_group_image',($path?'Обновлено':'Удалено').' фото PWA-группы #'.$id,'customer_product_group',(string)$id);
        }
        flash('success',$action==='delete'?'Фото удалено.':'Фото загружено и автоматически приведено к формату PWA.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_media.php');
}

$groups=db()->query('SELECT id,name,image_path,visible FROM customer_product_groups ORDER BY sort_order,name')->fetchAll();
$grouped=[];foreach(db()->query('SELECT product_id FROM customer_product_group_variants')->fetchAll(PDO::FETCH_COLUMN) as $id)$grouped[(int)$id]=true;
$products=db()->query("SELECT p.id,p.name,p.category,p.sale_price,s.image_path,COALESCE(s.visible,1) visible FROM products p LEFT JOIN customer_product_settings s ON s.product_id=p.id WHERE p.active=1 ORDER BY p.category,p.name")->fetchAll();
page_header('Фото клиентского PWA');
?>
<div class="card"><div class="chart-head"><div><h2>Фотографии меню</h2><p>Можно загружать JPG, PNG или WebP до 12 МБ. Kapouch автоматически поворачивает фото с телефона, масштабирует, обрезает по центру и сохраняет квадрат 1000×1000 для стабильного интерфейса.</p></div><a class="btn primary" href="customer/" target="_blank">Открыть PWA ↗</a></div></div>
<div class="alert info section"><strong>Автомасштабирование:</strong> вертикальные, горизонтальные и квадратные изображения приводятся к одинаковому формату. В карточках PWA дополнительно используется безопасный <code>object-fit: cover</code>, поэтому сетка не ломается.</div>
<?php if($groups):?><div class="card section"><div class="chart-head"><div><h2>Группы напитков</h2><p>Для объединённых объёмов лучше загружать одно общее фото группы — например одно фото «Капучино» для 250/350/450 мл.</p></div></div><div class="pwa-photo-grid"><?php foreach($groups as $g):?><article class="pwa-photo-item"><div class="pwa-photo-preview"><?php if($g['image_path']):?><img src="customer/<?=e($g['image_path'])?>?v=<?=time()?>" alt=""><?php else:?><span>Нет фото</span><?php endif;?></div><div><strong><?=e($g['name'])?></strong><div class="muted"><?=$g['visible']?'Показывается':'Скрыта'?></div></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="entity_type" value="group"><input type="hidden" name="entity_id" value="<?=$g['id']?>"><input type="file" name="image" accept="image/jpeg,image/png,image/webp" required><div class="actions"><button class="btn primary">Загрузить</button><?php if($g['image_path']):?><button class="btn ghost" name="action" value="delete" formnovalidate>Удалить</button><?php endif;?></div></form></article><?php endforeach;?></div></div><?php endif;?>
<div class="card section"><div class="chart-head"><div><h2>Отдельные позиции</h2><p>Фото товара используется для позиции, которая не объединена в PWA-группу. Для размеров внутри группы используется фото группы.</p></div></div><div class="pwa-photo-grid"><?php foreach($products as $p):?><article class="pwa-photo-item <?=isset($grouped[(int)$p['id']])?'is-grouped':''?>"><div class="pwa-photo-preview"><?php if($p['image_path']):?><img src="customer/<?=e($p['image_path'])?>?v=<?=time()?>" alt=""><?php else:?><span>Нет фото</span><?php endif;?></div><div><strong><?=e($p['name'])?></strong><div class="muted"><?=money((float)$p['sale_price'])?> · <?=e((string)$p['category'])?><?=isset($grouped[(int)$p['id']])?' · в группе':''?></div></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="entity_type" value="product"><input type="hidden" name="entity_id" value="<?=$p['id']?>"><input type="file" name="image" accept="image/jpeg,image/png,image/webp" required><div class="actions"><button class="btn primary">Загрузить</button><?php if($p['image_path']):?><button class="btn ghost" name="action" value="delete" formnovalidate>Удалить</button><?php endif;?></div></form></article><?php endforeach;?></div></div>
<style>.pwa-photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}.pwa-photo-item{display:grid;gap:10px;padding:12px;border:1px solid var(--line);border-radius:16px;background:var(--surface)}.pwa-photo-item.is-grouped{opacity:.72}.pwa-photo-preview{aspect-ratio:1;border-radius:14px;overflow:hidden;background:#171412;display:grid;place-items:center;color:var(--muted)}.pwa-photo-preview img{width:100%;height:100%;display:block;object-fit:cover}.pwa-photo-item form{display:grid;gap:8px}.pwa-photo-item input[type=file]{max-width:100%}</style>
<?php page_footer();?>
