<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/customer_pwa.php';

function customer_group_variant_label(string $name): string{
    if(preg_match('/\b(\d{2,4})\s*(мл|ml)\b/ui',$name,$m))return $m[1].' мл';
    if(preg_match('/\b(\d+(?:[.,]\d+)?)\s*(л|l)\b/ui',$name,$m))return str_replace('.',',',$m[1]).' л';
    return 'Вариант';
}
function customer_group_base_name(string $name): string{
    $base=preg_replace('/\s*[\(\[]?\s*\d{2,4}\s*(мл|ml)\s*[\)\]]?\s*/ui',' ',$name)??$name;
    $base=preg_replace('/\s*[\(\[]?\s*\d+(?:[.,]\d+)?\s*(л|l)\s*[\)\]]?\s*/ui',' ',$base)??$base;
    return trim(preg_replace('/\s{2,}/u',' ',$base)??$base," -–—");
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='group_save'){
            $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));if($name==='')throw new RuntimeException('Укажите название группы.');
            $cat=(int)($_POST['category_id']??0);$description=mb_substr(trim((string)($_POST['description']??'')),0,600);$badge=mb_substr(trim((string)($_POST['badge']??'')),0,80);$sort=(int)($_POST['sort_order']??100);$featured=isset($_POST['featured'])?1:0;$visible=isset($_POST['visible'])?1:0;
            if($id>0){$stmt=db()->prepare('UPDATE customer_product_groups SET name=?,category_id=?,description=?,badge=?,featured=?,visible=?,sort_order=? WHERE id=?');$stmt->execute([$name,$cat?:null,$description?:null,$badge?:null,$featured,$visible,$sort,$id]);}
            else{$stmt=db()->prepare('INSERT INTO customer_product_groups(name,category_id,description,badge,featured,visible,sort_order) VALUES(?,?,?,?,?,?,?)');$stmt->execute([$name,$cat?:null,$description?:null,$badge?:null,$featured,$visible,$sort]);$id=(int)db()->lastInsertId();}
            audit_write('customer_product_group_saved','PWA-группа товара: '.$name,'customer_product_group',(string)$id);flash('success','Группа сохранена.');
        }elseif($action==='variant_save'){
            $groupId=(int)($_POST['group_id']??0);$productId=(int)($_POST['product_id']??0);if($groupId<=0||$productId<=0)throw new RuntimeException('Выберите группу и товар.');
            $label=mb_substr(trim((string)($_POST['variant_label']??'')),0,80);if($label==='')throw new RuntimeException('Укажите подпись варианта, например 350 мл.');$sort=(int)($_POST['sort_order']??100);$default=isset($_POST['is_default'])?1:0;
            $pdo=db();$pdo->beginTransaction();
            try{
                if($default)$pdo->prepare('UPDATE customer_product_group_variants SET is_default=0 WHERE group_id=?')->execute([$groupId]);
                $stmt=$pdo->prepare('INSERT INTO customer_product_group_variants(group_id,product_id,variant_label,sort_order,is_default) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE group_id=VALUES(group_id),variant_label=VALUES(variant_label),sort_order=VALUES(sort_order),is_default=VALUES(is_default)');
                $stmt->execute([$groupId,$productId,$label,$sort,$default]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            flash('success','Вариант сохранён.');
        }elseif($action==='variant_delete'){
            $stmt=db()->prepare('DELETE FROM customer_product_group_variants WHERE group_id=? AND product_id=?');$stmt->execute([(int)($_POST['group_id']??0),(int)($_POST['product_id']??0)]);flash('success','Вариант удалён из группы.');
        }elseif($action==='auto_group'){
            $rows=db()->query("SELECT p.id,p.name,p.category,s.category_id FROM products p LEFT JOIN customer_product_settings s ON s.product_id=p.id WHERE p.active=1 AND p.sale_price>0 ORDER BY p.name")->fetchAll();$bucket=[];
            foreach($rows as $p){$label=customer_group_variant_label((string)$p['name']);if($label==='Вариант')continue;$base=customer_group_base_name((string)$p['name']);if($base==='')continue;$key=mb_strtolower($base);$bucket[$key]['name']=$base;$bucket[$key]['category_id']=(int)($p['category_id']??0);$bucket[$key]['items'][]=['id'=>(int)$p['id'],'label'=>$label];}
            $created=0;$linked=0;$pdo=db();$pdo->beginTransaction();
            try{
                foreach($bucket as $group){if(count($group['items'])<2)continue;$find=$pdo->prepare('SELECT id FROM customer_product_groups WHERE name=? LIMIT 1');$find->execute([$group['name']]);$gid=(int)($find->fetchColumn()?:0);if(!$gid){$pdo->prepare('INSERT INTO customer_product_groups(name,category_id,visible,sort_order) VALUES(?,?,1,100)')->execute([$group['name'],$group['category_id']?:null]);$gid=(int)$pdo->lastInsertId();$created++;}
                    $first=true;foreach($group['items'] as $item){$stmt=$pdo->prepare('INSERT INTO customer_product_group_variants(group_id,product_id,variant_label,sort_order,is_default) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE group_id=VALUES(group_id),variant_label=VALUES(variant_label),sort_order=VALUES(sort_order)');$stmt->execute([$gid,$item['id'],$item['label'],(int)preg_replace('/\D+/','',$item['label']),$first?1:0]);$linked++;$first=false;}}
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            flash('success','Автогруппировка завершена: групп создано '.$created.', вариантов связано '.$linked.'.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_groups.php');
}

$categories=customer_pwa_categories(false);$groups=customer_pwa_groups(false);$products=db()->query('SELECT id,name,sale_price FROM products WHERE active=1 ORDER BY name')->fetchAll();
$variants=[];foreach(db()->query('SELECT v.*,p.name product_name,p.sale_price FROM customer_product_group_variants v JOIN products p ON p.id=v.product_id ORDER BY v.group_id,v.sort_order,p.name')->fetchAll() as $v)$variants[(int)$v['group_id']][]=$v;
page_header('Группы и объёмы PWA');
?>
<div class="card"><div class="chart-head"><div><h2>Группы товаров и объёмы</h2><p>Эвотор продолжает хранить каждый размер отдельным товаром. Здесь они объединяются только для клиентского PWA.</p></div><div class="actions"><a class="btn ghost" href="customer_app.php">← Клиентское PWA</a><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="auto_group"><button class="btn primary">Автосгруппировать по объёму</button></form></div></div></div>

<div class="card section"><div class="chart-head"><div><h2>Новая группа</h2><p>Например: «Капучино». Затем добавь к ней товары «Капучино 250 мл», «350 мл», «450 мл».</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="group_save"><label>Название<input name="name" required placeholder="Капучино"></label><label>Категория<select name="category_id"><option value="0">Автоматически</option><?php foreach($categories as $c):?><option value="<?=$c['id']?>"><?=e($c['icon'].' '.$c['name'])?></option><?php endforeach;?></select></label><label>Описание<textarea name="description" placeholder="Нежный кофе с молочной пеной"></textarea></label><label>Бейдж<input name="badge" placeholder="Хит"></label><label>Порядок<input type="number" name="sort_order" value="100"></label><label style="display:flex;gap:8px;align-items:center"><input style="width:auto" type="checkbox" name="featured" value="1"> Популярное</label><label style="display:flex;gap:8px;align-items:center"><input style="width:auto" type="checkbox" name="visible" value="1" checked> Показывать</label><div><button class="btn primary">Создать группу</button></div></form></div>

<?php foreach($groups as $g):$gid=(int)$g['id'];?>
<div class="card section"><div class="chart-head"><div><h2><?=e($g['name'])?></h2><p><?=count($variants[$gid]??[])?> вариантов</p></div></div>
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="group_save"><input type="hidden" name="id" value="<?=$gid?>"><label>Название<input name="name" value="<?=e($g['name'])?>" required></label><label>Категория<select name="category_id"><option value="0">Автоматически</option><?php foreach($categories as $c):?><option value="<?=$c['id']?>" <?=((int)$g['category_id']===(int)$c['id'])?'selected':''?>><?=e($c['icon'].' '.$c['name'])?></option><?php endforeach;?></select></label><label>Описание<textarea name="description"><?=e((string)$g['description'])?></textarea></label><label>Бейдж<input name="badge" value="<?=e((string)$g['badge'])?>"></label><label>Порядок<input type="number" name="sort_order" value="<?=$g['sort_order']?>"></label><label style="display:flex;gap:8px;align-items:center"><input style="width:auto" type="checkbox" name="featured" value="1" <?=$g['featured']?'checked':''?>> Популярное</label><label style="display:flex;gap:8px;align-items:center"><input style="width:auto" type="checkbox" name="visible" value="1" <?=$g['visible']?'checked':''?>> Показывать</label><div><button class="btn ghost">Сохранить группу</button></div></form>
<div style="overflow:auto;margin-top:16px"><table><thead><tr><th>Товар Kapouch / Evotor</th><th>Вариант в PWA</th><th>Цена</th><th>По умолчанию</th><th></th></tr></thead><tbody><?php foreach($variants[$gid]??[] as $v):?><tr><td><strong><?=e($v['product_name'])?></strong></td><td><?=e($v['variant_label'])?></td><td><?=money((float)$v['sale_price'])?></td><td><?=$v['is_default']?'Да':'—'?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="variant_delete"><input type="hidden" name="group_id" value="<?=$gid?>"><input type="hidden" name="product_id" value="<?=$v['product_id']?>"><button class="btn ghost">Убрать</button></form></td></tr><?php endforeach;?><?php if(empty($variants[$gid])):?><tr><td colspan="5" class="muted">Добавь хотя бы два размера.</td></tr><?php endif;?></tbody></table></div>
<form method="post" class="form-grid" style="margin-top:14px;padding:12px;border:1px dashed var(--line);border-radius:14px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="variant_save"><input type="hidden" name="group_id" value="<?=$gid?>"><label>Товар<select name="product_id" required><option value="">Выберите товар</option><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=e($p['name'].' · '.money((float)$p['sale_price']))?></option><?php endforeach;?></select></label><label>Подпись варианта<input name="variant_label" placeholder="350 мл" required></label><label>Порядок<input type="number" name="sort_order" value="100"></label><label style="display:flex;gap:8px;align-items:center"><input style="width:auto" type="checkbox" name="is_default" value="1"> По умолчанию</label><div><button class="btn primary">Добавить вариант</button></div></form>
</div>
<?php endforeach;?>
<?php page_footer();?>
