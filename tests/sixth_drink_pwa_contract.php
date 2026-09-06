<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$drink=file_get_contents($root.'/inc/customer_drink_loyalty.php');
$orders=file_get_contents($root.'/inc/customer_orders.php');
$status=file_get_contents($root.'/api/customer_order_status.php');
$profile=file_get_contents($root.'/api/customer_profile.php');
$cardApi=file_get_contents($root.'/api/customer_loyalty_card.php');
$quote=file_get_contents($root.'/api/customer_order_quote.php');
$cardJs=file_get_contents($root.'/customer/assets/loyalty-card.js');
$checkoutJs=file_get_contents($root.'/customer/assets/sixth-drink-checkout.js');
$config=file_get_contents($root.'/customer/config.js');
$sw=file_get_contents($root.'/customer/sw.js');
$payments=file_get_contents($root.'/inc/customer_payments.php');
$qr=file_get_contents($root.'/customer_qr.php');
$migration=file_get_contents($root.'/database/migrations/037_customer_pwa_subdomain_finalize.sql');
$orderApi=file_get_contents($root.'/api/customer_order.php');
$return=file_get_contents($root.'/customer/payment-return.html');

$checks=[
  'online order reward is applied server-side'=>str_contains($orders,'customer_drink_loyalty_apply_online_order_reward($orderId,$customerId)'),
  'gift line is excluded from paid stamps'=>str_contains($drink,'$giftLineId')&&str_contains($drink,'$units--'),
  'cancelled gift can be restored'=>str_contains($drink,'customer_drink_loyalty_restore_online_order_reward'),
  'order polling credits sixth drink live'=>str_contains($status,'customer_drink_loyalty_credit_online_order'),
  'profile refreshes sixth drink'=>str_contains($profile,'customer_drink_loyalty_refresh_customer($customerId)'),
  'QR card refreshes sixth drink'=>str_contains($cardApi,'customer_drink_loyalty_refresh_customer($customerId)'),
  'quote API requires auth and quotes cart'=>str_contains($quote,'customer_auth_current()')&&str_contains($quote,'customer_drink_loyalty_quote_cart'),
  'PWA renders live gift quote'=>str_contains($checkoutJs,'customer_order_quote.php')&&str_contains($checkoutJs,'Подарок «6-й напиток»'),
  'QR card listens for completed order'=>str_contains($cardJs,'kapouch-order-status')&&str_contains($cardJs,"status==='completed'"),
  'PWA loads sixth drink checkout'=>str_contains($config,'assets/sixth-drink-checkout.js?v=1'),
  'service worker v31 includes gift assets'=>str_contains($sw,'kapouch-pwa-v31')&&str_contains($sw,'./assets/sixth-drink-checkout.js?v=1'),
  'YooKassa returns to canonical app domain'=>str_contains($payments,"customer_public_app_url('payment-return.html')"),
  'public QR uses canonical app URL'=>str_contains($qr,'return customer_public_app_url();'),
  'final migration pins app and API origins'=>str_contains($migration,"https://app.kapouch.store/")&&str_contains($migration,"https://kapouch.store/api/"),
  'zero-ruble SBP gift has local return'=>str_contains($orderApi,"customer_public_app_url('payment-return.html?gift=1')")&&str_contains($return,"params.get('gift')==='1'"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Sixth-drink PWA contract failed: {$label}\n");exit(1);}}
echo "Sixth-drink PWA contract passed\n";
