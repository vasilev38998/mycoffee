<?php
declare(strict_types=1);

require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/customer_wheel.php';

$user=current_user();if(!in_array($user['role']??'',['owner','manager'],true)){http_response_code(403);exit('Недостаточно прав.');}

function customer_wheel_admin_accent(string $raw): string
{
    $raw=trim($raw);return preg_match('/^#[0-9A-Fa-f]{6}$/',$raw)?strtoupper($raw):'#FFC928';
}
function customer_wheel_admin_type_label(string $type): string
{
    return ['points'=>'Бонусы','stamp'=>'+ к прогрессу','free_drink'=>'Напиток в подарок','discount_percent'=>'Скидка на напиток'][$type]??$type;
}
function customer_wheel_admin_phone(string $phone): string
{
    $digits=preg_replace('/\D+/','',$phone)??'';if(strlen($digits)<7)return '—';return '+'.substr($digits,0,1).' '.substr($digits,1,3).' ***-**-'.substr($digits,-2);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='settings'){
            $minOrder=round((float)str_replace(',','.',(string)($_POST['min_order']??'250')),2);if($minOrder<0||$minOrder>100000)throw new RuntimeException('Минимальная сумма заказа должна быть от 0 до 100 000 ₽.');
            $cooldown=(int)($_POST['cooldown_hours']??20);if($cooldown<0||$cooldown>720)throw new RuntimeException('Пауза между вращениями должна быть от 0 до 720 часов.');
            $title=trim((string)($_POST['title']??''));$subtitle=trim((string)($_POST['subtitle']??''));if($title===''||mb_strlen($title)>120)throw new RuntimeException('Название должно содержать от 1 до 120 символов.');if(mb_strlen($subtitle)>190)throw new RuntimeException('Подзаголовок слишком длинный.');
            set_app_setting('customer_wheel_enabled',isset($_POST['enabled'])?'1':'0');set_app_setting('customer_wheel_min_order',(string)$minOrder);set_app_setting('customer_wheel_cooldown_hours',(string)$cooldown);set_app_setting('customer_wheel_title',$title);set_app_setting('customer_wheel_subtitle',$subtitle);
            if(trim((string)app_setting('customer_wheel_started_at',''))==='')set_app_setting('customer_wheel_started_at',date('Y-m-d H:i:s'));
            audit_write('customer_wheel_settings','Обновлены настройки колеса Kapouch');flash('success','Настройки колеса сохранены.');
        }elseif($action==='save_prize'){
            $id=(int)($_POST['id']??0);$title=trim((string)($_POST['prize_title']??''));$subtitle=trim((string)($_POST['prize_subtitle']??''));$type=(string)($_POST['prize_type']??'points');
            if($title===''||mb_strlen($title)>120)throw new RuntimeException('Укажите короткое название приза.');if(mb_strlen($subtitle)>190)throw new RuntimeException('Описание приза слишком длинное.');if(!in_array($type,['points','stamp','free_drink','discount_percent'],true))throw new RuntimeException('Неизвестный тип приза.');
            $value=round((float)str_replace(',','.',(string)($_POST['value']??'0')),2);$cap=round((float)str_replace(',','.',(string)($_POST['cap_value']??'0')),2);$weight=(int)($_POST['weight']??1);$daily=(int)($_POST['daily_limit']??0);$days=(int)($_POST['validity_days']??14);$sort=(int)($_POST['sort_order']??0);
            if($value<=0||$value>100000)throw new RuntimeException('Значение приза должно быть больше нуля.');if($cap<0||$cap>100000)throw new RuntimeException('Лимит скидки указан неверно.');if($weight<0||$weight>100000)throw new RuntimeException('Вес должен быть от 0 до 100 000.');if($daily<0||$daily>10000)throw new RuntimeException('Дневной лимит указан неверно.');if($days<1||$days>365)throw new RuntimeException('Срок действия — от 1 до 365 дней.');
            $icon=(string)($_POST['icon']??'star');if(!in_array($icon,['star','sparkle','stamp','discount','crown','cup','bean'],true))$icon='star';$accent=customer_wheel_admin_accent((string)($_POST['accent']??''));$active=isset($_POST['active'])?1:0;
            if($id>0){$stmt=db()->prepare('UPDATE customer_wheel_prizes SET title=?,subtitle=?,prize_type=?,value=?,cap_value=?,weight=?,daily_limit=?,validity_days=?,icon=?,accent=?,active=?,sort_order=? WHERE id=?');$stmt->execute([$title,$subtitle!==''?$subtitle:null,$type,$value,$cap,$weight,$daily,$days,$icon,$accent,$active,$sort,$id]);}
            else{$stmt=db()->prepare('INSERT INTO customer_wheel_prizes(title,subtitle,prize_type,value,cap_value,weight,daily_limit,validity_days,icon,accent,active,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$title,$subtitle!==''?$subtitle:null,$type,$value,$cap,$weight,$daily,$days,$icon,$accent,$active,$sort]);$id=(int)db()->lastInsertId();}
            audit_write('customer_wheel_prize','Сохранён приз колеса #'.$id,'customer_wheel_prize',(string)$id);flash('success','Приз сохранён.');
        }elseif($action==='toggle_prize'){
            $id=(int)($_POST['id']??0);if($id<=0)throw new RuntimeException('Приз не найден.');db()->prepare('UPDATE customer_wheel_prizes SET active=1-active WHERE id=?')->execute([$id]);flash('success','Статус приза изменён.');
        }else throw new RuntimeException('Неизвестное действие.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_wheel.php');
}

$settings=customer_wheel_settings();$prizes=customer_wheel_prizes(false);$activeWeight=0;foreach($prizes as $p)if($p['active']&&$p['weight']>0)$activeWeight+=(int)$p['weight'];
$stats=['today'=>0,'month'=>0,'discounts'=>0,'free_drinks'=>0];
try{$stats=db()->query("SELECT SUM(created_at>=CURDATE()) today,SUM(created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) month,SUM(prize_type='discount_percent' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) discounts,SUM(prize_type='free_drink' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) free_drinks FROM customer_wheel_spins")->fetch()?:$stats;}catch(Throwable $e){}
$history=[];try{$history=db()->query("SELECT ws.*,c.name,c.phone,o.order_number FROM customer_wheel_spins ws LEFT JOIN customer_accounts c ON c.id=ws.customer_id LEFT JOIN online_orders o ON o.id=ws.source_order_id ORDER BY ws.id DESC LIMIT 60")->fetchAll();}catch(Throwable $e){}
page_header('Колесо удачи');
?>
<div class="card"><div class="chart-head"><div><h2>Колесо Kapouch</h2><p>Управляемая игровая механика после завершённых PWA-заказов. Результат выбирает сервер, а PWA только красиво показывает выигрыш.</p></div><span class="pill <?=$settings['enabled']?'connected':''?>"><?=$settings['enabled']?'Работает':'На паузе'?></span></div></div>

<div class="three-col section">
  <div class="metric-card"><span>Вращений сегодня</span><strong><?=(int)($stats['today']??0)?></strong><small>Одно подходящее завершение = не больше одного шанса</small></div>
  <div class="metric-card"><span>За 30 дней</span><strong><?=(int)($stats['month']??0)?></strong><small>Всего выданных призов</small></div>
  <div class="metric-card"><span>Крупные призы · 30 дней</span><strong><?=(int)($stats['free_drinks']??0)?> ☕ · <?=(int)($stats['discounts']??0)?> %</strong><small>Напитки и скидочные ваучеры</small></div>
</div>

<form method="post" class="card section"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="settings">
<div class="chart-head"><div><h2>Правила вращения</h2><p>Шанс открывается только после завершённого заказа, созданного через клиентское PWA.</p></div></div>
<div class="form-grid section" style="margin-top:14px">
<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="enabled" value="1" style="width:auto" <?=$settings['enabled']?'checked':''?>> Включить колесо</label>
<label>Минимальная сумма заказа, ₽<input type="number" name="min_order" min="0" max="100000" step="1" value="<?=e((string)$settings['min_order'])?>"></label>
<label>Пауза между вращениями, часов<input type="number" name="cooldown_hours" min="0" max="720" step="1" value="<?=(int)$settings['cooldown_hours']?>"><small class="muted">0 — без дополнительной паузы. Каждый заказ всё равно можно использовать только один раз.</small></label>
<label>Название<input name="title" maxlength="120" value="<?=e($settings['title'])?>"></label>
<label style="grid-column:1/-1">Подзаголовок<input name="subtitle" maxlength="190" value="<?=e($settings['subtitle'])?>"></label>
</div><button class="btn primary">Сохранить правила</button></form>

<div class="card section"><div class="chart-head"><div><h2>Призы и вероятность</h2><p>Вероятность определяется весом. Дневной лимит временно исключает исчерпанный приз из розыгрыша, поэтому фактическая вероятность может меняться в течение дня.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Приз</th><th>Тип</th><th>Значение</th><th>Вес / шанс</th><th>Лимит</th><th>Статус</th><th></th></tr></thead><tbody>
<?php foreach($prizes as $p):$chance=$activeWeight>0&&$p['active']?((int)$p['weight']/$activeWeight*100):0;?>
<tr><td><div style="display:flex;align-items:center;gap:10px"><span style="width:13px;height:38px;border-radius:8px;background:<?=e(customer_wheel_admin_accent($p['accent']))?>"></span><div><strong><?=e($p['title'])?></strong><div class="muted"><?=e((string)($p['subtitle']??''))?></div></div></div></td><td><?=e(customer_wheel_admin_type_label($p['prize_type']))?></td><td><?=number_format((float)$p['value'],2,',',' ')?><?=($p['prize_type']==='discount_percent'?'%':'')?><?php if((float)$p['cap_value']>0):?><div class="muted">до <?=number_format((float)$p['cap_value'],0,',',' ')?> ₽</div><?php endif;?></td><td><strong><?=(int)$p['weight']?></strong><div class="muted">≈ <?=number_format($chance,1,',',' ')?>%</div></td><td><?=$p['daily_limit']>0?(int)$p['daily_limit'].' / день':'без лимита'?></td><td><span class="pill <?=$p['active']?'connected':''?>"><?=$p['active']?'Активен':'Выключен'?></span></td><td><details><summary class="btn ghost" style="cursor:pointer">Настроить</summary><form method="post" class="form-grid" style="min-width:min(720px,80vw);margin-top:10px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_prize"><input type="hidden" name="id" value="<?=$p['id']?>"><label>Название<input name="prize_title" value="<?=e($p['title'])?>" maxlength="120"></label><label>Описание<input name="prize_subtitle" value="<?=e((string)($p['subtitle']??''))?>" maxlength="190"></label><label>Тип<select name="prize_type"><?php foreach(['points'=>'Бонусы','stamp'=>'+ к прогрессу','discount_percent'=>'Скидка %','free_drink'=>'Напиток в подарок'] as $value=>$label):?><option value="<?=$value?>" <?=$p['prize_type']===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Значение<input type="number" name="value" min="0.01" step="0.01" value="<?=e((string)$p['value'])?>"></label><label>Макс. скидка, ₽<input type="number" name="cap_value" min="0" step="1" value="<?=e((string)$p['cap_value'])?>"></label><label>Вес<input type="number" name="weight" min="0" value="<?=$p['weight']?>"></label><label>Лимит в день<input type="number" name="daily_limit" min="0" value="<?=$p['daily_limit']?>"></label><label>Срок, дней<input type="number" name="validity_days" min="1" max="365" value="<?=$p['validity_days']?>"></label><label>Иконка<select name="icon"><?php foreach(['star'=>'Звезда','sparkle'=>'Искры','stamp'=>'Штамп','discount'=>'Скидка','crown'=>'Корона','cup'=>'Стакан','bean'=>'Зерно'] as $value=>$label):?><option value="<?=$value?>" <?=$p['icon']===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Цвет<input type="color" name="accent" value="<?=e(customer_wheel_admin_accent($p['accent']))?>"></label><label>Порядок<input type="number" name="sort_order" value="<?=$p['sort_order']?>"></label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="active" value="1" style="width:auto" <?=$p['active']?'checked':''?>> Активен</label><div><button class="btn primary">Сохранить приз</button></div></form></details></td></tr>
<?php endforeach;?></tbody></table></div>

<details class="section" style="margin-top:18px"><summary class="btn ghost" style="cursor:pointer">+ Добавить приз</summary><form method="post" class="form-grid section"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_prize"><label>Название<input name="prize_title" maxlength="120" placeholder="Например: 30 бонусов" required></label><label>Описание<input name="prize_subtitle" maxlength="190" placeholder="Короткое сообщение клиенту"></label><label>Тип<select name="prize_type"><option value="points">Бонусы</option><option value="stamp">+ к прогрессу</option><option value="discount_percent">Скидка %</option><option value="free_drink">Напиток в подарок</option></select></label><label>Значение<input type="number" name="value" min="0.01" step="0.01" value="20"></label><label>Макс. скидка, ₽<input type="number" name="cap_value" min="0" value="0"></label><label>Вес<input type="number" name="weight" min="0" value="10"></label><label>Лимит в день<input type="number" name="daily_limit" min="0" value="0"></label><label>Срок, дней<input type="number" name="validity_days" min="1" max="365" value="14"></label><label>Иконка<select name="icon"><option value="star">Звезда</option><option value="sparkle">Искры</option><option value="stamp">Штамп</option><option value="discount">Скидка</option><option value="crown">Корона</option><option value="cup">Стакан</option><option value="bean">Зерно</option></select></label><label>Цвет<input type="color" name="accent" value="#FFC928"></label><label>Порядок<input type="number" name="sort_order" value="100"></label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="active" value="1" style="width:auto" checked> Активен</label><div><button class="btn primary">Добавить</button></div></form></details>
</div>

<div class="card section"><div class="chart-head"><div><h2>Последние вращения</h2><p>Результат каждого вращения хранится на сервере и не может быть заменён повторной прокруткой интерфейса.</p></div></div><div class="table-wrap"><table><thead><tr><th>Время</th><th>Клиент</th><th>Заказ</th><th>Приз</th><th>Статус</th></tr></thead><tbody><?php foreach($history as $row):?><tr><td><?=e(date('d.m H:i',strtotime((string)$row['created_at'])))?></td><td><?=e((string)($row['name']?:'Клиент'))?><div class="muted"><?=e(customer_wheel_admin_phone((string)($row['phone']??'')))?></div></td><td>#<?=e((string)($row['order_number']?:$row['source_order_id']))?></td><td><strong><?=e((string)$row['prize_title'])?></strong><div class="muted"><?=e(customer_wheel_admin_type_label((string)$row['prize_type']))?></div></td><td><span class="pill <?=in_array($row['reward_status'],['granted','redeemed'],true)?'connected':''?>"><?=e((string)$row['reward_status'])?></span></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="5" class="muted">Вращений пока не было.</td></tr><?php endif;?></tbody></table></div></div>
<div class="alert info section"><strong>Экономика:</strong> все сектора выигрышные. Бонусы и прогресс начисляются сразу, бесплатный напиток попадает в существующую программу подарков, а процентная скидка хранится как одноразовый серверный ваучер. Денежного эквивалента у призов нет.</div>
<?php page_footer();?>
