<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/online_orders.php';

$filter=(string)($_GET['filter']??'active');
if(!in_array($filter,['active','done','all'],true))$filter='active';

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
            online_orders_create_test();
            flash('success','Тестовый онлайн-заказ создан.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('online_orders.php?filter='.$filter);
}

require __DIR__.'/inc/layout.php';
$orders=online_orders_fetch($filter);
$user=current_user();$canSeeApi=in_array($user['role']??'',['owner','manager'],true);
$apiToken=$canSeeApi?online_orders_api_token():'';
page_header('Онлайн-заказы');
?>
<style>
.online-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:16px}.online-toolbar .actions{margin:0}.order-board{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.order-card{border:1px solid var(--line);border-radius:16px;background:#fff;padding:16px;min-width:0;box-shadow:var(--shadow)}.order-card[data-status="new"]{border-width:2px}.order-card[data-status="ready"]{box-shadow:0 0 0 2px rgba(65,120,70,.14)}.order-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.order-number{font-size:26px;font-weight:850;line-height:1}.order-age{font-size:12px;font-weight:700;color:var(--muted);margin-top:6px}.order-status{font-size:12px;font-weight:800;padding:7px 10px;border-radius:999px;background:var(--surface-soft);white-space:nowrap}.order-card[data-status="new"] .order-status{background:#fff0d9}.order-card[data-status="preparing"] .order-status{background:#f3e9dc}.order-card[data-status="ready"] .order-status{background:#e4f3e5}.order-meta{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.order-meta span{font-size:12px;background:var(--surface-soft);border:1px solid var(--line);padding:5px 8px;border-radius:999px}.order-items{display:grid;gap:9px;margin-top:12px}.order-item{display:flex;gap:10px;justify-content:space-between;border-top:1px solid var(--line);padding-top:9px}.order-item:first-child{border-top:0;padding-top:0}.order-item-name{font-weight:800;min-width:0}.order-item small{display:block;color:var(--muted);margin-top:3px}.order-qty{font-size:18px;font-weight:850;white-space:nowrap}.order-comment{margin-top:12px;padding:10px;border-radius:12px;background:#fff8ea;font-weight:650}.order-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.order-actions form{margin:0}.order-total{font-size:18px;font-weight:850;margin-top:12px}.live-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#4d8b55;margin-right:6px}.live-dot.off{background:#999}.api-token{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere;background:var(--surface-soft);padding:10px;border-radius:10px;border:1px solid var(--line)}.board-empty{text-align:center;padding:44px 20px;color:var(--muted)}body.online-fullscreen .sidebar,body.online-fullscreen .topbar{display:none}body.online-fullscreen .content{margin-left:0;width:100%;max-width:none;padding:18px}body.online-fullscreen .order-board{grid-template-columns:repeat(4,minmax(0,1fr))}@media(max-width:1180px){.order-board{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.order-board{grid-template-columns:1fr}.order-number{font-size:23px}body.online-fullscreen .order-board{grid-template-columns:1fr}}
</style>
<div class="online-toolbar">
    <div class="actions">
        <a class="btn <?=$filter==='active'?'primary':''?>" href="online_orders.php?filter=active">Активные</a>
        <a class="btn <?=$filter==='done'?'primary':''?>" href="online_orders.php?filter=done">Завершённые</a>
        <a class="btn <?=$filter==='all'?'primary':''?>" href="online_orders.php?filter=all">Все</a>
    </div>
    <div class="actions"><span class="muted" id="liveStatus"><span class="live-dot"></span>Live · каждые 5 сек</span><button class="btn ghost" type="button" id="soundToggle">🔇 Звук</button><button class="btn ghost" type="button" id="fullToggle">На весь экран</button></div>
</div>

<div class="order-board" id="orderBoard" data-filter="<?=e($filter)?>">
<?php if(!$orders):?><div class="card board-empty">Заказов в этой группе пока нет.</div><?php endif;?>
</div>

<?php if($canSeeApi):?>
<div class="card section"><div class="chart-head"><div><h2>Подключение сайта онлайн-заказов</h2><p>Эти данные нужны разработчику второго сайта. Токен не передавайте покупателям и не вставляйте в клиентский JavaScript.</p></div></div><div class="two-col"><div><div class="muted" style="font-size:12px;margin-bottom:6px">API endpoint</div><div class="api-token"><?=e((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'ваш-домен').rtrim(dirname($_SERVER['SCRIPT_NAME']??'/'),'/').'/api_online_orders.php')?></div></div><div><div class="muted" style="font-size:12px;margin-bottom:6px">X-Kapouch-Token</div><div class="api-token"><?=e($apiToken)?></div></div></div><div class="alert warning" style="margin-top:14px">API принимает <strong>POST JSON</strong> для создания/обновления заказа и <strong>GET ?external_id=...</strong> для получения текущего статуса. Один и тот же external_id повторно не создаёт дубль.</div><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="test"><button class="btn primary">Создать тестовый заказ</button></form></div></div>
<?php endif;?>

<script>
(function(){
const board=document.getElementById('orderBoard'),filter=board.dataset.filter,csrf=<?=json_encode(csrf_token(),JSON_UNESCAPED_UNICODE)?>;
let lastNewId=0,sound=false,firstLoad=true;
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function age(v){if(!v)return '';const d=new Date(String(v).replace(' ','T')),sec=Math.max(0,Math.floor((Date.now()-d.getTime())/1000));if(sec<60)return 'только что';const min=Math.floor(sec/60);if(min<60)return min+' мин';return Math.floor(min/60)+' ч '+(min%60)+' мин';}
function actionButton(o,status,label,primary){return '<form method="post"><input type="hidden" name="csrf" value="'+esc(csrf)+'"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="'+o.id+'"><input type="hidden" name="status" value="'+status+'"><button class="btn '+(primary?'primary':'ghost')+'">'+label+'</button></form>';}
function card(o){let items=(o.items||[]).map(i=>'<div class="order-item"><div class="order-item-name">'+esc(i.product_name)+(i.variant_name?'<small>'+esc(i.variant_name)+'</small>':'')+(i.item_comment?'<small>💬 '+esc(i.item_comment)+'</small>':'')+'</div><div class="order-qty">× '+esc(i.quantity)+'</div></div>').join('');let actions='';if(o.status==='new')actions=actionButton(o,'preparing','Начать готовить',true)+actionButton(o,'ready','Сразу готов',false)+actionButton(o,'cancelled','Отменить',false);if(o.status==='preparing')actions=actionButton(o,'ready','Готов',true)+actionButton(o,'cancelled','Отменить',false);if(o.status==='ready')actions=actionButton(o,'completed','Выдать заказ',true)+actionButton(o,'preparing','Вернуть в готовку',false);return '<article class="order-card" data-status="'+esc(o.status)+'"><div class="order-head"><div><div class="order-number">#'+esc(o.order_number)+'</div><div class="order-age">'+age(o.external_created_at||o.created_at)+'</div></div><div class="order-status">'+esc(o.status_label)+'</div></div><div class="order-meta">'+(o.customer_name?'<span>👤 '+esc(o.customer_name)+'</span>':'')+'<span>'+(o.fulfillment_type==='delivery'?'🚚':'☕')+' '+esc(o.fulfillment_label|| (o.fulfillment_type==='delivery'?'Доставка':'Самовывоз'))+'</span>'+(o.promised_at?'<span>⏱ к '+esc(String(o.promised_at).slice(11,16))+'</span>':'')+(o.payment_status?'<span>Оплата: '+esc(o.payment_status)+'</span>':'')+'</div><div class="order-items">'+items+'</div>'+(o.customer_comment?'<div class="order-comment">💬 '+esc(o.customer_comment)+'</div>':'')+'<div class="order-total">'+Number(o.total_amount||0).toLocaleString('ru-RU',{maximumFractionDigits:2})+' ₽</div><div class="order-actions">'+actions+'</div></article>';}
function beep(){if(!sound)return;try{const C=window.AudioContext||window.webkitAudioContext,ctx=new C(),osc=ctx.createOscillator(),gain=ctx.createGain();osc.connect(gain);gain.connect(ctx.destination);osc.frequency.value=880;gain.gain.value=.12;osc.start();setTimeout(()=>{osc.stop();ctx.close();},180);}catch(e){}}
async function refresh(){try{const r=await fetch('online_orders.php?feed=1&filter='+encodeURIComponent(filter),{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw new Error();const data=await r.json(),orders=data.orders||[];const newIds=orders.filter(o=>o.status==='new').map(o=>Number(o.id));const newest=newIds.length?Math.max(...newIds):0;if(!firstLoad&&newest>lastNewId)beep();lastNewId=Math.max(lastNewId,newest);firstLoad=false;board.innerHTML=orders.length?orders.map(card).join(''):'<div class="card board-empty">Заказов в этой группе пока нет.</div>';document.getElementById('liveStatus').innerHTML='<span class="live-dot"></span>Live · '+new Date().toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}catch(e){document.getElementById('liveStatus').innerHTML='<span class="live-dot off"></span>Нет связи';}}
document.getElementById('soundToggle').addEventListener('click',function(){sound=!sound;this.textContent=sound?'🔔 Звук включён':'🔇 Звук';if(sound)beep();});
document.getElementById('fullToggle').addEventListener('click',function(){document.body.classList.toggle('online-fullscreen');this.textContent=document.body.classList.contains('online-fullscreen')?'Обычный режим':'На весь экран';});
refresh();setInterval(refresh,5000);
})();
</script>
<?php page_footer();?>
