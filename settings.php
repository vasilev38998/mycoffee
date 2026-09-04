<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/notifications.php';
require_once __DIR__.'/inc/customer_auth.php';

$user=current_user();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=$_POST['action']??'';
    try{
        if($action==='business'){
            $name=trim($_POST['coffee_name']??'Kapouch');
            $currency=trim($_POST['currency']??'₽');
            set_app_setting('coffee_name',$name!==''?$name:'Kapouch');
            set_app_setting('timezone','Asia/Irkutsk');
            set_app_setting('currency',$currency!==''?$currency:'₽');
            set_app_setting('opening_hour',$_POST['opening_hour']??'07:00');
            set_app_setting('closing_hour',$_POST['closing_hour']??'21:00');
            flash('success','Основные настройки сохранены.');
        }
        if($action==='goals'){
            foreach(['monthly_revenue_goal','monthly_profit_goal','target_food_cost','target_expense_load'] as $key)set_app_setting($key,(string)max(0,(float)($_POST[$key]??0)));
            flash('success','Цели и нормативы сохранены.');
        }
        if($action==='profile'){
            $name=trim($_POST['name']??'');$email=mb_strtolower(trim($_POST['email']??''));
            if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Проверьте имя и email.');
            $stmt=db()->prepare('UPDATE users SET name=?,email=? WHERE id=?');$stmt->execute([$name,$email,(int)$user['id']]);flash('success','Профиль обновлён.');
        }
        if($action==='password'){
            $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');
            $stmt=db()->prepare('SELECT password_hash FROM users WHERE id=?');$stmt->execute([(int)$user['id']]);$hash=(string)$stmt->fetchColumn();
            if(!password_verify($current,$hash))throw new RuntimeException('Текущий пароль указан неверно.');
            if(strlen($new)<8)throw new RuntimeException('Новый пароль должен быть не короче 8 символов.');
            $stmt=db()->prepare('UPDATE users SET password_hash=? WHERE id=?');$stmt->execute([password_hash($new,PASSWORD_DEFAULT),(int)$user['id']]);flash('success','Пароль изменён.');
        }
        if($action==='telegram'){
            $enabled=isset($_POST['telegram_enabled'])?1:0;$chatId=trim($_POST['telegram_chat_id']??'');$hour=max(0,min(23,(int)($_POST['telegram_hour']??9)));$token=trim($_POST['telegram_token']??'');
            $existing=telegram_notification_settings();
            if($enabled && $chatId==='')throw new RuntimeException('Укажите Telegram chat ID.');
            if($enabled && !$token && empty($existing['secret_ciphertext']))throw new RuntimeException('Для первого включения укажите bot token.');
            if($token){[$cipher,$iv,$tag]=encrypt_notification_secret($token);$stmt=db()->prepare("UPDATE notification_settings SET enabled=?,destination=?,secret_ciphertext=?,secret_iv=?,secret_tag=?,send_hour=? WHERE channel='telegram'");$stmt->execute([$enabled,$chatId,$cipher,$iv,$tag,$hour]);}
            else{$stmt=db()->prepare("UPDATE notification_settings SET enabled=?,destination=?,send_hour=? WHERE channel='telegram'");$stmt->execute([$enabled,$chatId,$hour]);}
            flash('success','Настройки Telegram сохранены. Токен хранится зашифрованным.');
        }
        if($action==='customer'){
            $apiId=trim((string)($_POST['smsru_api_id']??''));
            if(isset($_POST['smsru_clear']))set_app_setting('smsru_api_id','');
            elseif($apiId!=='')set_app_setting('smsru_api_id',customer_auth_encrypt($apiId));
            set_app_setting('smsru_sender',mb_substr(trim((string)($_POST['smsru_sender']??'')),0,32));
            set_app_setting('smsru_test_mode',isset($_POST['smsru_test_mode'])?'1':'0');
            $loyalty=max(0,min(50,(float)($_POST['customer_loyalty_percent']??5)));
            set_app_setting('customer_loyalty_percent',(string)$loyalty);
            set_app_setting('customer_pickup_label',mb_substr(trim((string)($_POST['customer_pickup_label']??'Самовывоз из кофейни')),0,160));
            $origin=rtrim(trim((string)($_POST['customer_api_allowed_origin']??'')),'/');
            if($origin!==''&&!preg_match('#^https?://[^/]+$#i',$origin))throw new RuntimeException('Разрешённый origin должен выглядеть как https://order.example.ru без пути.');
            set_app_setting('customer_api_allowed_origin',$origin);
            flash('success','Настройки клиентского сайта и SMS.ru сохранены.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('settings.php');
}

$user=current_user();$telegram=telegram_notification_settings();$smsConfigured=customer_auth_smsru_configured();
page_header('Настройки');
?>
<div class="two-col"><div class="card"><div class="chart-head"><div><h2>Кофейня</h2><p>Основные параметры Kapouch</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="business"><label>Название<input name="coffee_name" value="<?=e((string)app_setting('coffee_name','Kapouch'))?>" required></label><label>Часовой пояс<input value="Иркутск (UTC+8)" disabled></label><label>Валюта<input name="currency" value="<?=e(app_currency())?>"></label><label>Открытие<input type="time" name="opening_hour" value="<?=e((string)app_setting('opening_hour','07:00'))?>"></label><label>Закрытие<input type="time" name="closing_hour" value="<?=e((string)app_setting('closing_hour','21:00'))?>"></label><div><button class="btn primary">Сохранить</button></div></form></div><div class="card"><div class="chart-head"><div><h2>Профиль владельца</h2><p>Имя и данные входа</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="profile"><label>Имя<input name="name" value="<?=e($user['name'])?>" required></label><label>Email<input type="email" name="email" value="<?=e($user['email'])?>" required></label><button class="btn primary">Обновить профиль</button></form><div class="section"><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="password"><label>Текущий пароль<input type="password" name="current_password" required></label><label>Новый пароль<input type="password" name="new_password" minlength="8" required></label><button class="btn ghost">Изменить пароль</button></form></div></div></div>
<div class="card section"><div class="chart-head"><div><h2>Клиентский сайт и SMS.ru</h2><p>Вход покупателей по SMS, лояльность и настройки переносимой витрины</p></div><span class="pill <?=$smsConfigured?'connected':''?>"><?=$smsConfigured?'SMS.ru настроен':'SMS.ru не настроен'?></span></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="customer"><label>SMS.ru api_id<input type="password" name="smsru_api_id" autocomplete="new-password" placeholder="<?=$smsConfigured?'•••••••• сохранён':'Вставьте api_id из SMS.ru'?>"><span class="muted">Ключ хранится зашифрованным. Если уже сохранён — оставьте пустым.</span></label><label>Отправитель SMS<input name="smsru_sender" value="<?=e((string)app_setting('smsru_sender',''))?>" placeholder="Необязательно"><span class="muted">Укажите только согласованное в SMS.ru имя. Иначе оставьте пустым.</span></label><label>Бонусы за выданный заказ, %<input type="number" min="0" max="50" step="0.1" name="customer_loyalty_percent" value="<?=e((string)app_setting('customer_loyalty_percent','5'))?>"></label><label>Точка самовывоза<input name="customer_pickup_label" value="<?=e((string)app_setting('customer_pickup_label','Самовывоз из кофейни'))?>"></label><label>Разрешённый origin будущего сайта<input name="customer_api_allowed_origin" value="<?=e((string)app_setting('customer_api_allowed_origin',''))?>" placeholder="https://order.example.ru"><span class="muted">Пока витрина внутри Kapouch — можно оставить пустым.</span></label><label><input type="checkbox" name="smsru_test_mode" value="1" <?=app_setting('smsru_test_mode','0')==='1'?'checked':''?>> Тестовый режим SMS.ru без реальной отправки</label><?php if($smsConfigured):?><label><input type="checkbox" name="smsru_clear" value="1"> Удалить сохранённый api_id SMS.ru</label><?php endif;?><div><button class="btn primary">Сохранить клиентские настройки</button></div></form><div class="alert warning" style="margin-top:14px">Код входа действует 5 минут. Повторная отправка — не чаще раза в минуту. На номер — не более 5 кодов в час, на IP — не более 20.</div></div>
<div class="card section"><div class="chart-head"><div><h2>Цели и нормативы</h2><p>Используются дашбордом, отчётом дня и предупреждениями</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="goals"><label>Цель выручки/месяц<input type="number" step="1000" min="0" name="monthly_revenue_goal" value="<?=e((string)app_setting('monthly_revenue_goal','0'))?>"></label><label>Цель прибыли/месяц<input type="number" step="1000" min="0" name="monthly_profit_goal" value="<?=e((string)app_setting('monthly_profit_goal','0'))?>"></label><label>Целевой food cost, %<input type="number" step="0.1" min="0" max="100" name="target_food_cost" value="<?=e((string)app_setting('target_food_cost','30'))?>"></label><label>Макс. доля расходов, %<input type="number" step="0.1" min="0" max="100" name="target_expense_load" value="<?=e((string)app_setting('target_expense_load','30'))?>"></label><div><button class="btn primary">Сохранить цели</button></div></form></div>
<div class="card section"><div class="chart-head"><div><h2>Telegram-отчёт</h2><p>Kapouch сможет автоматически отправлять вчерашнюю сводку владельцу</p></div><span class="pill <?=!empty($telegram['enabled'])?'connected':''?>"><?=!empty($telegram['enabled'])?'Включено':'Выключено'?></span></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="telegram"><label>Включить<label><input type="checkbox" name="telegram_enabled" value="1" <?=!empty($telegram['enabled'])?'checked':''?>> Автоматическая отправка</label></label><label>Chat ID<input name="telegram_chat_id" value="<?=e((string)($telegram['destination']??''))?>" placeholder="Например, 123456789"></label><label>Bot token<input type="password" name="telegram_token" placeholder="<?=!empty($telegram['secret_ciphertext'])?'•••••••••••• сохранён':'Токен от BotFather'?>" autocomplete="off"><span class="muted">Если токен уже сохранён, оставь поле пустым.</span></label><label>Час отправки<input type="number" min="0" max="23" name="telegram_hour" value="<?=e((string)($telegram['send_hour']??9))?>"><span class="muted">По времени Иркутска.</span></label><div><button class="btn primary">Сохранить Telegram</button></div></form></div>
<div class="card section"><div class="chart-head"><div><h2>Системная информация</h2></div></div><table><tbody><tr><td>Часовой пояс</td><td><strong><?=e(date_default_timezone_get())?></strong></td></tr><tr><td>Локальное время</td><td><strong><?=e(date('d.m.Y H:i:s'))?></strong></td></tr><tr><td>Бренд</td><td><strong>Kapouch</strong></td></tr></tbody></table></div>
<?php page_footer(); ?>