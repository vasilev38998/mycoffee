<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$migration=file_get_contents($root.'/database/migrations/042_customer_wheel.sql');
$manualMigration=file_get_contents($root.'/database/migrations/043_customer_wheel_manual_attempts.sql');
$updater=file_get_contents($root.'/inc/updater.php');
$wheel=file_get_contents($root.'/inc/customer_wheel.php');
$api=file_get_contents($root.'/api/customer_wheel_api.php');
$access=file_get_contents($root.'/inc/access.php');
$admin=file_get_contents($root.'/customer_wheel.php');
$quote=file_get_contents($root.'/api/customer_order_quote.php');
$orders=file_get_contents($root.'/inc/customer_orders.php');
$checkout=file_get_contents($root.'/customer/assets/sixth-drink-checkout.js');
$ui=file_get_contents($root.'/customer/assets/wheel.js');
$polish=file_get_contents($root.'/customer/assets/wheel-polish.js');
$config=file_get_contents($root.'/customer/config.js');
$sw=file_get_contents($root.'/customer/sw.js');
$refunds=file_get_contents($root.'/customer_refunds.php');
$webhook=file_get_contents($root.'/api/customer_payment_yookassa_webhook.php');
$checks=[
 'wheel migration creates prizes and spin ledger'=>str_contains($migration,'CREATE TABLE IF NOT EXISTS customer_wheel_prizes')&&str_contains($migration,'CREATE TABLE IF NOT EXISTS customer_wheel_spins')&&str_contains($migration,'UNIQUE KEY uniq_customer_wheel_source_order'),
 'wheel supports requested prize families'=>str_contains($migration,"ENUM('points','stamp','free_drink','discount_percent')")&&str_contains($migration,"'Напиток в подарок'")&&str_contains($migration,"'−10% на напиток'"),
 'manual-attempt migration adds grant ledger and nullable order source'=>str_contains($manualMigration,'CREATE TABLE IF NOT EXISTS customer_wheel_attempt_grants')&&str_contains($manualMigration,'attempts_remaining')&&str_contains($manualMigration,'MODIFY source_order_id BIGINT UNSIGNED NULL')&&str_contains($manualMigration,'ADD COLUMN attempt_grant_id'),
 'schema fast path includes latest customer migration'=>str_contains($updater,'KAPOUCH_SCHEMA_VERSION = 44')&&str_contains($updater,'KAPOUCH_SCHEMA_MIGRATION_COUNT = 43'),
 'spin eligibility requires completed PWA order and minimum total'=>str_contains($wheel,"o.source='customer-web'")&&str_contains($wheel,"o.status='completed'")&&str_contains($wheel,'o.total_amount>=?')&&str_contains($wheel,'ws.id IS NULL'),
 'manual attempts are counted granted and consumed transactionally'=>str_contains($wheel,'function customer_wheel_manual_attempts')&&str_contains($wheel,'function customer_wheel_grant_manual_attempts')&&str_contains($wheel,'function customer_wheel_take_manual_attempt')&&str_contains($wheel,'attempts_remaining=attempts_remaining-1')&&str_contains($wheel,'FOR UPDATE'),
 'manual attempts bypass order cooldown without moving regular cooldown'=>str_contains($wheel,'source_order_id IS NOT NULL ORDER BY id DESC LIMIT 1')&&str_contains($wheel,"elseif(\$manual>0)\$reason='manual'")&&str_contains($wheel,"\$manualGrantId=customer_wheel_take_manual_attempt")&&str_contains($wheel,"\$manualGrantId!==null?'manual':'order'"),
 'public wheel status exposes gifted attempt balance'=>str_contains($wheel,"'manual_attempts'=>\$manual")&&str_contains($wheel,"\$manual>0||(")&&str_contains($wheel,"\$manual>0?null:customer_wheel_next_available_at"),
 'spin result is server weighted with crypto random'=>str_contains($wheel,'random_int(1,$total)')&&str_contains($wheel,'daily_limit')&&str_contains($wheel,'customer_wheel_pick_prize'),
 'points stamps and drinks are granted server side'=>str_contains($wheel,"operation_type,note) VALUES(?,NULL,?,'adjust',?)")&&str_contains($wheel,'stamp_delta,reward_delta,reward_value')&&str_contains($wheel,"'wheel:'.\$spinUuid")&&str_contains($wheel,'0,1,0'),
 'percentage voucher applies to one eligible drink'=>str_contains($wheel,'customer_wheel_discount_quote')&&str_contains($wheel,'customer_drink_loyalty_product_map()')&&str_contains($wheel,'$unit*$percent/100')&&str_contains($wheel,"reward_status='redeemed'"),
 'customer API requires authenticated profile'=>str_contains($api,'customer_auth_require()')&&str_contains($api,"customer_wheel_spin('")===false&&str_contains($api,'customer_wheel_spin($customerId)'),
 'gifted batches fit guarded spin rate limit'=>str_contains($api,"customer_wheel_spin','customer:'.\$customerId,60,3600")&&str_contains($api,"'source'=>(string)(\$spin['source']??'order')"),
 'wheel API is public transport while admin stays protected'=>str_contains($access,"'customer_wheel_api.php'")&&str_contains($access,"'customer_wheel.php'")&&!preg_match("/kapouch_public_pages\(\).*?'customer_wheel\.php'/s",$access),
 'admin exposes economics prize controls and attempt grants'=>str_contains($admin,'name="min_order"')&&str_contains($admin,'name="cooldown_hours"')&&str_contains($admin,'name="weight"')&&str_contains($admin,'name="daily_limit"')&&str_contains($admin,'≈ ')&&str_contains($admin,'name="action" value="grant_attempts"')&&str_contains($admin,'name="customer_ref"')&&str_contains($admin,'name="attempts"')&&str_contains($admin,'customer_wheel_grant_manual_attempts')&&str_contains($admin,'customer_wheel_attempt_grants'),
 'admin tracks who issued attempts and remaining count'=>str_contains($admin,'granted_by_user_id')&&str_contains($admin,'attempts_remaining')&&str_contains($admin,'Выдать попытки вручную')&&str_contains($admin,'От админа'),
 'checkout quote keeps wheel exclusive from gift and points'=>str_contains($quote,"\$mode==='wheel'")&&str_contains($quote,"'wheel_offer'")&&str_contains($quote,"'wheel_discount'")&&str_contains($orders,"\$loyaltyMode==='wheel'"),
 'wheel checkout uses edited PWA product name'=>str_contains($quote,'customer_product_settings')&&str_contains($quote,'customer_product_group_variants')&&str_contains($quote,'customer_product_groups')&&str_contains($quote,"\$wheelOffer['product_name']=\$pwaName"),
 'payment failure and full refund restore voucher'=>str_contains($orders,"customer_wheel_restore_order_discount(\$orderId,'платёж СБП не был создан'")&&str_contains($refunds,'customer_wheel_restore_order_discount')&&str_contains($webhook,'customer_wheel_restore_order_discount'),
 'PWA renders pure SVG prize icons'=>str_contains($ui,'function svgIcon')&&str_contains($ui,'function buildWheelSvg')&&str_contains($ui,'<path d=')&&str_contains($ui,'wheel-pointer'),
 'PWA wheel uses long eased server-targeted spin'=>str_contains($ui,'5.1s cubic-bezier(.12,.78,.12,1)')&&str_contains($ui,'spin.prize_index')&&str_contains($ui,'6)*360')&&str_contains($ui,'wheelStage')&&str_contains($ui,'spinning'),
 'PWA celebrates with confetti vibration and reduced motion'=>str_contains($ui,'wheel-confetti')&&str_contains($ui,'navigator.vibrate')&&str_contains($ui,'prefers-reduced-motion'),
 'wheel uses server result rather than client prize selection'=>str_contains($ui,"body:JSON.stringify({action:'spin'})")&&str_contains($ui,'const spin=d.spin||{}')&&str_contains($ui,'showResult(prize)'),
 'wheel home copy has correct Russian declension'=>str_contains($polish,"word(n,'вращение','вращения','вращений')")&&str_contains($polish,"word(n,'подарочная попытка','подарочные попытки','подарочных попыток')"),
 'wheel home icon is premium vector artwork'=>str_contains($polish,'data-kapouch-premium-wheel')&&str_contains($polish,'kwhRim')&&str_contains($polish,'kwhHub')&&str_contains($polish,'feDropShadow'),
 'checkout selector includes wheel voucher'=>str_contains($checkout,"data-loyalty-mode=\"wheel\"")&&str_contains($checkout,'wheel_reward_id:wheelRewardId()')&&str_contains($checkout,'Оставить приз на потом'),
 'config loads wheel polish and forwards selected reward'=>str_contains($config,"wheel.src='assets/wheel.js?v=1'")&&str_contains($config,"wheelPolish.src='assets/wheel-polish.js?v=1'")&&str_contains($config,"payload.wheel_reward_id=wheelRewardId()")&&str_contains($config,'customer_wheel_api.php'),
 'service worker cache remains network-fresh for wheel'=>str_contains($sw,"const CACHE='kapouch-pwa-v57'")&&str_contains($sw,"./assets/wheel.js?v=1")&&str_contains($sw,"./assets/wheel-polish.js?v=1")&&str_contains($sw,'customer_wheel_api.php')&&str_contains($sw,"url.pathname.endsWith('/assets/wheel.js')")&&str_contains($sw,"url.pathname.endsWith('/assets/wheel-polish.js')"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Customer wheel contract failed: {$label}\n");exit(1);}echo "OK: {$label}\n";}
echo "CUSTOMER WHEEL CONTRACT PASSED\n";
