<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/evotor_order_notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function evotor_order_action_response(int $status,array $body): never
{
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
    evotor_order_action_response(405,['ok'=>false,'error'=>'Метод не поддерживается.']);
}

$limit=kapouch_rate_limit_hit('evotor_order_action',kapouch_client_ip(),90,60);
if(empty($limit['allowed'])){
    header('Retry-After: '.(int)$limit['retry_after']);
    evotor_order_action_response(429,['ok'=>false,'error'=>'Слишком много запросов. Повторите позже.']);
}

$token=trim((string)($_SERVER['HTTP_X_KAPOUCH_ORDER_TOKEN']??''));
if($token===''){
    $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    if(preg_match('/^Bearer\s+(.+)$/i',$auth,$m))$token=trim($m[1]);
}
$claims=evotor_order_action_claims($token);
if(!$claims)evotor_order_action_response(401,['ok'=>false,'error'=>'Недействительный или просроченный ключ действия.']);

$connection=evotor_order_push_connection((int)$claims['connection_id']);
if(!$connection||empty($connection['push_enabled'])){
    evotor_order_action_response(401,['ok'=>false,'error'=>'Управление заказами с этого Эвотора отключено.']);
}

try{
    $raw=file_get_contents('php://input');
    if(!is_string($raw)||strlen($raw)>16384)throw new RuntimeException('Некорректное тело запроса.');
    $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('JSON должен быть объектом.');
    $action=trim((string)($data['action']??''));
    $orderId=(int)($data['order_id']??$claims['order_id']);
    if($orderId!==(int)$claims['order_id'])evotor_order_action_response(403,['ok'=>false,'error'=>'Ключ выпущен для другого заказа.']);
    if(!in_array($action,['accept','ready'],true))throw new RuntimeException('Допустимые действия: accept или ready.');

    $order=evotor_order_action_apply($orderId,$action);
    evotor_order_action_response(200,['ok'=>true,'action'=>$action,'order'=>$order]);
}catch(JsonException $e){
    evotor_order_action_response(400,['ok'=>false,'error'=>'Некорректный JSON.']);
}catch(RuntimeException $e){
    evotor_order_action_response(409,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('[Kapouch Evotor order action] '.$e->getMessage());
    evotor_order_action_response(500,['ok'=>false,'error'=>'Не удалось изменить заказ.']);
}
