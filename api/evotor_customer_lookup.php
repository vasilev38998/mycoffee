<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';
require_once dirname(__DIR__).'/inc/customer_loyalty_card.php';
require_once dirname(__DIR__).'/inc/customer_drink_loyalty.php';
require_once dirname(__DIR__).'/inc/evotor_customer_loyalty.php';
require_once dirname(__DIR__).'/inc/evotor_order_notifications.php';

customer_api_headers();
header('Cache-Control: no-store');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){http_response_code(204);exit;}
$legacyTransport=$method==='GET'
    && trim((string)($_SERVER['HTTP_X_KAPOUCH_EVOTOR_LEGACY']??''))==='1';
if($method!=='POST'&&!$legacyTransport)customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
if($legacyTransport&&trim((string)($_SERVER['QUERY_STRING']??''))!==''){
    customer_api_reply(400,['ok'=>false,'error'=>'Legacy-запрос не должен содержать параметры URL.']);
}

try{
    if($legacyTransport){
        $data=[
            'code'=>trim((string)($_SERVER['HTTP_X_KAPOUCH_LOYALTY_CODE']??'')),
            'bootstrap_order_token'=>trim((string)($_SERVER['HTTP_X_KAPOUCH_BOOTSTRAP_ORDER_TOKEN']??'')),
        ];
    }else{
        $data=customer_api_json();
    }
    $terminalToken=trim((string)($_SERVER['HTTP_X_KAPOUCH_TERMINAL_TOKEN']??($data['terminal_token']??'')));
    $connectionId=evotor_loyalty_terminal_connection_id($terminalToken);
    $issuedTerminalToken='';
    if($connectionId===null){
        $bootstrapToken=trim((string)($data['bootstrap_order_token']??''));
        $claims=evotor_order_action_claims($bootstrapToken);
        if($claims!==null){
            $connectionId=(int)$claims['connection_id'];
            $issuedTerminalToken=evotor_loyalty_terminal_token($connectionId);
        }
    }
    if($connectionId===null)customer_api_reply(401,['ok'=>false,'error'=>'Терминал Kapouch не авторизован. После установки приложения получите хотя бы один новый PWA-заказ, затем повторите сканирование.']);

    $limit=kapouch_rate_limit_hit('evotor_customer_lookup','connection:'.$connectionId,600,3600);
    if(!$limit['allowed']){header('Retry-After: '.(int)$limit['retry_after']);customer_api_reply(429,['ok'=>false,'error'=>'Слишком много сканирований. Повторите позже.']);}

    $code=trim((string)($data['code']??''));
    if(strlen($code)>200)customer_api_reply(422,['ok'=>false,'error'=>'Некорректный QR-код Kapouch.']);
    $customerId=customer_loyalty_card_customer_id($code);
    if($customerId===null)customer_api_reply(422,['ok'=>false,'error'=>'Это не персональная карта Kapouch или её подпись недействительна.']);

    $connection=db()->prepare('SELECT push_device_uuid FROM evotor_connections WHERE id=? LIMIT 1');$connection->execute([$connectionId]);$deviceUuid=(string)($connection->fetchColumn()?:'');
    customer_loyalty_refresh_customer($customerId);
    $scan=evotor_customer_loyalty_register_scan($connectionId,$customerId,$deviceUuid);
    $customer=$scan['customer'];$customer['loyalty_balance']=customer_loyalty_balance($customerId);
    $drink=customer_drink_loyalty_summary($customerId);
    $response=[
        'ok'=>true,
        'customer'=>$customer,
        'loyalty'=>['balance'=>$customer['loyalty_balance'],'earn_percent'=>customer_loyalty_rate()],
        'drink_loyalty'=>$drink,
        'link'=>['active'=>true,'scan_id'=>$scan['scan_id'],'expires_at'=>$scan['expires_at'],'message'=>'Клиент будет привязан к следующей продаже на этом Эвоторе.'],
        'transport'=>$legacyTransport?'legacy-get':'post',
    ];
    if($issuedTerminalToken!=='')$response['terminal_token']=$issuedTerminalToken;
    customer_api_reply(200,$response);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);
}catch(Throwable $e){error_log('[Kapouch Evotor loyalty lookup] '.$e->getMessage());customer_api_reply(500,['ok'=>false,'error'=>'Не удалось определить клиента.']);}
