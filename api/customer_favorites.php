<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';

customer_api_headers();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(!in_array($method,['GET','POST'],true))customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $customer=customer_auth_require();$customerId=(int)$customer['id'];
    if($method==='POST'){
        $data=customer_api_json();$items=$data['favorites']??[];
        if(!is_array($items))customer_api_reply(422,['ok'=>false,'error'=>'Некорректный список избранного.']);
        $clean=[];foreach($items as $key){$key=trim((string)$key);if(preg_match('/^[pg]\d{1,12}$/',$key))$clean[$key]=true;if(count($clean)>=100)break;}
        $pdo=db();$pdo->beginTransaction();try{$pdo->prepare('DELETE FROM customer_favorites WHERE customer_id=?')->execute([$customerId]);$ins=$pdo->prepare('INSERT INTO customer_favorites(customer_id,product_key) VALUES(?,?)');foreach(array_keys($clean) as $key)$ins->execute([$customerId,$key]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    $stmt=db()->prepare('SELECT product_key FROM customer_favorites WHERE customer_id=? ORDER BY created_at,id');$stmt->execute([$customerId]);
    customer_api_reply(200,['ok'=>true,'favorites'=>array_values(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)))]);
}catch(JsonException $e){customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);}
catch(RuntimeException $e){if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Требуется вход.']);customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось синхронизировать избранное.']);}
