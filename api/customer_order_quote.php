<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';
require_once dirname(__DIR__).'/inc/customer_loyalty_runtime.php';
require_once dirname(__DIR__).'/inc/customer_drink_loyalty.php';
require_once dirname(__DIR__).'/inc/customer_same_order_gift.php';
require_once dirname(__DIR__).'/inc/customer_checkout_loyalty.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $customer=customer_auth_current();if(!$customer)customer_api_reply(401,['ok'=>false,'error'=>'Войдите в профиль, чтобы рассчитать скидки.']);
    $data=customer_api_json();$items=$data['items']??[];if(!is_array($items))throw new RuntimeException('Некорректная корзина.');
    $customerId=(int)$customer['id'];
    // The checkout UI can quote repeatedly while quantities/options change.
    // Historical reconciliation is much heavier than the quote itself, so run
    // it at most once per short customer window across quote/profile/card.
    customer_loyalty_refresh_customer_if_due($customerId,30,20);

    // Always calculate the possible sixth-drink gift once so the PWA can show
    // it as an alternative. If points are selected, the gift discount is not
    // included in the payable base and the reward stays untouched.
    $mode=customer_checkout_loyalty_mode($data);
    $quote=customer_same_order_gift_quote($customerId,$items);
    $giftOffer=$quote['gift']??null;
    if($mode!=='gift'){
        $quote['discount']=0.0;
        $quote['gift']=null;
        $quote['total']=round(max(0,(float)($quote['subtotal']??0)),2);
    }

    $beforePoints=round(max(0,(float)($quote['total']??0)),2);
    $requestedSpend=$mode==='points'?($data['loyalty_spend']??0):0;
    $points=customer_loyalty_quote_spend($customerId,$beforePoints,$requestedSpend);
    $quote['gift_offer']=$giftOffer;
    $quote['gift_available']=is_array($giftOffer)&&!empty($giftOffer);
    $quote['loyalty_mode']=$mode;
    $quote['total_before_points']=$beforePoints;
    $quote['loyalty_balance']=$points['balance'];
    $quote['loyalty_spend_max']=$points['max_spend'];
    $quote['loyalty_spend']=$points['spend'];
    $quote['loyalty_spend_percent']=$points['spend_percent'];
    $quote['total']=$points['total'];
    $quote['loyalty_percent']=customer_loyalty_rate();
    $quote['loyalty_expected']=customer_loyalty_preview((float)$quote['total']);
    customer_api_reply(200,['ok'=>true,'quote'=>$quote]);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);}
catch(RuntimeException $e){customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('[Kapouch customer quote] '.$e->getMessage());customer_api_reply(500,['ok'=>false,'error'=>'Не удалось рассчитать корзину.']);}