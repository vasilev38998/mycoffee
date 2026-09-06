<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/customer_loyalty.php';
require_once __DIR__.'/inc/customer_drink_loyalty.php';

$user=current_user();if(!in_array($user['role']??'',['owner','manager'],true)){http_response_code(403);exit('Недостаточно прав.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $percent=(float)str_replace(',','.',(string)($_POST['customer_loyalty_percent']??'0'));if($percent<0||$percent>50)throw new RuntimeException('Процент бонусов должен быть от 0 до 50.');
        $required=(int)($_POST['customer_sixth_drink_paid_count']??5);if($required<1||$required>20)throw new RuntimeException('Количество оплаченных напитков должно быть от 1 до 20.');
        $mode=isset($_POST['customer_sixth_drink_auto_products'])?'auto':'selected';$ids=[];foreach((array)($_POST['drink_product_ids']??[]) as $raw){$id=(int)$raw;if($id>0)$ids[$id]=true;}
        $reference=(int)($_POST['customer_sixth_drink_reference_product_id']??0);
        if($reference>0){$stmt=db()->prepare('SELECT COUNT(*) FROM products WHERE id=? AND active=1 AND sale_price>0');$stmt->execute([$reference]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('Выбранный эталонный напиток больше недоступен.');}
        set_app_setting('customer_loyalty_percent',(string)round($percent,2));
        set_app_setting('customer_sixth_drink_enabled',isset($_POST['customer_sixth_drink_enabled'])?'1':'0');
        set_app_setting('customer_sixth_drink_paid_count',(string)$required);
        set_app_setting('customer_sixth_drink_products_mode',$mode);
        set_app_setting('customer_sixth_drink_product_ids',implode(',',array_keys($ids)));
        set_app_setting('customer_sixth_drink_reference_product_id',(string)max(0,$reference));
        if(trim((string)app_setting('customer_sixth_drink_started_at',''))==='')set_app_setting('customer_sixth_drink_started_at',date('Y-m-d H:i:s'));
        audit_write('customer_loyalty_settings_updated','Обновлены процентные бонусы и программа «6-й напиток»');flash('success','Настройки лояльности сохранены.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_loyalty_settings.php');
}

$settings=customer_drink_loyalty_settings();$rows=customer_drink_loyalty_product_rows(true);$eligible=customer_drink_loyalty_product_map();$reference=customer_drink_loyalty_reference_product();$percent=customer_loyalty_rate();
$ledgerStats=['customers'=>0,'stamps'=>0,'redemptions'=>0];try{$ledgerStats=db()->query("SELECT COUNT(DISTINCT customer_id) customers,COALESCE(SUM(CASE WHEN stamp_delta>0 THEN stamp_delta ELSE 0 END),0) stamps,COALESCE(SUM(CASE WHEN reward_delta<0 THEN -reward_delta ELSE 0 END),0) redemptions FROM customer_drink_loyalty_ledger")->fetch()?:$ledgerStats;}catch(Throwable $e){}
page_header('Лояльность клиентов');
?>
<div class="card"><div class="chart-head"><div><h2>Лояльность клиентов</h2><p>Две программы работают одновременно: процентные бонусы и «каждый 6-й напиток в подарок».</p></div><a class="btn ghost" href="customer_app.php">← Клиентское PWA</a></div></div>

<div class="three-col section">
  <div class="metric-card"><span>Процентные бонусы</span><strong><?=number_format($percent,2,',',' ')?>%</strong><small>Начисляются как раньше</small></div>
  <div class="metric-card"><span>Отметок по напиткам</span><strong><?=(int)$ledgerStats['stamps']?></strong><small>С момента запуска программы</small></div>
  <div class="metric-card"><span>Подарков использовано</span><strong><?=(int)$ledgerStats['redemptions']?></strong><small>История хранится отдельно</small></div>
</div>

<form method="post" class="stack section"><input type="hidden" name="csrf" value="<?=csrf_token()?>">
<div class="card"><div class="chart-head"><div><h2>Процентные бонусы</h2><p>Эта программа остаётся независимой от бесплатного напитка.</p></div></div><div class="form-grid"><label>Начислять с покупки, %<input type="number" name="customer_loyalty_percent" min="0" max="50" step="0.01" value="<?=e((string)$percent)?>"></label></div></div>

<div class="card"><div class="chart-head"><div><h2>Каждый 6-й напиток</h2><p>После пяти оплаченных подходящих напитков клиент получает один подарок. Если выбранный напиток дороже лимита — оплачивается только разница.</p></div><span class="pill <?=$settings['enabled']?'connected':''?>"><?=$settings['enabled']?'Включено':'Выключено'?></span></div>
<div class="form-grid section" style="margin-top:14px">
<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="customer_sixth_drink_enabled" value="1" style="width:auto" <?=$settings['enabled']?'checked':''?>> Включить программу</label>
<label>Оплаченных напитков перед подарком<input type="number" name="customer_sixth_drink_paid_count" min="1" max="20" value="<?=(int)$settings['required_paid']?>"></label>
<label>Эталон подарка<select name="customer_sixth_drink_reference_product_id"><option value="0">Определять капучино 0,2 автоматически</option><?php foreach($rows as $row):?><option value="<?=$row['id']?>" <?=((int)$settings['reference_product_id']===(int)$row['id'])?'selected':''?>><?=e($row['name'].(($row['variant_label']??'')?' · '.$row['variant_label']:'').' · '.money((float)$row['sale_price']))?></option><?php endforeach;?></select></label>
<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="customer_sixth_drink_auto_products" value="1" style="width:auto" <?=$settings['products_mode']==='auto'?'checked':''?>> Автоматически определять напитки по категориям и названиям</label>
</div>
<div class="alert info" style="margin-top:12px"><strong>Лимит подарка сейчас:</strong> <?php if($reference):?><?=money((float)$reference['price'])?> — <?=e($reference['name'].($reference['variant']?' · '.$reference['variant']:''))?><?=$reference['auto']?' (определено автоматически)':''?>.<?php else:?>не определён. Выберите капучино 0,2 в поле выше.<?php endif;?> Программа считает только покупки после <?=e(date('d.m.Y H:i',strtotime($settings['started_at'])))?>.</div>
</div>

<div class="card"><div class="chart-head"><div><h2>Какие товары считаются напитками</h2><p>При автоматическом режиме список ниже — предпросмотр. Если снять автовыбор, отмеченные позиции станут точным списком программы.</p></div></div><div class="table-wrap"><table><thead><tr><th></th><th>Товар</th><th>Категория</th><th>Цена</th><th>Сейчас участвует</th></tr></thead><tbody><?php foreach($rows as $row):$id=(int)$row['id'];$category=trim((string)($row['group_name']?:$row['direct_category']?:$row['category']));?><tr><td><input type="checkbox" name="drink_product_ids[]" value="<?=$id?>" <?=!empty($eligible[$id])?'checked':''?>></td><td><strong><?=e($row['name'])?></strong><?php if(!empty($row['variant_label'])):?><div class="muted"><?=e((string)$row['variant_label'])?></div><?php endif;?></td><td><?=e($category?:'Без категории')?></td><td><?=money((float)$row['sale_price'])?></td><td><span class="pill <?=!empty($eligible[$id])?'connected':''?>"><?=!empty($eligible[$id])?'Да':'Нет'?></span></td></tr><?php endforeach;?></tbody></table></div></div>

<div><button class="btn primary">Сохранить настройки лояльности</button></div></form>
<div class="alert info section"><strong>Списание подарка:</strong> серверная логика уже рассчитывает скидку как минимум из стоимости выбранного напитка и цены эталонного капучино. Фактическое применение скидки к кассовому чеку будет подключено к Эвотору отдельным шагом после проверки кассы.</div>
<?php page_footer();?>
