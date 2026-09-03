<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

$user=current_user();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=$_POST['action']??'';
    try{
        if($action==='business'){
            $name=trim($_POST['coffee_name']??'MyCoffee');
            $timezone=$_POST['timezone']??'Asia/Irkutsk';
            $currency=trim($_POST['currency']??'₽');
            $allowedTz=['Asia/Irkutsk','Europe/Moscow','Asia/Krasnoyarsk','Asia/Yekaterinburg'];
            if(!in_array($timezone,$allowedTz,true))$timezone='Asia/Irkutsk';
            set_app_setting('coffee_name',$name!==''?$name:'MyCoffee');
            set_app_setting('timezone',$timezone);
            set_app_setting('currency',$currency!==''?$currency:'₽');
            set_app_setting('opening_hour',$_POST['opening_hour']??'07:00');
            set_app_setting('closing_hour',$_POST['closing_hour']??'21:00');
            flash('success','Основные настройки сохранены. Часовой пояс применяется ко всему интерфейсу и новым продажам.');
        }
        if($action==='goals'){
            foreach(['monthly_revenue_goal','monthly_profit_goal','target_food_cost','target_expense_load'] as $key){
                $value=max(0,(float)($_POST[$key]??0));
                set_app_setting($key,(string)$value);
            }
            flash('success','Цели и контрольные показатели сохранены.');
        }
        if($action==='profile'){
            $name=trim($_POST['name']??'');
            $email=mb_strtolower(trim($_POST['email']??''));
            if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Проверьте имя и email.');
            $stmt=db()->prepare('UPDATE users SET name=?,email=? WHERE id=?');
            $stmt->execute([$name,$email,(int)$user['id']]);
            flash('success','Профиль обновлён.');
        }
        if($action==='password'){
            $current=(string)($_POST['current_password']??'');
            $new=(string)($_POST['new_password']??'');
            $stmt=db()->prepare('SELECT password_hash FROM users WHERE id=?');$stmt->execute([(int)$user['id']]);$hash=(string)$stmt->fetchColumn();
            if(!password_verify($current,$hash))throw new RuntimeException('Текущий пароль указан неверно.');
            if(strlen($new)<8)throw new RuntimeException('Новый пароль должен быть не короче 8 символов.');
            $stmt=db()->prepare('UPDATE users SET password_hash=? WHERE id=?');$stmt->execute([password_hash($new,PASSWORD_DEFAULT),(int)$user['id']]);
            flash('success','Пароль изменён.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('settings.php');
}

$user=current_user();
page_header('Настройки');
?>
<div class="two-col">
<div class="card"><div class="chart-head"><div><h2>Кофейня</h2><p>Основные параметры системы</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="business"><label>Название кофейни<input name="coffee_name" value="<?=e((string)app_setting('coffee_name','MyCoffee'))?>" required></label><label>Часовой пояс<select name="timezone"><option value="Asia/Irkutsk" <?=app_timezone()==='Asia/Irkutsk'?'selected':''?>>Иркутск (UTC+8, +5 к Москве)</option><option value="Europe/Moscow" <?=app_timezone()==='Europe/Moscow'?'selected':''?>>Москва (UTC+3)</option><option value="Asia/Krasnoyarsk" <?=app_timezone()==='Asia/Krasnoyarsk'?'selected':''?>>Красноярск (UTC+7)</option><option value="Asia/Yekaterinburg" <?=app_timezone()==='Asia/Yekaterinburg'?'selected':''?>>Екатеринбург (UTC+5)</option></select></label><label>Валюта<input name="currency" value="<?=e(app_currency())?>" maxlength="8"></label><label>Открытие<input type="time" name="opening_hour" value="<?=e((string)app_setting('opening_hour','07:00'))?>"></label><label>Закрытие<input type="time" name="closing_hour" value="<?=e((string)app_setting('closing_hour','21:00'))?>"></label><div><button class="btn primary">Сохранить настройки</button></div></form></div>
<div class="card"><div class="chart-head"><div><h2>Профиль владельца</h2><p>Имя и данные входа</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="profile"><label>Имя<input name="name" value="<?=e($user['name'])?>" required></label><label>Email<input type="email" name="email" value="<?=e($user['email'])?>" required></label><button class="btn primary">Обновить профиль</button></form><div class="section"><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="password"><label>Текущий пароль<input type="password" name="current_password" required></label><label>Новый пароль<input type="password" name="new_password" minlength="8" required></label><button class="btn ghost">Изменить пароль</button></form></div></div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Цели и нормативы</h2><p>Эти значения используются дашбордом и автоматическими предупреждениями</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="goals"><label>Цель выручки в месяц<input type="number" step="1000" min="0" name="monthly_revenue_goal" value="<?=e((string)app_setting('monthly_revenue_goal','0'))?>"></label><label>Цель операционной прибыли в месяц<input type="number" step="1000" min="0" name="monthly_profit_goal" value="<?=e((string)app_setting('monthly_profit_goal','0'))?>"></label><label>Целевой food cost, %<input type="number" step="0.1" min="0" max="100" name="target_food_cost" value="<?=e((string)app_setting('target_food_cost','30'))?>"></label><label>Максимальная доля расходов, %<input type="number" step="0.1" min="0" max="100" name="target_expense_load" value="<?=e((string)app_setting('target_expense_load','30'))?>"></label><div><button class="btn primary">Сохранить цели</button></div></form></div>

<div class="card section"><div class="chart-head"><div><h2>Системная информация</h2><p>Для проверки корректности данных</p></div></div><table><tbody><tr><td>Текущий часовой пояс</td><td><strong><?=e(date_default_timezone_get())?></strong></td></tr><tr><td>Локальное время системы</td><td><strong><?=e(date('d.m.Y H:i:s'))?></strong></td></tr><tr><td>Старые чеки Эвотор переведены в Иркутск</td><td><strong><?=system_meta('evotor_time_rebased_to_irkutsk')==='1'?'Да':'Нет'?></strong></td></tr></tbody></table></div>
<?php page_footer(); ?>