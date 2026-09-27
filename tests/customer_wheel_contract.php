<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$migration=file_get_contents($root.'/database/migrations/042_customer_wheel.sql');
$updater=file_get_contents($root.'/inc/updater.php');
$wheel=file_get_contents($root.'/inc/customer_wheel.php');
$api=file_get_contents($root.'/api/customer_wheel_api.php');
$access=file_get_contents($root.'/inc/access.php');
$admin=file_get_contents($root.'/customer_wheel.php');
$quote=file_get_contents($root.'/api/customer_order_quote.php');
$orders=file_get_contents($root.'/inc/customer_orders.php');
$checkout=file_get_contents($root.'/customer/assets/sixth-drink-checkout.js');
$ui=file_get_contents($root.'/customer/assets/wheel.js');
$config=file_get_contents($root.'/customer/config.js');
$sw=file_get_contents($root.'/customer/sw.js');
$refunds=file_get_contents($root.'/customer_refunds.php');
$webhook=file_get_contents($root.'/api/customer_payment_yookassa_webhook.php');
$checks=[
 'wheel migration creates prizes and spin ledger'=>str_contains($migration,'CREATE TABLE IF NOT EXISTS customer_wheel_prizes')&&str_contains($migration,'CREATE TABLE IF NOT EXISTS customer_wheel_spins')&&str_contains($migration,'UNIQUE KEY uniq_customer_wheel_source_order'),
 'wheel supports requested prize families'=>str_contains($migration,"ENUM('points','stamp','free_drink','discount_percent')")&&str_contains($migration,"'Напиток в подарок'")&&str_contains($migration,"'−10% на напиток'"),
 'schema fast path includes wheel migration'=>str_contains($updater,'KAPOUCH_SCHEMA_VERSION = 42')&&str_contains($updater,'KAPOUCH_SCHEMA_MIGRATION_COUNT = 41'),
 'spin eligibility requires completed PWA order and minimum total'=>str_contains($wheel,"o.source='customer-web'")&&str_contains($wheel,"o.status='completed'")&&str_contains($wheel,'o.total_amount>=?')&&str_contains($wheel,'ws.id IS NULL'),
 'spin result is server weighted with crypto random'=>str_contains($wheel,'random_int(1,$total)')&&str_contains($wheel,'daily_limit')&&str_contains($wheel,'customer_wheel_pick_prize'),
 'points stamps and drinks are granted server side'=>str_contains($wheel,"operation_type,note) VALUES(?,NULL,?,'adjust',?)")&&str_contains($wheel,'stamp_delta,reward_delta,reward_value')&&str_contains($wheel,"'wheel:'.\$spinUuid")&&str_contains($wheel,'0,1,0'),
 'percentage voucher applies to one eligible drink'=>str_contains($wheel,'customer_wheel_discount_quote')&&str_contains($wheel,'customer_drink_loyalty_product_map()')&&str_contains($wheel,'$unit*$percent/100')&&str_contains($wheel,"reward_status='redeemed'"),
 'customer API requires authenticated profile'=>str_contains($api,'customer_auth_require()')&&str_contains($api,"customer_wheel_spin('")===false&&str_contains($api,'customer_wheel_spin($customerId)'),
 'wheel API is public transport while admin stays protected'=>str_contains($access,"'customer_wheel_api.php'")&&str_contains($access,"'customer_wheel.php'")&&!preg_match("/kapouch_public_pages\(\).*?'customer_wheel\.php'/s",$access),
 'admin exposes economics and prize controls'=>str_contains($admin,'name="min_order"')&&str_contains($admin,'name="cooldown_hours"')&&str_contains($admin,'name="weight"')&&str_contains($admin,'name="daily_limit"')&&str_contains($admin,'≈ '),
 'checkout quote keeps wheel exclusive from gift and points'=>str_contains($quote,"\$mode==='wheel'")&&str_contains($quote,"'wheel_offer'")&&str_contains($quote,"'wheel_discount'")&&str_contains($orders,"\$loyaltyMode==='wheel'"),
 'payment failure and full refund restore voucher'=>str_contains($orders,"customer_wheel_restore_order_discount(\$orderId,'платёж СБП не был создан'")&&str_contains($refunds,'customer_wheel_restore_order_discount')&&str_contains($webhook,'customer_wheel_restore_order_discount'),
 'PWA renders pure SVG prize icons'=>str_contains($ui,'function svgIcon')&&str_contains($ui,'function buildWheelSvg')&&str_contains($ui,'<path d=')&&str_contains($ui,'wheel-pointer'),
 'PWA wheel uses long eased server-targeted spin'=>str_contains($ui,'5.1s cubic-bezier(.12,.78,.12,1)')&&str_contains($ui,'spin.prize_index')&&str_contains($ui,'6)*360')&&str_contains($ui,'wheelStage')&&str_contains($ui,'spinning'),
 'PWA celebrates with confetti vibration and reduced motion'=>str_contains($ui,'wheel-confetti')&&str_contains($ui,'navigator.vibrate')&&str_contains($ui,'prefers-reduced-motion'),
 'wheel uses server result rather than client prize selection'=>str_contains($ui,"body:JSON.stringify({action:'spin'})")&&str_contains($ui,'const spin=d.spin||{}')&&str_contains($ui,'showResult(prize)'),
 'checkout selector includes wheel voucher'=>str_contains($checkout,"data-loyalty-mode=\"wheel\"")&&str_contains($checkout,'wheel_reward_id:wheelRewardId()')&&str_contains($checkout,'Оставить приз на потом'),
 'config loads wheel and forwards selected reward'=>str_contains($config,"wheel.src='assets/wheel.js?v=1'")&&str_contains($config,"payload.wheel_reward_id=wheelRewardId()")&&str_contains($config,'customer_wheel_api.php'),
 'service worker cache is refreshed for wheel'=>str_contains($sw,"const CACHE='kapouch-pwa-v56'")&&str_contains($sw,"./assets/wheel.js?v=1")&&str_contains($sw,'customer_wheel_api.php'),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Customer wheel contract failed: {$label}\n");exit(1);}echo "OK: {$label}\n";}
echo "CUSTOMER WHEEL CONTRACT PASSED\n";
