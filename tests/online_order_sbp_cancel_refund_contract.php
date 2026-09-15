<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$page=file_get_contents($root.'/online_orders.php');
$orders=file_get_contents($root.'/inc/online_orders.php');
$payments=file_get_contents($root.'/inc/customer_payments.php');

$checks=[
    'normal cancel button remains for non-SBP orders'=>str_contains($page,"return actionButton(o,'cancelled','Отменить',false)"),
    'paid SBP detection checks both paid status and provider'=>str_contains($page,"o.payment_status==='paid'&&o.payment_provider==='yookassa_sbp'"),
    'paid SBP button is renamed'=>str_contains($page,'Отменить и вернуть деньги'),
    'paid SBP cancel uses dedicated action'=>str_contains($page,'name="action" value="cancel_refund"'),
    'refund action is manager protected'=>str_contains($page,"if(!\$canManage)throw new RuntimeException('Недостаточно прав для возврата оплаты.')"),
    'refund action validates paid YooKassa SBP'=>str_contains($page,"(string)\$order['payment_status']!=='paid'||(string)\$order['payment_provider']!=='yookassa_sbp'"),
    'refund action calls YooKassa full refund'=>str_contains($page,'customer_payment_yookassa_refund_full($orderId)'),
    'successful refund reverses cashback'=>str_contains($page,'customer_loyalty_reverse_order($orderId)'),
    'refund success automatically cancels active order'=>str_contains($payments,"status=CASE WHEN status IN ('new','preparing','ready') THEN 'cancelled' ELSE status END"),
    'plain paid SBP cancel stays blocked'=>str_contains($orders,"Сначала выполните возврат покупателю, затем заказ будет отменён автоматически"),
];
foreach($checks as $label=>$ok){
    if(!$ok){fwrite(STDERR,"SBP cancel/refund contract failed: {$label}\n");exit(1);}
}
echo "SBP CANCEL / AUTO REFUND CONTRACT PASSED\n";
