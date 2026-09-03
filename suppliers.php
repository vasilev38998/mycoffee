<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/suppliers.php';
require __DIR__.'/inc/layout.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='save'){
            $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$contact=trim((string)($_POST['contact_name']??''));$phone=trim((string)($_POST['phone']??''));$email=trim((string)($_POST['email']??''));$notes=trim((string)($_POST['notes']??''));$lead=max(0,(int)($_POST['lead_time_days']??1));$min=max(0,(float)($_POST['min_order_amount']??0));$active=isset($_POST['active'])?1:0;
            if($name==='')throw new RuntimeException('Укажи название поставщика.');if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Некорректный email.');
            if($id){$stmt=db()->prepare('UPDATE suppliers SET name=?,contact_name=?,phone=?,email=?,notes=?,lead_time_days=?,min_order_amount=?,active=? WHERE id=?');$stmt->execute([$name,$contact?:null,$phone?:null,$email?:null,$notes?:null,$lead,$min,$active,$id]);audit_write('supplier_updated','Изменён поставщик '.$name,'supplier',(string)$id);}
            else{$stmt=db()->prepare('INSERT INTO suppliers(name,contact_name,phone,email,notes,lead_time_days,min_order_amount,active) VALUES(?,?,?,?,?,?,?,?)');$stmt->execute([$name,$contact?:null,$phone?:null,$email?:null,$notes?:null,$lead,$min,$active]);$id=(int)db()->lastInsertId();audit_write('supplier_created','Создан поставщик '.$name,'supplier',(string)$id);}
            flash('success','Поставщик сохранён.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('suppliers.php');
}
$from=$_GET['from']??date('Y-m-01');$to=$_GET['to']??date('Y-m-d');
$overview=supplier_overview($from,$to);$suppliers=supplier_list(false);$totalSpend=array_sum(array_map(fn($r)=>(float)$r['spend'],$overview));$active=count(array_filter($suppliers,fn($s)=>(int)$s['active']===1));
page_header('Поставщики');
?>
<div class="grid">
<div class="card metric"><div class="label">Активные поставщики</div><div class="value"><?=$active?></div><div class="meta">из <?=count($suppliers)?> карточек</div></div>
<div class="card metric"><div class="label">Закупки за период</div><div class="value"><?=money($totalSpend)?></div><div class="meta"><?=e(date('d.m.Y',strtotime($from)))?>–<?=e(date('d.m.Y',strtotime($to)))?></div></div>
<div class="card metric"><div class="label">Поставщиков с закупками</div><div class="value"><?=count(array_filter($overview,fn($r)=>(int)$r['orders']>0))?></div><div class="meta">за выбранный период</div></div>
<div class="card metric"><div class="label">Контроль цен</div><div class="value"><a href="purchase_prices.php">Открыть →</a></div><div class="meta">динамика и влияние на меню</div></div>
</div>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Добавить поставщика</h2><p>Контакты и условия для закупок.</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save"><label>Название<input name="name" required></label><div class="form-grid"><label>Контактное лицо<input name="contact_name"></label><label>Телефон<input name="phone"></label><label>Email<input type="email" name="email"></label><label>Срок поставки, дней<input type="number" min="0" name="lead_time_days" value="1"></label><label>Минимальный заказ<input type="number" min="0" step="0.01" name="min_order_amount" value="0"></label></div><label>Заметки<textarea name="notes" rows="3"></textarea></label><label style="display:flex;grid-template-columns:auto 1fr;align-items:center"><input type="checkbox" name="active" value="1" checked style="width:auto"> Активен</label><button class="btn primary">Сохранить поставщика</button></form></div>
<div class="card"><div class="chart-head"><div><h2>Фильтр аналитики</h2><p>Оборот и частота закупок.</p></div></div><form method="get" class="stack"><div class="form-grid"><label>С<input type="date" name="from" value="<?=e($from)?>"></label><label>По<input type="date" name="to" value="<?=e($to)?>"></label></div><button class="btn">Показать период</button></form><div class="alert-item good section"><span class="alert-dot"></span><div><strong>Старые поставщики уже сохранятся</strong><p>При миграции Kapouch создаст карточки из текстовых названий в существующих закупках и привяжет историю к ним.</p></div></div></div>
</div>

<div class="card table-card section"><div class="chart-head"><div><h2>Оборот по поставщикам</h2><p>Сумма, количество заказов и средний чек закупки.</p></div></div><table><thead><tr><th>Поставщик</th><th>Заказов</th><th>Оборот</th><th>Средний заказ</th><th>Ингредиентов</th><th>Последняя закупка</th><th>Срок поставки</th></tr></thead><tbody><?php foreach($overview as $r):?><tr><td><strong><?=e($r['name'])?></strong></td><td><?=number_format((int)$r['orders'],0,',',' ')?></td><td><?=money((float)$r['spend'])?></td><td><?=money((float)$r['avg_order'])?></td><td><?=number_format((int)$r['ingredients'],0,',',' ')?></td><td><?=$r['last_purchase']?e(date('d.m.Y',strtotime($r['last_purchase']))):'—'?></td><td><?=number_format((int)$r['lead_time_days'],0,',',' ')?> дн.</td></tr><?php endforeach;?></tbody></table></div>

<div class="card table-card section"><div class="chart-head"><div><h2>Карточки поставщиков</h2><p>Редактирование контактов и условий.</p></div></div><table><thead><tr><th>Название</th><th>Контакты</th><th>Мин. заказ</th><th>Статус</th><th>Управление</th></tr></thead><tbody><?php foreach($suppliers as $s):?><tr><td><strong><?=e($s['name'])?></strong><br><span class="muted"><?=e((string)$s['notes'])?></span></td><td><?=e((string)($s['contact_name']?:'—'))?><br><span class="muted"><?=e((string)($s['phone']?:$s['email']?:''))?></span></td><td><?=money((float)$s['min_order_amount'])?></td><td><span class="pill <?=(int)$s['active']?'connected':''?>"><?=(int)$s['active']?'Активен':'Отключён'?></span></td><td><details><summary class="btn ghost">Изменить</summary><form method="post" class="stack" style="margin-top:10px;min-width:300px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=$s['id']?>"><label>Название<input name="name" value="<?=e($s['name'])?>" required></label><label>Контакт<input name="contact_name" value="<?=e((string)$s['contact_name'])?>"></label><label>Телефон<input name="phone" value="<?=e((string)$s['phone'])?>"></label><label>Email<input type="email" name="email" value="<?=e((string)$s['email'])?>"></label><label>Срок поставки<input type="number" min="0" name="lead_time_days" value="<?=$s['lead_time_days']?>"></label><label>Минимальный заказ<input type="number" min="0" step="0.01" name="min_order_amount" value="<?=$s['min_order_amount']?>"></label><label>Заметки<textarea name="notes"><?=e((string)$s['notes'])?></textarea></label><label style="display:flex;grid-template-columns:auto 1fr;align-items:center"><input type="checkbox" name="active" value="1" style="width:auto" <?=(int)$s['active']?'checked':''?>> Активен</label><button class="btn primary">Сохранить</button></form></details></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>