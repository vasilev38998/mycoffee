<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';
require_once dirname(__DIR__).'/inc/customer_auth.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';

customer_api_headers();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(!in_array($method,['GET','POST'],true))customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);
try{
    $customer=customer_auth_require();
    if($method==='POST'){
        $data=customer_api_json();
        $name=trim((string)($data['name']??$customer['name']??''));
        $email=mb_strtolower(trim((string)($data['email']??'')));
        if(mb_strlen($name)>160)customer_api_reply(422,['ok'=>false,'error'=>'Имя слишком длинное.']);
        if($email!==''&&(mb_strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)))customer_api_reply(422,['ok'=>false,'error'=>'Укажите корректную электронную почту.']);
        db()->prepare('UPDATE customer_accounts SET name=?,email=? WHERE id=?')->execute([$name!==''?$name:null,$email!==''?$email:null,(int)$customer['id']]);
        $customer['name']=$name;
    }
    $customerId=(int)$customer['id'];
    customer_loyalty_refresh_customer($customerId);
    $profile=customer_auth_profile($customer);
    $stmt=db()->prepare('SELECT email,name FROM customer_accounts WHERE id=? LIMIT 1');$stmt->execute([$customerId]);$account=$stmt->fetch()?:[];
    $profile['customer']['email']=trim((string)($account['email']??''));
    $profile['customer']['name']=trim((string)($account['name']??''));

    $stmt=db()->prepare("SELECT COUNT(*) completed_orders,COALESCE(SUM(o.total_amount),0) completed_spend FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id WHERE a.customer_id=? AND o.status='completed'");
    $stmt->execute([$customerId]);$orderStats=$stmt->fetch()?:[];
    $stmt=db()->prepare("SELECT oi.product_name,COALESCE(SUM(oi.quantity),0) qty,COUNT(DISTINCT o.id) order_count FROM customer_order_access a JOIN online_orders o ON o.id=a.order_id JOIN online_order_items oi ON oi.order_id=o.id WHERE a.customer_id=? AND o.status='completed' AND oi.variant_name IS NULL GROUP BY oi.product_name ORDER BY qty DESC,order_count DESC,oi.product_name LIMIT 1");
    $stmt->execute([$customerId]);$favorite=$stmt->fetch()?:[];
    $stmt=db()->prepare('SELECT COALESCE(SUM(amount),0) FROM customer_loyalty_ledger WHERE customer_id=? AND amount>0');$stmt->execute([$customerId]);$earned=(float)$stmt->fetchColumn();
    $profile['stats']=['completed_orders'=>(int)($orderStats['completed_orders']??0),'completed_spend'=>(float)($orderStats['completed_spend']??0),'favorite_product'=>trim((string)($favorite['product_name']??'')),'favorite_quantity'=>(float)($favorite['qty']??0),'loyalty_earned'=>$earned];
    customer_api_reply(200,['ok'=>true,'profile'=>$profile]);
}catch(JsonException $e){
    customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);
}catch(RuntimeException $e){
    if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Требуется вход.']);
    customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось загрузить профиль.']);}
