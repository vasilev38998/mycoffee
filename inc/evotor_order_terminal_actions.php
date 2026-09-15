<?php
declare(strict_types=1);

require_once __DIR__.'/evotor_order_notifications.php';
require_once __DIR__.'/online_orders.php';

/**
 * Applies an action coming from the Evotor terminal.
 *
 * accept/ready keep their existing idempotent semantics because the Android
 * client may retry them through the cookie bridge after a broken POST. The
 * final hand-off therefore uses a distinct complete action so a retry can
 * never accidentally advance a merely-ready order twice.
 */
function evotor_order_terminal_action_apply(int $orderId,string $action): array
{
    $action=trim($action);
    if($action!=='complete')return evotor_order_action_apply($orderId,$action);

    $stmt=db()->prepare('SELECT id,order_number,source,status,payment_status FROM online_orders WHERE id=? LIMIT 1');
    $stmt->execute([$orderId]);
    $order=$stmt->fetch();
    if(!$order||(string)$order['source']!=='customer-web')throw new RuntimeException('PWA-заказ не найден.');

    $status=(string)$order['status'];
    if($status==='cancelled')throw new RuntimeException('Заказ уже отменён.');
    if($status==='ready')online_orders_transition($orderId,'completed');
    elseif($status!=='completed')throw new RuntimeException('Сначала отметьте заказ готовым.');

    $stmt=db()->prepare('SELECT id,order_number,status,payment_status FROM online_orders WHERE id=? LIMIT 1');
    $stmt->execute([$orderId]);
    $current=$stmt->fetch();
    if(!$current)throw new RuntimeException('Заказ не найден после изменения статуса.');
    return [
        'order_id'=>(int)$current['id'],
        'order_number'=>(string)$current['order_number'],
        'status'=>(string)$current['status'],
        'status_label'=>online_orders_status_label((string)$current['status']),
        'payment_status'=>(string)($current['payment_status']??''),
    ];
}
