<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_modifiers.php';

customer_api_headers();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if($method!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);

try{
    $customer=customer_auth_require();
    $data=customer_api_json();
    $orderNumber=trim((string)($data['order_number']??''));
    if($orderNumber==='')customer_api_reply(422,['ok'=>false,'error'=>'Не указан номер заказа.']);

    $stmt=db()->prepare('SELECT o.id,o.order_number FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.customer_id=? AND o.order_number=? LIMIT 1');
    $stmt->execute([(int)$customer['id'],$orderNumber]);
    $order=$stmt->fetch();
    if(!$order)customer_api_reply(404,['ok'=>false,'error'=>'Заказ не найден в вашем профиле.']);

    $stmt=db()->prepare('SELECT local_product_id,quantity,sort_order,id FROM online_order_items WHERE order_id=? AND local_product_id IS NOT NULL ORDER BY sort_order,id');
    $stmt->execute([(int)$order['id']]);
    $items=$stmt->fetchAll();
    if(!$items)customer_api_reply(422,['ok'=>false,'error'=>'У этого заказа нет позиций, которые можно повторить.']);

    $lines=[];$warnings=[];$current=null;
    foreach($items as $item){
        $productId=(int)$item['local_product_id'];
        if($productId<=0)continue;
        $matchedOption=null;
        if($current!==null){
            foreach(customer_modifier_groups_for_product((int)$current['product_id']) as $group){
                foreach(($group['options']??[]) as $option){
                    if((int)($option['product_id']??0)===$productId){$matchedOption=(int)$option['id'];break 2;}
                }
            }
        }
        if($matchedOption!==null){$current['modifiers'][]=$matchedOption;continue;}
        if($current!==null)$lines[]=$current;
        $current=['product_id'=>$productId,'quantity'=>max(1,min(20,(int)round((float)$item['quantity']))),'modifiers'=>[]];
    }
    if($current!==null)$lines[]=$current;

    $available=[];
    foreach($lines as $line){
        $stmt=db()->prepare('SELECT id,name FROM products WHERE id=? AND active=1 AND sale_price>0 LIMIT 1');
        $stmt->execute([(int)$line['product_id']]);$product=$stmt->fetch();
        if(!$product){$warnings[]='Одна из позиций прошлого заказа больше недоступна.';continue;}
        try{
            $validated=customer_modifier_validate_selection((int)$line['product_id'],$line['modifiers']);
            $available[]=['product_id'=>(int)$line['product_id'],'quantity'=>(int)$line['quantity'],'modifiers'=>array_values(array_map(static fn($m)=>(int)$m['option_id'],$validated))];
        }catch(RuntimeException $e){$warnings[]='«'.(string)$product['name'].'»: '.$e->getMessage();}
    }
    if(!$available)customer_api_reply(422,['ok'=>false,'error'=>'Позиции этого заказа сейчас недоступны для повторения.']);
    customer_api_reply(200,['ok'=>true,'order_number'=>(string)$order['order_number'],'lines'=>$available,'warnings'=>array_values(array_unique($warnings))]);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);}
catch(RuntimeException $e){if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Для повторения заказа войдите в профиль Kapouch.']);customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось подготовить повтор заказа.']);}
