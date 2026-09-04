<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='product_visibility'){
            $productId=(int)($_POST['product_id']??0);$visible=(int)($_POST['visible']??0)===1?1:0;
            if($productId<=0)throw new RuntimeException('Товар не найден.');
            $stmt=db()->prepare('INSERT INTO customer_product_settings(product_id,visible) VALUES(?,?) ON DUPLICATE KEY UPDATE visible=VALUES(visible)');$stmt->execute([$productId,$visible]);
            audit_write('customer_product_visibility',($visible?'Показан':'Скрыт').' товар PWA #'.$productId,'product',(string)$productId);
            flash('success',$visible?'Позиция снова показывается в PWA.':'Позиция скрыта из PWA.');
        }elseif($action==='group_visibility'){
            $groupId=(int)($_POST['group_id']??0);$visible=(int)($_POST['visible']??0)===1?1:0;
            if($groupId<=0)throw new RuntimeException('Группа не найдена.');
            $stmt=db()->prepare('UPDATE customer_product_groups SET visible=? WHERE id=?');$stmt->execute([$visible,$groupId]);
            audit_write('customer_group_visibility',($visible?'Показана':'Скрыта').' PWA-группа #'.$groupId,'customer_product_group',(string)$groupId);
            flash('success',$visible?'Группа снова показывается в PWA.':'Группа полностью скрыта из PWA.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('pwa_visibility.php');
}

$groups=db()->query("SELECT g.id,g.name,g.visible,g.sort_order,COUNT(v.product_id) variants
    FROM customer_product_groups g LEFT JOIN customer_product_group_variants v ON v.group_id=g.id
    GROUP BY g.id,g.name,g.visible,g.sort_order ORDER BY g.sort_order,g.name")->fetchAll();
$products=db()->query("SELECT p.id,p.name,p.category,p.sale_price,COALESCE(s.visible,1) visible,
    v.group_id,g.name group_name,v.variant_label
    FROM products p
    LEFT JOIN customer_product_settings s ON s.product_id=p.id
    LEFT JOIN customer_product_group_variants v ON v.product_id=p.id
    LEFT JOIN customer_product_groups g ON g.id=v.group_id
    WHERE p.active=1
    ORDER BY COALESCE(g.sort_order,9999),g.name,p.category,p.name")->fetchAll();
$shown=0;$hidden=0;foreach($products as $p){if((int)$p['visible']===1)$shown++;else$hidden++;}
page_header('Видимость меню PWA');
?>
<div class="three-col">
  <div class="insight-card"><div class="kicker">Показываются</div><strong><?=$shown?></strong><p>Активные товарные позиции PWA.</p></div>
  <div class="insight-card"><div class="kicker">Скрыты</div><strong><?=$hidden?></strong><p>Не видны клиентам, но остаются в Kapouch/Evotor.</p></div>
  <div class="insight-card"><div class="kicker">Группы</div><strong><?=count($groups)?></strong><p>Группы напитков с выбором объёма.</p></div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Группы напитков</h2><p>Скрытие группы убирает карточку напитка целиком со всеми объёмами. Данные в Эвоторе не меняются.</p></div><a class="btn ghost" href="customer_groups.php">Настроить объёмы →</a></div>
<div style="overflow:auto"><table><thead><tr><th>Группа</th><th>Вариантов</th><th>Статус</th><th></th></tr></thead><tbody><?php foreach($groups as $g):?><tr><td><strong><?=e($g['name'])?></strong></td><td><?=$g['variants']?></td><td><?=(int)$g['visible']===1?'<span class="badge ok">Показывается</span>':'<span class="badge bad">Скрыта</span>'?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="group_visibility"><input type="hidden" name="group_id" value="<?=$g['id']?>"><input type="hidden" name="visible" value="<?=(int)$g['visible']===1?0:1?>"><button class="btn <?=(int)$g['visible']===1?'ghost':'primary'?>"><?=(int)$g['visible']===1?'Скрыть группу':'Показать группу'?></button></form></td></tr><?php endforeach;?><?php if(!$groups):?><tr><td colspan="4" class="muted">Групп пока нет.</td></tr><?php endif;?></tbody></table></div></div>

<div class="card section"><div class="chart-head"><div><h2>Отдельные позиции и объёмы</h2><p>Можно скрыть любой конкретный товар. Если он входит в группу, исчезнет только этот объём. Остальные размеры продолжат показываться.</p></div><a class="btn ghost" href="customer_app.php">Полные настройки PWA →</a></div>
<div style="overflow:auto"><table><thead><tr><th>Позиция</th><th>Группа / объём</th><th>Цена</th><th>Статус</th><th></th></tr></thead><tbody><?php foreach($products as $p):?><tr style="<?=(int)$p['visible']===1?'':'opacity:.62'?>"><td><strong><?=e($p['name'])?></strong><div class="muted"><?=e((string)($p['category']?:'Без категории'))?></div></td><td><?php if($p['group_id']):?><strong><?=e($p['group_name'])?></strong><div class="muted"><?=e($p['variant_label'])?></div><?php else:?><span class="muted">Отдельная позиция</span><?php endif;?></td><td><?=money((float)$p['sale_price'])?></td><td><?=(int)$p['visible']===1?'<span class="badge ok">Показывается</span>':'<span class="badge bad">Скрыта</span>'?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="product_visibility"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="visible" value="<?=(int)$p['visible']===1?0:1?>"><button class="btn <?=(int)$p['visible']===1?'ghost':'primary'?>"><?=(int)$p['visible']===1?'Скрыть':'Показать'?></button></form></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="alert warning section"><strong>Важно:</strong> скрытая позиция не удаляется из Kapouch и Эвотора. Она только исключается из клиентского каталога и больше не принимается сервером в новом PWA-заказе.</div>
<?php page_footer();?>
