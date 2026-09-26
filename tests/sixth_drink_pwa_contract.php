<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$drink=file_get_contents($root.'/inc/customer_drink_loyalty.php');
$sameOrder=file_get_contents($root.'/inc/customer_same_order_gift.php');
$loyalty=file_get_contents($root.'/inc/customer_loyalty.php');
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
  'online order reward is applied server-side when gift is chosen'=>str_contains($orders,"if(\$loyaltyMode==='gift')")&&str_contains($orders,'customer_drink_loyalty_apply_online_order_reward($orderId,$customerId)'),
  'gift line is excluded from paid stamps'=>str_contains($drink,'$giftLineId')&&str_contains($drink,'$units--'),
  'cancelled gift can be restored'=>str_contains($drink,'customer_drink_loyalty_restore_online_order_reward'),
  'order polling reconciles sixth drink through idempotent completion path'=>str_contains($status,'customer_loyalty_on_order_completed($orderId)')&&str_contains($loyalty,'customer_drink_loyalty_credit_online_order($orderId,$customerId)'),
  'profile refreshes loyalty including sixth drink'=>str_contains($profile,'customer_loyalty_refresh_customer_if_due($customerId,30,20)')&&str_contains($loyalty,'customer_drink_loyalty_refresh_customer($customerId,$limit)'),
  'profile avoids duplicate sixth drink refresh'=>!str_contains($profile,'customer_drink_loyalty_refresh_customer($customerId);'),
  'QR card uses coalesced loyalty refresh including sixth drink'=>str_contains($cardApi,'customer_loyalty_refresh_customer_if_due($customerId,30,20)')&&str_contains($loyalty,'customer_drink_loyalty_refresh_customer($customerId,$limit)'),
  'quote API requires auth and quotes same-order gift'=>str_contains($quote,'customer_auth_current()')&&str_contains($quote,'customer_same_order_gift_quote'),
  'checkout can unlock gift inside current order'=>str_contains($sameOrder,'customer_same_order_gift_should_unlock')&&str_contains($sameOrder,'customer_same_order_gift_insert_provisional'),
  'points choice bypasses provisional same-order gift'=>str_contains($sameOrder,"customer_checkout_loyalty_mode(\$data)!=='gift'"),
  'actual checkout refreshes stamps before same-order decision'=>str_contains($sameOrder,'customer_drink_loyalty_refresh_customer($customerId,100)')&&str_contains($orderApi,'customer_same_order_gift_create'),
  'same-order gift requires a subsequent eligible drink'=>str_contains($sameOrder,'customer_same_order_gift_eligible_units($items)>$needed'),
  'quote cashback uses final amount due'=>str_contains($quote,"customer_loyalty_preview((float)\$quote['total'])")&&str_contains($quote,"\$quote['loyalty_expected']"),
  'actual cashback uses final order total'=>str_contains($loyalty,"customer_loyalty_preview((float)\$row['total_amount'])"),
  'PWA renders live gift quote and explicit choice'=>str_contains($checkoutJs,'customer_order_quote.php')&&str_contains($checkoutJs,'Подарок «6-й напиток»')&&str_contains($checkoutJs,'Как использовать лояльность?'),
  'PWA cashback copy uses amount due'=>str_contains($checkoutJs,'loyalty_expected')&&str_contains($checkoutJs,'% от суммы к оплате'),
  'QR card listens for completed order'=>str_contains($cardJs,'kapouch-order-status')&&str_contains($cardJs,"status==='completed'"),
  'PWA loads refreshed sixth drink checkout'=>str_contains($config,'assets/sixth-drink-checkout.js?v=6')&&str_contains($config,'assets/sixth-drink-checkout.css?v=3'),
  'service worker refreshes polish and gift assets'=>str_contains($sw,"kapouch-pwa-v44")&&str_contains($sw,'./assets/sixth-drink-checkout.js?v=6')&&str_contains($sw,'./assets/sixth-drink-checkout.css?v=3')&&str_contains($sw,'./assets/pwa-polish.js?v=3')&&str_contains($sw,'./assets/hero-cup.svg?v=2'),
  'YooKassa returns to canonical app domain with order token'=>str_contains($payments,"customer_public_app_url(\$returnPath)")&&str_contains($payments,"payment-return.html'.(\$trackingToken!==''?'?token='"),
  'public QR uses canonical app URL'=>str_contains($qr,'return customer_public_app_url();'),
  'final migration pins app and API origins'=>str_contains($migration,"https://app.kapouch.store/")&&str_contains($migration,"https://kapouch.store/api/"),
  'zero-ruble SBP gift has local return'=>str_contains($orderApi,"customer_public_app_url('payment-return.html?gift=1')")&&str_contains($return,"params.get('gift')==='1'"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Sixth-drink PWA contract failed: {$label}\n");exit(1);}}
echo "Sixth-drink PWA contract passed\n";