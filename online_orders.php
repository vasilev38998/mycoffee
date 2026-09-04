<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/online_orders.php';

$filter=(string)($_GET['filter']??'active');
if(!in_array($filter,['active','done','all'],true))$filter='active';
$user=current_user();$canManage=in_array($user['role']??'',['owner','manager'],true);

if(isset($_GET['feed'])){
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>true,'orders'=>online_orders_fetch($filter),'server_time'=>date('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='status'){
            online_orders_transition((int)($_POST['id']??0),(string)($_POST['status']??''));
            flash('success','Статус заказа обновлён.');
        }elseif($action==='test'){
            $id=online_orders_create_test();
            flash('success','Тестовый заказ #'.$id.' создан тем же обработчиком, что используется для реальных заказов.');
        }elseif($action==='clear_tests'){
            if(!$canManage)throw new RuntimeException('Недостаточно прав.');
            $count=online_orders_clear_tests();flash('success','Удалено тестовых заказов: '.$count.'.');
        }elseif($action==='pull_settings'){
            if(!$canManage)throw new RuntimeException('Недостаточно прав.');
            $url=trim((string)($_POST['pull_url']??''));
            if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!preg_match('#^https?://#i',$url)))throw new RuntimeException('Укажите корректный http/https URL источника заказов.');
            set_app_setting('online_orders_pull_url',$url);
            if(isset($_POST['clear_pull_token']))set_app_setting('online_orders_pull_token','');
            else{
                $token=trim((string)($_POST['pull_token']??''));
                if($token!=='')set_app_setting('online_orders_pull_token',online_orders_encrypt_secret($token));
            }
            flash('success','Настройки минутной синхронизации сохранены.');
        }elseif($action==='pull_now'){
            if(!$canManage)throw new RuntimeException('Недостаточно прав.');
            $r=online_orders_pull_once();
            flash('success',$r['configured']?'Синхронизация выполнена. Получено: '.$r['received'].' · новых: '.$r['created'].' · обновлено: '.$r['updated'].'.':'URL источника пока не настроен. Push API продолжает работать без cron.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('online_orders.php?filter='.$filter);
}

require __DIR__.'/inc/layout.php';
$orders=online_orders_fetch($filter);
$apiToken=$canManage?online_orders_api_token():'';
$pullUrl=$canManage?(string)app_setting('online_orders_pull_url',''):'';
$pullTokenSet=$canManage&&(string)app_setting('online_orders_pull_token','')!=='';
$lastPull=$canManage?system_meta('online_orders_last_pull_at','')??'':'';
$lastPullError=$canManage?system_meta('online_orders_last_pull_error','')??'':'';
page_header('Онлайн-заказы');
?>
<style>
.online-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:16px}.online-toolbar .actions{margin:0}.order-lanes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;align-items:start}.order-lane{min-width:0;background:rgba(255,255,255,.42);border:1px solid var(--line);border-radius:18px;padding:12px}.lane-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}.lane-head h2{font-size:16px;margin:0}.lane-count{min-width:28px;text-align:center;border-radius:999px;padding:4px 8px;background:var(--surface-soft);font-weight:800}.lane-cards{display:grid;gap:10px}.order-card{border:1px solid var(--line);border-radius:15px;background:#fff;padding:15px;min-width:0;box-shadow:var(--shadow)}.order-card[data-status="new"]{border-left:4px solid #c88735}.order-card[data-status="preparing"]{border-left:4px solid #8b684b}.order-card[data-status="ready"]{border-left:4px solid #54865b}.order-card[data-status="cancelled"]{opacity:.72}.order-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.order-number{font-size:24px;font-weight:850;line-height:1.05;overflow-wrap:anywhere}.order-age{font-size:12px;font-weight:700;color:var(--muted);margin-top:6px}.order-status{font-size:12px;font-weight:800;padding:6px 9px;border-radius:999px;background:var(--surface-soft);white-space:nowrap}.order-meta{display:flex;gap:7px;flex-wrap:wrap;margin:11px 0}.order-meta span{font-size:12px;background:var(--surface-soft);border:1px solid var(--line);padding:5px 8px;border-radius:999px}.order-items{display:grid;gap:8px;margin-top:10px}.order-item{display:flex;gap:10px;justify-content:space-between;border-top:1px solid var(--line);padding-top:8px}.order-item:first-child{border-top:0;padding-top:0}.order-item-name{font-weight:800;min-width:0}.order-item small{display:block;color:var(--muted);margin-top:3px}.order-qty{font-size:17px;font-weight:850;white-space:nowrap}.order-comment{margin-top:11px;padding:9px;border-radius:11px;background:#fff8ea;font-weight:650}.order-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:13px}.order-actions form{margin:0}.order-total{font-size:18px;font-weight:850;margin-top:11px}.live-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#4d8b55;margin-right:6px}.live-dot.off{background:#999}.api-token{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere;background:var(--surface-soft);padding:10px;border-radius:10px;border:1px solid var(--line)}.lane-empty{text-align:center;padding:26px 12px;color:var(--muted);font-size:13px}body.online-fullscreen .sidebar,body.online-fullscreen .topbar{display:none}body.online-fullscreen .content{margin-left:0;width:100%;max-width:none;padding:16px}body.online-fullscreen .order-lanes{grid-template-columns:repeat(3,minmax(0,1fr))}@media(max-width:1050px){.order-lanes{grid-template-columns:1fr}.order-lane{padding:10px}.lane-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.lane-cards{grid-template-columns:1fr}.order-number{font-size:22px}body.online-fullscreen .order-lanes{grid-template-columns:1fr}}
</style>
<div class="online-toolbar">
    <div class="actions"><a class="btn <?=$filter==='active'?'primary':''?>" href="online_orders.php?filter=active">Активные</a><a class="btn <?=$filter==='done'?'primary':''?>" href="online_orders.php?filter=done">Завершённые</a><a class="btn <?=$filter==='all'?'primary':''?>" href="online_orders.php?filter=all">Все</a></div>
    <div class="actions"><span class="muted" id="liveStatus"><span class="live-dot"></span>Live · каждые 5 сек</span><button class="btn ghost" type="button" id="soundToggle">🔇 Звук</button><button class="btn ghost" type="button" id="fullToggle">На весь экран</button></div>
</div>
<div id="orderBoard"></div>

<?php if($canManage):?>
<div class="card section"><div class="chart-head"><div><h2>Тестирование</h2><p>Тестовый заказ теперь создаётся через тот же обработчик JSON, что и реальный заказ с сайта.</p></div></div><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="test"><button class="btn primary">Создать тестовый заказ</button></form><form method="post" onsubmit="return confirm('Удалить все тестовые заказы Kapouch?')"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="clear_tests"><button class="btn ghost">Удалить тестовые</button></form></div></div>

<div class="card section"><div class="chart-head"><div><h2>Получение заказов с отдельного сайта</h2><p>Основной push API работает мгновенно. Дополнительно cron раз в минуту может сам забирать список заказов с указанного URL — это резервный или самостоятельный способ интеграции.</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="pull_settings"><label>URL JSON-ленты заказов<input type="url" name="pull_url" value="<?=e($pullUrl)?>" placeholder="https://site.ru/api/kapouch/orders"></label><label>Bearer-токен сайта-источника <span class="muted"><?=$pullTokenSet?'токен уже сохранён — оставьте пустым, чтобы не менять':''?></span><input type="password" name="pull_token" autocomplete="new-password" placeholder="Необязательно"></label><?php if($pullTokenSet):?><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="clear_pull_token" value="1" style="width:auto"> Удалить сохранённый токен источника</label><?php endif;?><div><button class="btn primary">Сохранить настройки cron</button></div></form><div class="actions" style="margin-top:12px"><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="pull_now"><button class="btn ghost">Проверить синхронизацию сейчас</button></form></div><div class="muted" style="font-size:12px;margin-top:10px">Последний успешный запуск: <?=e($lastPull?:'ещё не было')?><?=$lastPullError?' · последняя ошибка: '.e($lastPullError):''?></div></div>

<div class="card section"><div class="chart-head"><div><h2>Push API для сайта заказов</h2><p>Если сайт отправляет заказ сам, он появляется в Kapouch сразу — ждать cron не нужно.</p></div></div><div class="two-col"><div><div class="muted" style="font-size:12px;margin-bottom:6px">API endpoint</div><div class="api-token"><?=e((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'ваш-домен').rtrim(dirname($_SERVER['SCRIPT_NAME']??'/'),'/').'/api_online_orders.php')?></div></div><div><div class="muted" style="font-size:12px;margin-bottom:6px">X-Kapouch-Token</div><div class="api-token"><?=e($apiToken)?></div></div></div></div>
<?php endif;?>

<script>
(function(){
const board=document.getElementById('orderBoard'),filter=<?=json_encode($filter)?>,csrf=<?=json_encode(csrf_token(),JSON_UNESCAPED_UNICODE)?>,initialOrders=<?=json_encode($orders,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
let lastNewId=0,sound=false,firstLoad=true;
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function qty(v){const n=Number(v||0);return Number.isInteger(n)?String(n):n.toLocaleString('ru-RU',{maximumFractionDigits:3});}
function age(sec){sec=Math.max(0,Number(sec||0));if(sec<60)return 'только что';const min=Math.floor(sec/60);if(min<60)return min+' мин назад';const h=Math.floor(min/60);return h+' ч '+(min%60)+' мин назад';}
function actionButton(o,status,label,primary){return '<form method="post"><input type="hidden" name="csrf" value="'+esc(csrf)+'"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="'+Number(o.id)+'"><input type="hidden" name="status" value="'+esc(status)+'"><button class="btn '+(primary?'primary':'ghost')+'">'+esc(label)+'</button></form>';}
function card(o){let items=(o.items||[]).map(i=>'<div class="order-item"><div class="order-item-name">'+esc(i.product_name)+(i.variant_name?'<small>'+esc(i.variant_name)+'</small>':'')+(i.item_comment?'<small>💬 '+esc(i.item_comment)+'</small>':'')+'</div><div class="order-qty">× '+qty(i.quantity)+'</div></div>').join('');let actions='';if(o.status==='new')actions=actionButton(o,'preparing','Начать готовить',true)+actionButton(o,'ready','Готов',false)+actionButton(o,'cancelled','Отменить',false);if(o.status==='preparing')actions=actionButton(o,'ready','Готов',true)+actionButton(o,'cancelled','Отменить',false);if(o.status==='ready')actions=actionButton(o,'completed','Выдать',true)+actionButton(o,'preparing','Вернуть в готовку',false);return '<article class="order-card" data-status="'+esc(o.status)+'"><div class="order-head"><div><div class="order-number">#'+esc(o.order_number)+'</div><div class="order-age">'+age(o.age_seconds)+' · '+esc(o.created_display||'')+'</div></div><div class="order-status">'+esc(o.status_label)+'</div></div><div class="order-meta">'+(o.customer_name?'<span>👤 '+esc(o.customer_name)+'</span>':'')+'<span>'+(o.fulfillment_type==='delivery'?'🚚':'☕')+' '+esc(o.fulfillment_display||'Самовывоз')+'</span>'+(o.promised_display?'<span>⏱ к '+esc(o.promised_display)+'</span>':'')+(o.payment_status?'<span>💳 '+esc(o.payment_label)+'</span>':'')+'</div><div class="order-items">'+items+'</div>'+(o.customer_comment?'<div class="order-comment">💬 '+esc(o.customer_comment)+'</div>':'')+'<div class="order-total">'+Number(o.total_amount||0).toLocaleString('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:2})+' ₽</div><div class="order-actions">'+actions+'</div></article>';}
function lane(status,label,orders){const rows=orders.filter(o=>o.status===status);return '<section class="order-lane"><div class="lane-head"><h2>'+esc(label)+'</h2><span class="lane-count">'+rows.length+'</span></div><div class="lane-cards">'+(rows.length?rows.map(card).join(''):'<div class="lane-empty">Нет заказов</div>')+'</div></section>';}
function render(orders){const definitions=filter==='active'?[['new','Новые'],['preparing','Готовятся'],['ready','Готовы']]:filter==='done'?[['completed','Выданы'],['cancelled','Отменены']]:[['new','Новые'],['preparing','Готовятся'],['ready','Готовы'],['completed','Выданы'],['cancelled','Отменены']];board.innerHTML='<div class="order-lanes">'+definitions.map(d=>lane(d[0],d[1],orders)).join('')+'</div>';}
function beep(){if(!sound)return;try{const C=window.AudioContext||window.webkitAudioContext,ctx=new C(),osc=ctx.createOscillator(),gain=ctx.createGain();osc.connect(gain);gain.connect(ctx.destination);osc.frequency.value=880;gain.gain.value=.12;osc.start();setTimeout(()=>{osc.stop();ctx.close();},180);}catch(e){}}
function observeNew(orders){const ids=orders.filter(o=>o.status==='new').map(o=>Number(o.id));const newest=ids.length?Math.max(...ids):0;if(!firstLoad&&newest>lastNewId)beep();lastNewId=Math.max(lastNewId,newest);firstLoad=false;}
async function refresh(){try{const r=await fetch('online_orders.php?feed=1&filter='+encodeURIComponent(filter),{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw new Error();const data=await r.json(),orders=data.orders||[];observeNew(orders);render(orders);document.getElementById('liveStatus').innerHTML='<span class="live-dot"></span>Live · '+new Date().toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}catch(e){document.getElementById('liveStatus').innerHTML='<span class="live-dot off"></span>Нет связи';}}
render(initialOrders);observeNew(initialOrders);
document.getElementById('soundToggle').addEventListener('click',function(){sound=!sound;this.textContent=sound?'🔔 Звук включён':'🔇 Звук';if(sound)beep();});
document.getElementById('fullToggle').addEventListener('click',function(){document.body.classList.toggle('online-fullscreen');this.textContent=document.body.classList.contains('online-fullscreen')?'Обычный режим':'На весь экран';});
setInterval(refresh,5000);
})();
</script>
<?php page_footer();?>
