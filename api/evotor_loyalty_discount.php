<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_loyalty_card.php';
require_once dirname(__DIR__).'/inc/customer_drink_loyalty.php';
require_once dirname(__DIR__).'/inc/customer_loyalty_runtime.php';
require_once dirname(__DIR__).'/inc/evotor_customer_loyalty.php';

customer_api_headers();
header('Cache-Control: no-store');
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);

function evotor_discount_local_product(int $connectionId,array $position): int
{
    $remote=trim((string)($position['product_uuid']??$position['product_id']??''));
    if($remote!==''){
        $stmt=db()->prepare('SELECT local_product_id FROM evotor_products WHERE connection_id=? AND evotor_product_id=? LIMIT 1');
        $stmt->execute([$connectionId,$remote]);$id=(int)($stmt->fetchColumn()?:0);if($id>0)return $id;
    }
    $name=trim((string)($position['name']??''));$price=round(max(0,(float)($position['price']??0)),2);
    if($name===''||$price<=0)return 0;
    $stmt=db()->prepare('SELECT local_product_id FROM evotor_products WHERE connection_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND ABS(price-?)<0.01 AND local_product_id IS NOT NULL ORDER BY id LIMIT 1');
    $stmt->execute([$connectionId,$name,$price]);return (int)($stmt->fetchColumn()?:0);
}

function evotor_discount_receipt_ref(string $receiptUuid): string
{
    return $receiptUuid===''?'':substr(hash('sha256',$receiptUuid),0,12);
}

