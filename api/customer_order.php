<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $data=customer_api_json();
    $delay=0;
    $comment=(string)($data['comment']??'');
    if(preg_match('/\[\[pickup:(\d{1,3})\]\]/',$comment,$m)){
        $delay=(int)$m[1];
        $data['comment']=trim(preg_replace('/\s*\[\[pickup:\d{1,3}\]\]\s*/',' ',$comment)??$comment);
    }
    if($delay<0||$delay>180)throw new RuntimeException('Некорректное время получения заказа.');
    $allowed=[0,15,30,45,60,90,120];
    if(!in_array($delay,$allowed,true))throw new RuntimeException('Выберите доступное время получения.');
    $customer=customer_auth_current();
    $order=customer_order_create($data,$customer);
    if($delay>0&&!empty($order['order_id'])){
        $promised=date('Y-m-d H:i:s',time()+$delay*60);
        db()->prepare('UPDATE online_orders SET promised_at=? WHERE id=?')->execute([$promised,(int)$order['order_id']]);
        $order['promised_at']=$promised;
        $order['pickup_delay_minutes']=$delay;
    }
    customer_api_reply(201,['ok'=>true,'order'=>$order]);
}catch(JsonException $e){
    customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);
}catch(RuntimeException $e){
    customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    customer_api_reply(500,['ok'=>false,'error'=>'Не удалось оформить заказ. Попробуйте ещё раз.']);
}
