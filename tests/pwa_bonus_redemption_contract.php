<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$loyalty=file_get_contents($root.'/inc/customer_loyalty.php');
$choice=file_get_contents($root.'/inc/customer_checkout_loyalty.php');
$orders=file_get_contents($root.'/inc/customer_orders.php');
$sameOrder=file_get_contents($root.'/inc/customer_same_order_gift.php');
$quote=file_get_contents($root.'/api/customer_order_quote.php');
$status=file_get_contents($root.'/api/customer_order_status.php');
$evotor=file_get_contents($root.'/api/evotor_loyalty_discount.php');
$ui=file_get_contents($root.'/customer/assets/sixth-drink-checkout.js');
$uiCss=file_get_contents($root.'/customer/assets/sixth-drink-checkout.css');
$checkout=file_get_contents($root.'/customer/assets/payments.js');
$config=file_get_contents($root.'/customer/config.js');
$index=file_get_contents($root.'/customer/index.html');
$sw=file_get_contents($root.'/customer/sw.js');

$checks=[
  'loyalty mode resolver supports gift points wheel and none'=>str_contains($choice,"['gift','points','wheel','none']")&&str_contains($choice,"return \$requested>0?'points':'gift'"),
  'quote accepts requested point spend'=>str_contains($quote,"\$data['loyalty_spend']")&&str_contains($quote,'customer_loyalty_quote_spend'),
  'quote exposes alternative gift wheel and selected loyalty mode'=>str_contains($quote,"\$quote['gift_offer']")&&str_contains($quote,"\$quote['gift_available']")&&str_contains($quote,"\$quote['wheel_offer']")&&str_contains($quote,"\$quote['loyalty_mode']"),
  'non-gift quote removes sixth-drink discount'=>str_contains($quote,"if(\$mode!=='gift')")&&str_contains($quote,"\$quote['discount']=0.0")&&str_contains($quote,"\$quote['gift']=null")&&str_contains($quote,"\$quote['total']=\$subtotal"),
  'configured percentage caps quote and actual spend'=>str_contains($loyalty,'function customer_loyalty_spend_percent')&&str_contains($loyalty,'customer_loyalty_spend_limit($due)')&&str_contains($loyalty,'min($wanted,$balance,$due,$limit)'),
  'server applies gift points or wheel in exclusive branches'=>str_contains($orders,"if(\$loyaltyMode==='gift')")&&str_contains($orders,"elseif(\$loyaltyMode==='points')")&&str_contains($orders,"elseif(\$loyaltyMode==='wheel')")&&str_contains($orders,'customer_drink_loyalty_apply_online_order_reward($orderId,$customerId)')&&str_contains($orders,'customer_loyalty_apply_order_spend($orderId,$customerId'),
  'same-order gift is bypassed when another loyalty mode is selected'=>str_contains($sameOrder,"customer_checkout_loyalty_mode(\$data)!=='gift'")&&str_contains($sameOrder,'return customer_order_create($data,$customer);'),
  'server restricts point spend to customer web orders'=>str_contains($loyalty,"source']!=='customer-web'")&&str_contains($loyalty,'Списание бонусов доступно только в приложении Kapouch'),
  'point spend is idempotent per order'=>str_contains($loyalty,'customer_loyalty_order_spend($orderId,$pdo)')&&str_contains($loyalty,"operation_type='spend'"),
  'point discount updates fiscal line prices'=>str_contains($loyalty,'UPDATE online_order_items SET unit_price=?,line_total=?,item_comment=?'),
  'cancelled orders restore spent points'=>str_contains($loyalty,'customer_loyalty_restore_order_spend')&&str_contains($status,'customer_loyalty_restore_order_spend'),
  'cash register endpoint only handles sixth drink'=>str_contains($evotor,'customer_drink_loyalty_summary')&&str_contains($evotor,'customer_evotor_reward_pending')&&!str_contains($evotor,'customer_loyalty_apply_order_spend')&&!str_contains($evotor,"operation_type='spend'"),
  'PWA visibly offers gift points and wheel'=>str_contains($ui,'Как использовать лояльность?')&&str_contains($ui,'На один заказ можно выбрать только один вариант')&&str_contains($ui,'data-loyalty-mode="gift"')&&str_contains($ui,'data-loyalty-mode="points"')&&str_contains($ui,'data-loyalty-mode="wheel"'),
  'PWA preserves gift when another reward is used'=>str_contains($ui,'Подарок «6-й напиток» сохранён')&&str_contains($ui,'Бесплатный напиток останется доступен'),
  'PWA offers capped point controls'=>str_contains($ui,'loyaltySpendInput')&&str_contains($ui,'Списать максимум')&&str_contains($ui,'loyalty_spend_percent')&&str_contains($ui,'1 бонус = 1 ₽'),
  'PWA quote sends point spend mode and wheel reward'=>str_contains($ui,'loyalty_spend:requestedSpend()')&&str_contains($ui,'loyalty_mode:loyaltyMode()')&&str_contains($ui,'wheel_reward_id:wheelRewardId()'),
  'checkout still submits requested point amount'=>str_contains($checkout,'loyalty_spend:loyaltySpend()'),
  'fetch wrapper injects explicit loyalty mode into checkout'=>str_contains($config,'function attachLoyaltyMode')&&str_contains($config,'payload.loyalty_mode=loyaltyMode()')&&str_contains($config,'init=attachLoyaltyMode(input,init)'),
  'checkout handles zero due without demanding SBP URL'=>str_contains($checkout,"finalMethod=String(order.payment_method||method)")&&str_contains($checkout,"if(finalMethod==='sbp')"),
  'choice and point controls have styles'=>str_contains($uiCss,'.loyalty-choice-option')&&str_contains($uiCss,'.loyalty-spend-controls')&&str_contains($uiCss,'.loyalty-spend-head'),
  'fresh checkout assets are loaded'=>str_contains($config,'sixth-drink-checkout.js?v=7')&&str_contains($config,'sixth-drink-checkout.css?v=4')&&str_contains($index,'config.js?v=13')&&str_contains($index,'payments.js?v=9'),
  'service worker shell keeps loyalty assets fresh'=>str_contains($sw,"kapouch-pwa-v56")&&str_contains($sw,'./assets/sixth-drink-checkout.js?v=7')&&str_contains($sw,'./assets/sixth-drink-checkout.css?v=4')&&str_contains($sw,'./assets/payments.js?v=9'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"PWA bonus redemption contract failed: {$label}\n");exit(1);}}
echo "PWA BONUS REDEMPTION CONTRACT PASSED\n";