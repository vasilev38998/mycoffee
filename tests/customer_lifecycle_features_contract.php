<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$notifications=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/OrderNotifications.java');
$player=file_get_contents($root.'/evotor-app/app/src/main/java/ru/kapouch/evotor/BaristaAlertPlayer.java');
$gradle=file_get_contents($root.'/evotor-app/app/build.gradle');
$welcome=file_get_contents($root.'/inc/customer_welcome.php');
$auth=file_get_contents($root.'/api/customer_auth_verify.php');
$authCore=file_get_contents($root.'/inc/customer_auth.php');
$selfCall=file_get_contents($root.'/inc/customer_auth_self_call.php');
$authRequest=file_get_contents($root.'/api/customer_auth_request.php');
$authUi=file_get_contents($root.'/customer/assets/auth-call.js');
$config=file_get_contents($root.'/customer/config.js');
$loyaltyAdmin=file_get_contents($root.'/customer_loyalty_settings.php');
$birthday=file_get_contents($root.'/inc/customer_birthday.php');
$pushAdmin=file_get_contents($root.'/push_notifications.php');
$profileApi=file_get_contents($root.'/api/customer_profile.php');
$profileAvatarApi=file_get_contents($root.'/api/customer_profile_avatar.php');
$profileJs=file_get_contents($root.'/customer/assets/profile-plus.js');
$profileAvatarJs=file_get_contents($root.'/customer/assets/profile-avatar.js');
$cron=file_get_contents($root.'/cron/online_orders_sync.php');
$icon=file_get_contents($root.'/customer/assets/icon.svg');
$manifest=file_get_contents($root.'/api/customer_manifest.php');
$sw=file_get_contents($root.'/customer/sw.js');
$m40=file_get_contents($root.'/database/migrations/040_customer_birthday.sql');
$m41=file_get_contents($root.'/database/migrations/041_customer_welcome_bonus.sql');
$m44=file_get_contents($root.'/database/migrations/044_customer_profile_avatar.sql');
$mapsAdmin=file_get_contents($root.'/customer_maps.php');
$mapsApi=file_get_contents($root.'/api/customer_maps_public.php');
$mapsUi=file_get_contents($root.'/customer/assets/maps-reviews.js');
$access=file_get_contents($root.'/inc/access.php');
$layout=file_get_contents($root.'/inc/layout.php');
$checks=[
 'Evotor reminder runs every 15 seconds'=>str_contains($notifications,'REMINDER_DELAY_MS = 15_000L'),
 'Evotor reminder continues while order is new'=>str_contains($notifications,'return order != null && "new".equals(order.status);')&&!str_contains($notifications,'MAX_REMINDERS'),
 'each Evotor reminder cycle plays one explicit tone'=>str_contains($player,'play(context, 1);')&&!str_contains($player,'play(context, 3);'),
 'Evotor APK version is bumped'=>str_contains($gradle,'versionCode 36')&&str_contains($gradle,"versionName '1.2.29'"),
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
 'profile avatar migration exists'=>str_contains($m44,'ADD COLUMN avatar_path VARCHAR(255)'),
 'profile API exposes normalized avatar path'=>str_contains($profileApi,'avatar_path')&&str_contains($profileApi,'customer_media_public_path'),
 'avatar upload is authenticated and image-processed'=>str_contains($profileAvatarApi,'customer_auth_require()')&&str_contains($profileAvatarApi,'customer_media_save_upload')&&str_contains($profileAvatarApi,"'avatar-'.\$customerId")&&str_contains($profileAvatarApi,"action==='delete'"),
 'PWA avatar UI supports add change and delete'=>str_contains($profileAvatarJs,'Добавить фото')&&str_contains($profileAvatarJs,'Изменить фото')&&str_contains($profileAvatarJs,'profileAvatarRemove')&&str_contains($profileAvatarJs,'squareFile'),
 'PWA avatar appears on home greeting'=>str_contains($profileAvatarJs,"document.querySelector('.home-avatar')")&&str_contains($profileAvatarJs,'has-photo'),
 'avatar API is public authenticated transport'=>str_contains($access,"'customer_profile_avatar.php'"),
 'Kapouch logo replaces generic icon'=>str_contains($icon,'Фирменный жёлтый логотип Kapouch')&&str_contains($icon,'>KAPOUCH</text>')&&str_contains($icon,'КОФЕ С СОБОЙ'),
 'manifest uses refreshed Kapouch icon and launch splash'=>str_contains($manifest,'assets/icon.svg?v=2')&&str_contains($manifest,"'theme_color'=>'#f7f1e8'")&&str_contains($manifest,"'background_color'=>'#f7f1e8'"),
 'PWA cache refreshes profile and brand assets'=>str_contains($sw,"const CACHE='kapouch-pwa-v60'")&&str_contains($sw,"./assets/profile-plus.js?v=2")&&str_contains($sw,"./assets/profile-avatar.js?v=2")&&str_contains($sw,"./assets/icon.svg?v=2")&&str_contains($sw,'cacheFirst(req,CACHE)'),
 'SMS.ru call-code endpoint remains primary auth transport'=>str_contains($authCore,"curl_init('https://sms.ru/code/call')")&&str_contains($authRequest,"\$data['method']??'call'")&&str_contains($authRequest,"['call','self_call']"),
 'primary call auth sends phone user IP and API id'=>str_contains($authCore,"'api_id'=>\$apiId,'phone'=>\$digits,'ip'=>")&&str_contains($authCore,"preg_match('/^\\d{4}$/',\$code)"),
 'fallback uses SMS.ru user-originated callcheck flow'=>str_contains($selfCall,"curl_init('https://sms.ru/callcheck/add')")&&str_contains($selfCall,"curl_init('https://sms.ru/callcheck/status')")&&str_contains($selfCall,'check_status')&&str_contains($authRequest,'customer_auth_request_self_call($phone)'),
 'self-call challenge is signed and expires'=>str_contains($selfCall,"hash_hmac('sha256',\$body,customer_auth_secret_key())")&&str_contains($selfCall,"'exp'=>\$exp")&&str_contains($selfCall,'customer_auth_self_call_decode'),
 'self-call success issues normal customer session'=>str_contains($selfCall,'customer_sessions')&&str_contains($selfCall,'$status!==401')&&str_contains($selfCall,'customer_auth_self_call_issue_session')&&str_contains($auth,'customer_auth_verify_self_call'),
 'PWA fallback contains no SMS action'=>str_contains($authUi,'Позвонить самому')&&str_contains($authUi,'self_call')&&!str_contains($authUi,"requestAuth('sms'")&&!str_contains($authUi,'Получить код по SMS'),
 'PWA explains free outgoing verification call'=>str_contains($authUi,'деньги не спишутся')&&str_contains($authUi,'SMS.ru автоматически сбросит звонок')&&str_contains($authUi,'Ждём звонок'),
 'profile copy no longer claims SMS verification'=>str_contains($profileJs,'Номер подтверждён по телефону.')&&!str_contains($profileJs,'Номер подтверждён по SMS.'),
 'PWA loads refreshed call auth enhancement'=>str_contains($config,"callAuth.src='assets/auth-call.js?v=3'"),
 'PWA loads profile avatar enhancement'=>str_contains($config,"profileAvatar.src='assets/profile-avatar.js?v=2'"),
 'map settings are protected admin configuration'=>str_contains($access,"'customer_maps.php'")&&str_contains($layout,"['customer_maps.php','Карты и отзывы']")&&str_contains($mapsAdmin,'customer_2gis_url')&&str_contains($mapsAdmin,'customer_yandex_maps_url'),
 'public maps endpoint exposes 2GIS Yandex and review links'=>str_contains($access,"'customer_maps_public.php'")&&str_contains($mapsApi,'two_gis_review_url')&&str_contains($mapsApi,'yandex_review_url')&&str_contains($mapsApi,'https://2gis.ru/search/')&&str_contains($mapsApi,'https://yandex.ru/maps/?text='),
 'PWA shows map and review buttons'=>str_contains($mapsUi,'Найти нас и оставить отзыв')&&str_contains($mapsUi,'Kapouch в 2ГИС')&&str_contains($mapsUi,'Яндекс Карты')&&str_contains($mapsUi,'Оставить отзыв'),
 'PWA loads maps review module'=>str_contains($config,"mapsReviews.src='assets/maps-reviews.js?v=1'"),
];
foreach($checks as $label=>$ok){if(!$ok){fwrite(STDERR,"Customer lifecycle contract failed: {$label}\n");exit(1);}echo "OK: {$label}\n";}
echo "CUSTOMER LIFECYCLE FEATURES CONTRACT PASSED\n";
