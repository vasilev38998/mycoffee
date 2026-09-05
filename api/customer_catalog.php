<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_orders.php';
require_once dirname(__DIR__).'/inc/customer_pwa.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $catalog=customer_pwa_catalog();
    $growth=[
        'scheduled_pickup'=>(string)app_setting('customer_scheduled_pickup_enabled','1')==='1',
        'promo_enabled'=>(string)app_setting('customer_promo_enabled','0')==='1',
        'promo_kicker'=>(string)app_setting('customer_promo_kicker','АКЦИЯ'),
        'promo_title'=>(string)app_setting('customer_promo_title',''),
        'promo_text'=>(string)app_setting('customer_promo_text',''),
        'promo_button'=>(string)app_setting('customer_promo_button','Выбрать напиток'),
        'promo_target'=>(string)app_setting('customer_promo_target','menu'),
        'personal_offer_enabled'=>(string)app_setting('customer_personal_offer_enabled','1')==='1',
        'loyalty_levels_enabled'=>(string)app_setting('customer_loyalty_levels_enabled','1')==='1',
    ];
    customer_api_reply(200,['ok'=>true,'shop'=>array_merge($catalog['settings'],['currency'=>app_currency(),'loyalty_percent'=>customer_loyalty_rate(),'growth'=>$growth]),'categories'=>$catalog['categories'],'products'=>$catalog['products']]);
}catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось загрузить каталог.']);}
