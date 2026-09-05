<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/customer_payments.php';

$user=current_user();
if(!in_array($user['role']??'',['owner','manager'],true)){
    http_response_code(403);exit('Недостаточно прав.');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        $orderId=(int)($_POST['order_id']??0);
        if($orderId<=0)throw new RuntimeException('Заказ не найден.');
        if($action==='refund_full'){
            $result=customer_payment_yookassa_refund_full($orderId);
            $state=(string)($result['status']??'');
            if($state==='succeeded')flash('success','Полный возврат выполнен. ЮKassa автоматически сформирует чек возврата на основе исходного чека.');
            elseif($state==='pending')flash('warning','Возврат принят ЮKassa и ещё обрабатывается. Статус обновится по webhook.');
            else flash('warning','ЮKassa вернула статус возврата: '.$state.'.');
        }elseif($action==='sync_refund'){
            $payment=customer_payment_status_for_order($orderId);
            $refundId=trim((string)($payment['provider_refund_id']??''));
            if($refundId==='')throw new RuntimeException('Идентификатор возврата ещё не сохранён.');
            $result=customer_payment_yookassa_sync_refund($refundId);
            flash('success','Статус возврата обновлён: '.(string)($result['status']??'неизвестно').'.');
        }else throw new RuntimeException('Неизвестное действие.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_refunds.php');
}

require __DIR__.'/inc/layout.php';
$rows=customer_payment_refundable_orders(150);
page_header('Возвраты ЮKassa');
?>
<div class="card"><div class="chart-head"><div><h2>Возвраты онлайн-оплаты</h2><p>Полный возврат отправляется на тот же способ оплаты, которым покупатель оплатил заказ. Для полного возврата «Чеки от ЮKassa» формируют чек возврата автоматически из исходного чека.</p></div><a class="btn ghost" href="customer_payments.php">← Настройки оплаты</a></div></div>
<div class="alert warning section"><strong>Возврат необратим.</strong> Перед подтверждением проверьте номер заказа и сумму. Сейчас Kapouch поддерживает только полный возврат всей суммы платежа — это специально сделано, чтобы не сформировать некорректный частичный чек.</div>
<div class="alert info section"><strong>Webhook:</strong> кроме <code>payment.succeeded</code> рекомендуется включить событие <code>refund.succeeded</code>. Если возврат сначала получит статус pending, Kapouch завершит его обработку после уведомления ЮKassa.</div>

<div class="card section">
<?php if(!$rows):?>
<p class="muted">Оплаченных через ЮKassa заказов для возврата пока нет.</p>
<?php else:?>
<div class="table-wrap"><table><thead><tr><th>Заказ</th><th>Покупатель</th><th>Сумма</th><th>Оплата</th><th>Возврат</th><th></th></tr></thead><tbody>
<?php foreach($rows as $row):
$refundStatus=(string)($row['refund_status']??'');
$refunded=((string)$row['payment_record_status']==='refunded'||$refundStatus==='succeeded');
$pending=((string)$row['payment_record_status']==='refund_pending'||$refundStatus==='pending');
?>
<tr>
<td><strong>#<?=e((string)$row['order_number'])?></strong><div class="muted" style="font-size:12px"><?=e((string)$row['order_status'])?></div></td>
<td><?=e((string)($row['customer_name']?:'—'))?><div class="muted" style="font-size:12px"><?=e((string)($row['customer_phone']?:''))?></div></td>
<td><strong><?=number_format((float)$row['amount'],2,',',' ')?> ₽</strong></td>
<td><?=e((string)$row['payment_status'])?><div class="muted" style="font-size:12px"><?=e((string)($row['paid_at']?:''))?></div></td>
<td><?php if($refunded):?><span class="pill connected">Возвращено</span><?php elseif($pending):?><span class="pill">Обрабатывается</span><?php elseif($refundStatus==='canceled'):?><span class="pill">Ошибка возврата</span><?php else:?><span class="pill">Не возвращено</span><?php endif;?></td>
<td><div class="actions">
<?php if(!$refunded&&!$pending):?>
<form method="post" onsubmit="return confirm('Вернуть покупателю <?=e(number_format((float)$row['amount'],2,',',' '))?> ₽ по заказу #<?=e((string)$row['order_number'])?>? Действие необратимо.')"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="refund_full"><input type="hidden" name="order_id" value="<?=(int)$row['order_id']?>"><button class="btn danger">Вернуть всю сумму</button></form>
<?php elseif($pending):?>
<form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sync_refund"><input type="hidden" name="order_id" value="<?=(int)$row['order_id']?>"><button class="btn ghost">Проверить статус</button></form>
<?php endif;?>
</div></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</div>
<?php page_footer();?>
