<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$notifications=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/OrderNotifications.java');
$player=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/BaristaAlertPlayer.java');
$gradle=file_get_contents($root.'/evotor-app/app/build.gradle');
$welcome=file_get_contents($root.'/inc/customer_welcome.php');
$auth=file_get_contents($root.'/api/customer_auth_verify.php');
$loyaltyAdmin=file_get_contents($root.'/customer_loyalty_settings.php');
$birthday=file_get_contents($root.'/inc/customer_birthday.php');
$pushAdmin=file_get_contents($root.'/push_notifications.php');
$profileApi=file_get_contents($root.'/api/customer_profile.php');
$profileJs=file_get_contents($root.'/customer/assets/profile-plus.js');
$cron=file_get_contents($root.'/cron/online_orders_sync.php');
$icon=file_get_contents($root.'/customer/assets/icon.svg');
$manifest=file_get_contents($root.'/api/customer_manifest.php');
$sw=file_get_contents($root.'/customer/sw.js');
$m40=file_get_contents($root.'/database/migrations/040_customer_birthday.sql');
$m41=file_get_contents($root.'/database/migrations/041_customer_welcome_bonus.sql');
$checks=[
 'Evotor reminder runs every 15 seconds'=>str_contains($notifications,'REMINDER_DELAY_MS = 15_000L'),
 'Evotor reminder continues while order is new'=>str_contains($notifications,'return order != null && "new".equals(order.status);')&&!str_contains($notifications,'MAX_REMINDERS'),
 'each Evotor reminder cycle plays one explicit tone'=>str_contains($player,'play(context, 1);')&&!str_contains($player,'play(context, 3);'),
 'Evotor APK version is bumped'=>str_contains($gradle,"versionCode 36")&&str_contains($gradle,"versionName '1.2.29'"),
 'birthday column migration exists'=>str_contains($m40,'ADD COLUMN birth_date DATE'),
 'existing customers are excluded from retroactive welcome bonus'=>str_contains($m41,'welcome_bonus_granted_at')&&str_contains($m41,'UPDATE customer_accounts'),
 'welcome bonus is idempotent and configurable'=>str_contains($welcome,"app_setting('customer_welcome_bonus','100')")&&str_contains($welcome,'welcome_bonus_granted_at=NOW()'),
 'welcome bonus is granted after successful authentication'=>str_contains($auth,'customer_welcome_bonus_grant($customerId)')&&str_contains($auth,"\$auth['welcome_bonus']"),
 'admin can configure welcome bonus'=>str_contains($loyaltyAdmin,'name="customer_welcome_bonus"')&&str_contains($loyaltyAdmin,"set_app_setting('customer_welcome_bonus'"),
 'profile stores and validates birth date'=>str_contains($profileApi,'birth_date')&&str_contains($profileApi,"createFromFormat('!Y-m-d'"),
 'PWA profile exposes birthday input'=>str_contains($profileJs,'id="profileBirthdayInput"')&&str_contains($profileJs,'birth_date:birthdayInput.value.trim()'),
 'birthday push has configurable time and copy'=>str_contains($birthday,'customer_birthday_push_time')&&str_contains($birthday,'customer_birthday_push_title')&&str_contains($birthday,'customer_birthday_push_body'),
 'birthday push is deduplicated yearly'=>str_contains($birthday,"'birthday:'.\$customerId.':'.\$now->format('Y')"),
 'birthday automation is called by minute cron'=>str_contains($cron,'customer_birthday_push_enqueue_due()'),
 'admin exposes birthday notification settings'=>str_contains($pushAdmin,'name="birthday_time"')&&str_contains($pushAdmin,'name="birthday_title"')&&str_contains($pushAdmin,'name="birthday_body"'),
 'Kapouch logo replaces generic icon'=>str_contains($icon,'Фирменный жёлтый логотип Kapouch')&&str_contains($icon,'>KAPOUCH</text>')&&str_contains($icon,'КОФЕ С СОБОЙ'),
 'manifest uses refreshed Kapouch icon'=>str_contains($manifest,'assets/icon.svg?v=2')&&str_contains($manifest,"'theme_color'=>'#ffd523'"),
 'PWA cache refreshes profile and brand assets'=>str_contains($sw,"const CACHE='kapouch-pwa-v55'")&&str_contains($sw,"url.pathname.endsWith('/assets/profile-plus.js')")&&str_contains($sw,"url.pathname.endsWith('/assets/icon.svg')"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Customer lifecycle contract failed: {$label}\n");exit(1);}echo "OK: {$label}\n";}
echo "CUSTOMER LIFECYCLE FEATURES CONTRACT PASSED\n";
