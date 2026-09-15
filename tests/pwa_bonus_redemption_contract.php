<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$loyalty=file_get_contents($root.'/inc/customer_loyalty.php');
$orders=file_get_contents($root.'/inc/customer_orders.php');
$quote=file_get_contents($root.'/api/customer_order_quote.php');
$status=file_get_contents($root.'/api/customer_order_status.php');
$evotor=file_get_contents($root.'/api/evotor_loyalty_discount.php');
$ui=file_get_contents($root.'/customer/assets/sixth-drink-checkout.js');
$uiCss=file_get_contents($root.'/customer/assets/sixth-drink-checkout.css');
$checkout=file_get_contents($root.'/customer/assets/payments.js');
$config=file_get_contents($root.'/customer/config.js');
$index=file_get_contents($root.'/customer/index.html');
$sw=file_get_contents($root.'/customer/sw.js');

$giftPos=strpos($orders,'customer_drink_loyalty_apply_online_order_reward($orderId,$customerId)');
$spendPos=strpos($orders,'customer_loyalty_apply_order_spend($orderId,$customerId');
$checks=[
  'quote accepts requested point spend'=>str_contains($quote,"\$data['loyalty_spend']")&&str_contains($quote,'customer_loyalty_quote_spend'),
  'quote exposes balance maximum and applied spend'=>str_contains($quote,"loyalty_spend_max")&&str_contains($quote,"loyalty_spend")&&str_contains($quote,"total_before_points"),
  'sixth drink is applied before ordinary points'=>$giftPos!==false&&$spendPos!==false&&$giftPos<$spendPos,
  'server restricts point spend to customer web orders'=>str_contains($loyalty,"source']!=='customer-web'")&&str_contains($loyalty,'Списание бонусов доступно только в приложении Kapouch'),
  'point spend is idempotent per order'=>str_contains($loyalty,'customer_loyalty_order_spend($orderId,$pdo)')&&str_contains($loyalty,"operation_type='spend'"),
  'point discount updates fiscal line prices'=>str_contains($loyalty,'UPDATE online_order_items SET unit_price=?,line_total=?,item_comment=?'),
  'cancelled orders restore spent points'=>str_contains($loyalty,'customer_loyalty_restore_order_spend')&&str_contains($status,'customer_loyalty_restore_order_spend'),
  'cash register endpoint only handles sixth drink'=>str_contains($evotor,'customer_drink_loyalty_summary')&&str_contains($evotor,'customer_evotor_reward_pending')&&!str_contains($evotor,'customer_loyalty_apply_order_spend')&&!str_contains($evotor,"operation_type='spend'"),
  'PWA offers point amount and spend-all controls'=>str_contains($ui,'loyaltySpendInput')&&str_contains($ui,'Списать все')&&str_contains($ui,'1 бонус = 1 ₽'),
  'PWA quote sends point spend'=>str_contains($ui,'loyalty_spend:requestedSpend()'),
  'checkout submits point spend'=>str_contains($checkout,'loyalty_spend:loyaltySpend()'),
  'checkout handles zero due without demanding SBP URL'=>str_contains($checkout,"finalMethod=String(order.payment_method||method)")&&str_contains($checkout,"if(finalMethod==='sbp')"),
  'point controls have styles'=>str_contains($uiCss,'.loyalty-spend-controls')&&str_contains($uiCss,'.loyalty-spend-head'),
  'fresh checkout assets are loaded'=>str_contains($config,'sixth-drink-checkout.js?v=3')&&str_contains($config,'sixth-drink-checkout.css?v=2')&&str_contains($index,'config.js?v=11')&&str_contains($index,'payments.js?v=7'),
  'service worker shell bumped'=>str_contains($sw,"kapouch-pwa-v40")&&str_contains($sw,'./assets/sixth-drink-checkout.js?v=3')&&str_contains($sw,'./assets/payments.js?v=7'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA bonus redemption contract failed: {$label}\n");exit(1);}}
echo "PWA BONUS REDEMPTION CONTRACT PASSED\n";
