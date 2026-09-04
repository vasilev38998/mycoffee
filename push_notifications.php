<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/customer_push.php';
require_once __DIR__.'/inc/customer_pwa.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='campaign'){
            $segment=(string)($_POST['segment']??'all');$category=(int)($_POST['category_id']??0);
            $r=customer_push_create_campaign((string)($_POST['title']??''),(string)($_POST['body']??''),(string)($_POST['target_url']??'./#home'),$segment,$category>0?$category:null,(int)(current_user()['id']??0));
            $sent=customer_push_process_queue(30);
            audit_write('customer_push_campaign','Создана push-рассылка #'.$r['id'].' для '.$r['recipients'].' клиентов','customer_push_campaign',(string)$r['id']);
            flash('success','Рассылка поставлена в очередь: '.$r['recipients'].' клиентов. Сразу обработано: '.$sent['processed'].'.');
        }elseif($action==='process'){
            $r=customer_push_process_queue(100);flash('success','Очередь обработана: '.$r['processed'].', отправлено '.$r['sent'].', ошибок '.$r['failed'].'.');
        }elseif($action==='subject'){
            $subject=trim((string)($_POST['vapid_subject']??''));if($subject!==''&&!preg_match('#^(mailto:|https://)#i',$subject))throw new RuntimeException('Контакт VAPID должен начинаться с mailto: или https://');set_app_setting('customer_push_vapid_subject',$subject);flash('success','Контакт VAPID сохранён.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('push_notifications.php');
}

$stats=customer_push_stats();$categories=customer_pwa_categories(true);$campaigns=db()->query('SELECT c.*,cc.name category_name FROM customer_push_campaigns c LEFT JOIN customer_categories cc ON cc.id=c.category_id ORDER BY c.id DESC LIMIT 30')->fetchAll();
$subject=customer_push_vapid_subject();
page_header('Push-уведомления');
?>
<div class="three-col">
  <div class="insight-card"><div class="kicker">Активные подписки</div><strong><?=$stats['active_subscriptions']?></strong><p>Устройства с разрешёнными push.</p></div>
  <div class="insight-card"><div class="kicker">Клиенты с push</div><strong><?=$stats['customers']?></strong><p>Уникальные подтверждённые клиенты.</p></div>
  <div class="insight-card"><div class="kicker">В очереди</div><strong><?=$stats['queued']?></strong><p>Будут обработаны минутным cron.</p></div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Новая push-рассылка</h2><p>Отправка идёт подписанным клиентам. Сегмент «Категория» строится по фактической истории покупок.</p></div></div>
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="campaign">
<label>Заголовок<input name="title" maxlength="120" required placeholder="Например: Лимонады сегодня −20%"></label>
<label>Сегмент<select name="segment" id="pushSegment"><option value="all">Все подписанные</option><option value="active30">Покупали за последние 30 дней</option><option value="inactive30">Не покупали последние 30 дней</option><option value="bonus">Есть бонусный баланс</option><option value="category">Покупали выбранную категорию</option></select></label>
<label>Категория<select name="category_id"><option value="0">—</option><?php foreach($categories as $c):?><option value="<?=$c['id']?>"><?=e($c['icon'].' '.$c['name'])?></option><?php endforeach;?></select></label>
<label>Куда открыть<input name="target_url" value="./#home" placeholder="./#menu"></label>
<label style="grid-column:1/-1">Текст<textarea name="body" maxlength="500" required rows="4" placeholder="Короткое сообщение клиенту"></textarea></label>
<div><button class="btn primary">Поставить рассылку в очередь</button></div></form></div>

<div class="card section"><div class="chart-head"><div><h2>Техническая настройка Web Push</h2><p>VAPID-ключи генерируются Kapouch автоматически и сохраняются один раз.</p></div></div>
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="subject"><label>Контакт VAPID<input name="vapid_subject" value="<?=e($subject)?>" placeholder="mailto:owner@example.ru"><span class="muted">Используется push-сервисами как контакт отправителя.</span></label><div><button class="btn ghost">Сохранить</button></div></form>
<div class="actions" style="margin-top:12px"><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="process"><button class="btn ghost">Обработать очередь сейчас</button></form></div></div>

<div class="card section"><div class="chart-head"><div><h2>История рассылок</h2></div></div><div style="overflow:auto"><table><thead><tr><th>Дата</th><th>Сообщение</th><th>Сегмент</th><th>Получателей</th><th>Отправлено</th><th>Ошибок</th><th>Статус</th></tr></thead><tbody><?php foreach($campaigns as $c):?><tr><td><?=e(date('d.m H:i',strtotime((string)$c['created_at'])))?></td><td><strong><?=e($c['title'])?></strong><div class="muted"><?=e($c['body'])?></div></td><td><?=e($c['segment_type'].($c['category_name']?' · '.$c['category_name']:''))?></td><td><?=$c['recipient_count']?></td><td><?=$c['sent_count']?></td><td><?=$c['failed_count']?></td><td><?=e($c['status'])?></td></tr><?php endforeach;?><?php if(!$campaigns):?><tr><td colspan="7" class="muted">Рассылок ещё не было.</td></tr><?php endif;?></tbody></table></div></div>
<?php page_footer();?>