$startedAt=microtime(true);
try{
    $data=customer_api_json();
    $terminalToken=trim((string)($_SERVER['HTTP_X_KAPOUCH_TERMINAL_TOKEN']??($data['terminal_token']??'')));
    $connectionId=evotor_loyalty_terminal_connection_id($terminalToken);
    if($connectionId===null)customer_api_reply(401,['ok'=>false,'error'=>'Терминал Kapouch не авторизован.']);
    $limit=kapouch_rate_limit_hit('evotor_loyalty_discount','connection:'.$connectionId,900,3600);
    if(!$limit['allowed'])customer_api_reply(429,['ok'=>false,'error'=>'Слишком много запросов скидки.']);

    $action=trim((string)($data['action']??'quote'));
    $receiptUuid=mb_substr(trim((string)($data['receipt_uuid']??'')),0,200);
    if($receiptUuid==='')throw new RuntimeException('Не удалось определить открытый чек Эвотора.');
    $receiptRef=evotor_discount_receipt_ref($receiptUuid);
    kapouch_runtime_log('evotor','discount_request',['connection_id'=>$connectionId,'action'=>$action,'receipt_ref'=>$receiptRef]);

    if($action==='confirm'){
        $stmt=db()->prepare("UPDATE customer_evotor_reward_pending SET status='applied',applied_at=NOW() WHERE connection_id=? AND receipt_uuid=? AND status='quoted' AND expires_at>NOW()");
        $stmt->execute([$connectionId,$receiptUuid]);
        $read=db()->prepare('SELECT reward_value,status FROM customer_evotor_reward_pending WHERE connection_id=? AND receipt_uuid=? LIMIT 1');$read->execute([$connectionId,$receiptUuid]);$row=$read->fetch();
        $confirmed=$row&&in_array((string)$row['status'],['applied','finalized'],true);$discount=(float)($row['reward_value']??0);
        kapouch_runtime_log('evotor','discount_confirm',['connection_id'=>$connectionId,'receipt_ref'=>$receiptRef,'confirmed'=>$confirmed,'discount'=>$discount,'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000)]);
        customer_api_reply(200,['ok'=>true,'confirmed'=>$confirmed,'discount'=>$discount]);
    }

    $code=trim((string)($data['code']??''));if(strlen($code)>200)throw new RuntimeException('Некорректная карта Kapouch.');
    $customerId=customer_loyalty_card_customer_id($code);if($customerId===null)throw new RuntimeException('Карта Kapouch недействительна.');
    // Discount calculation is called repeatedly while Evotor edits/recalculates a receipt.
    // Reconcile history at most once per short interval and under a local lock instead of
    // re-reading up to 100 historical orders/sales on every quote request.
    $refresh=customer_loyalty_refresh_customer_if_due($customerId,20,15);
    $summary=customer_drink_loyalty_summary($customerId);
    $positions=$data['positions']??[];if(!is_array($positions))$positions=[];
    $eligibleMap=customer_drink_loyalty_product_map();$eligibleUnits=0;$candidate=null;
    foreach($positions as $position){
        if(!is_array($position))continue;$qty=max(0,(float)($position['quantity']??0));if($qty<=0)continue;
        $productId=evotor_discount_local_product($connectionId,$position);if($productId<=0||empty($eligibleMap[$productId]))continue;
        $wholeUnits=(int)floor($qty+0.000001);if($wholeUnits<=0)continue;$eligibleUnits+=$wholeUnits;
        $price=round(max(0,(float)($position['price']??0)),2);$discount=round(min($price,(float)($summary['gift_cap']??0)),2);
        if($discount<=0)continue;
        if($candidate===null||$discount>(float)$candidate['discount'])$candidate=['product_id'=>$productId,'product_name'=>mb_substr((string)($position['name']??'Напиток'),0,200),'discount'=>$discount];
    }
    $hasReward=(int)($summary['available_rewards']??0)>0;
    $needed=max(1,(int)($summary['next_in']??$summary['required_paid']??1));
    $sameOrder=!$hasReward&&$eligibleUnits>$needed;
    $canGift=!empty($summary['enabled'])&&(float)($summary['gift_cap']??0)>0&&$candidate!==null&&($hasReward||$sameOrder);
    if(!$canGift){
        db()->prepare("UPDATE customer_evotor_reward_pending SET status='cancelled' WHERE connection_id=? AND receipt_uuid=? AND status='quoted'")->execute([$connectionId,$receiptUuid]);
        kapouch_runtime_log('evotor','discount_quote_none',['connection_id'=>$connectionId,'receipt_ref'=>$receiptRef,'customer_id'=>$customerId,'eligible_units'=>$eligibleUnits,'next_in'=>$needed,'available_rewards'=>(int)($summary['available_rewards']??0),'gift_cap'=>(float)($summary['gift_cap']??0),'refresh_skipped'=>!empty($refresh['skipped']),'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000)]);
        customer_api_reply(200,['ok'=>true,'discount'=>0.0,'gift'=>false,'eligible_units'=>$eligibleUnits,'next_in'=>$needed]);
    }

    $existing=db()->prepare('SELECT status,reward_value,product_id,customer_id FROM customer_evotor_reward_pending WHERE connection_id=? AND receipt_uuid=? LIMIT 1');$existing->execute([$connectionId,$receiptUuid]);$pending=$existing->fetch();
    if($pending&&in_array((string)$pending['status'],['applied','finalized'],true)){
        kapouch_runtime_log('evotor','discount_quote_reused',['connection_id'=>$connectionId,'receipt_ref'=>$receiptRef,'customer_id'=>$customerId,'discount'=>(float)$pending['reward_value'],'status'=>(string)$pending['status'],'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000)]);
        customer_api_reply(200,['ok'=>true,'discount'=>(float)$pending['reward_value'],'gift'=>true,'already_applied'=>true,'same_order_unlock'=>$sameOrder]);
    }
    $upsert=db()->prepare("INSERT INTO customer_evotor_reward_pending(connection_id,receipt_uuid,customer_id,product_id,reward_value,status,quoted_at,expires_at) VALUES(?,?,?,?,?,'quoted',NOW(),DATE_ADD(NOW(),INTERVAL 2 HOUR)) ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id),product_id=VALUES(product_id),reward_value=VALUES(reward_value),status='quoted',quoted_at=NOW(),expires_at=VALUES(expires_at),applied_at=NULL,finalized_at=NULL,sale_id=NULL");
    $upsert->execute([$connectionId,$receiptUuid,$customerId,(int)$candidate['product_id'],(float)$candidate['discount']]);
    kapouch_runtime_log('evotor','discount_quote',['connection_id'=>$connectionId,'receipt_ref'=>$receiptRef,'customer_id'=>$customerId,'product_id'=>(int)$candidate['product_id'],'discount'=>(float)$candidate['discount'],'gift_cap'=>(float)($summary['gift_cap']??0),'eligible_units'=>$eligibleUnits,'same_order_unlock'=>$sameOrder,'refresh_skipped'=>!empty($refresh['skipped']),'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000)]);
    customer_api_reply(200,['ok'=>true,'discount'=>(float)$candidate['discount'],'gift'=>true,'product_name'=>$candidate['product_name'],'same_order_unlock'=>$sameOrder,'eligible_units'=>$eligibleUnits,'next_in'=>$needed]);
}catch(JsonException $e){
    kapouch_runtime_log('evotor','discount_error',['class'=>get_class($e),'message'=>$e->getMessage(),'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000)]);
    customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);
}catch(RuntimeException $e){
    kapouch_runtime_log('evotor','discount_error',['class'=>get_class($e),'message'=>$e->getMessage(),'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000)]);
    customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('[Kapouch Evotor loyalty discount] '.$e->getMessage());
    kapouch_runtime_log('evotor','discount_error',['class'=>get_class($e),'message'=>$e->getMessage(),'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000)]);
    customer_api_reply(500,['ok'=>false,'error'=>'Не удалось рассчитать скидку Kapouch.']);
}
