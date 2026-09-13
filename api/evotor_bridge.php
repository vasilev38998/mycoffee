<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';
require_once dirname(__DIR__).'/inc/customer_loyalty_card.php';
require_once dirname(__DIR__).'/inc/customer_drink_loyalty.php';
require_once dirname(__DIR__).'/inc/evotor_customer_loyalty.php';
require_once dirname(__DIR__).'/inc/evotor_order_notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Kapouch-Evotor-Bridge: 1');

function evotor_bridge_reply(int $status,array $body): never
{
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function evotor_bridge_bearer(): string
{
    $token=trim((string)($_SERVER['HTTP_X_KAPOUCH_ORDER_TOKEN']??''));
    if($token!=='')return $token;
    $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    if(preg_match('/^Bearer\s+(.+)$/i',$auth,$m))return trim($m[1]);
    return '';
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method!=='GET')evotor_bridge_reply(405,['ok'=>false,'error'=>'Bridge принимает только GET.']);

$type=trim((string)($_GET['type']??''));
if(!in_array($type,['order','loyalty'],true)){
    evotor_bridge_reply(400,['ok'=>false,'error'=>'Неизвестный тип bridge-запроса.']);
}
if(count($_GET)!==1){
    evotor_bridge_reply(400,['ok'=>false,'error'=>'Bridge не принимает данные в URL.']);
}

if($type==='order'){
    $limit=kapouch_rate_limit_hit('evotor_order_bridge',kapouch_client_ip(),90,60);
    if(empty($limit['allowed'])){
        header('Retry-After: '.(int)$limit['retry_after']);
        evotor_bridge_reply(429,['ok'=>false,'error'=>'Слишком много запросов. Повторите позже.']);
    }

    $token=evotor_bridge_bearer();
    $claims=evotor_order_action_claims($token);
    if(!$claims)evotor_bridge_reply(401,['ok'=>false,'error'=>'Недействительный или просроченный ключ действия.']);

    $connection=evotor_order_push_connection((int)$claims['connection_id']);
    if(!$connection||empty($connection['push_enabled'])){
        evotor_bridge_reply(401,['ok'=>false,'error'=>'Управление заказами с этого Эвотора отключено.']);
    }

    $action=trim((string)($_SERVER['HTTP_X_KAPOUCH_ACTION']??''));
    $orderId=(int)($_SERVER['HTTP_X_KAPOUCH_ORDER_ID']??$claims['order_id']);
    if($orderId!==(int)$claims['order_id']){
        evotor_bridge_reply(403,['ok'=>false,'error'=>'Ключ выпущен для другого заказа.']);
    }
    if(!in_array($action,['accept','ready'],true)){
        evotor_bridge_reply(400,['ok'=>false,'error'=>'Допустимые действия: accept или ready.']);
    }

    try{
        $order=evotor_order_action_apply($orderId,$action);
        evotor_bridge_reply(200,[
            'ok'=>true,
            'action'=>$action,
            'order'=>$order,
            'transport'=>'root-bridge',
        ]);
    }catch(RuntimeException $e){
        evotor_bridge_reply(409,['ok'=>false,'error'=>$e->getMessage()]);
    }catch(Throwable $e){
        error_log('[Kapouch Evotor bridge order] '.$e->getMessage());
        evotor_bridge_reply(500,['ok'=>false,'error'=>'Не удалось изменить заказ.']);
    }
}

$terminalToken=trim((string)($_SERVER['HTTP_X_KAPOUCH_TERMINAL_TOKEN']??''));
$bootstrapToken=trim((string)($_SERVER['HTTP_X_KAPOUCH_BOOTSTRAP_ORDER_TOKEN']??''));
$code=trim((string)($_SERVER['HTTP_X_KAPOUCH_LOYALTY_CODE']??''));
if(strlen($code)>200)evotor_bridge_reply(422,['ok'=>false,'error'=>'Некорректный QR-код Kapouch.']);

$connectionId=evotor_loyalty_terminal_connection_id($terminalToken);
$issuedTerminalToken='';
if($connectionId===null&&$bootstrapToken!==''){
    $claims=evotor_order_action_claims($bootstrapToken);
    if($claims!==null){
        $connectionId=(int)$claims['connection_id'];
        $issuedTerminalToken=evotor_loyalty_terminal_token($connectionId);
    }
}
if($connectionId===null){
    evotor_bridge_reply(401,['ok'=>false,'error'=>'Терминал Kapouch не авторизован. После установки приложения получите хотя бы один новый PWA-заказ, затем повторите сканирование.']);
}

$limit=kapouch_rate_limit_hit('evotor_customer_bridge','connection:'.$connectionId,600,3600);
if(empty($limit['allowed'])){
    header('Retry-After: '.(int)$limit['retry_after']);
    evotor_bridge_reply(429,['ok'=>false,'error'=>'Слишком много сканирований. Повторите позже.']);
}

$customerId=customer_loyalty_card_customer_id($code);
if($customerId===null){
    evotor_bridge_reply(422,['ok'=>false,'error'=>'Это не персональная карта Kapouch или её подпись недействительна.']);
}

try{
    $connection=db()->prepare('SELECT push_device_uuid FROM evotor_connections WHERE id=? LIMIT 1');
    $connection->execute([$connectionId]);
    $deviceUuid=(string)($connection->fetchColumn()?:'');
    customer_loyalty_refresh_customer($customerId);
    $scan=evotor_customer_loyalty_register_scan($connectionId,$customerId,$deviceUuid);
    $customer=$scan['customer'];
    $customer['loyalty_balance']=customer_loyalty_balance($customerId);
    $drink=customer_drink_loyalty_summary($customerId);
    $response=[
        'ok'=>true,
        'customer'=>$customer,
        'loyalty'=>['balance'=>$customer['loyalty_balance'],'earn_percent'=>customer_loyalty_rate()],
        'drink_loyalty'=>$drink,
        'link'=>[
            'active'=>true,
            'scan_id'=>$scan['scan_id'],
            'expires_at'=>$scan['expires_at'],
            'message'=>'Клиент будет привязан к следующей продаже на этом Эвоторе.',
        ],
        'transport'=>'root-bridge',
    ];
    if($issuedTerminalToken!=='')$response['terminal_token']=$issuedTerminalToken;
    evotor_bridge_reply(200,$response);
}catch(Throwable $e){
    error_log('[Kapouch Evotor bridge loyalty] '.$e->getMessage());
    evotor_bridge_reply(500,['ok'=>false,'error'=>'Не удалось определить клиента.']);
}
