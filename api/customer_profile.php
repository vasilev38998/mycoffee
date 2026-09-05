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
        $email=mb_strtolower(trim((string)($data['email']??'')));
        if($email===''||mb_strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))customer_api_reply(422,['ok'=>false,'error'=>'Укажите корректную электронную почту.']);
        db()->prepare('UPDATE customer_accounts SET email=? WHERE id=?')->execute([$email,(int)$customer['id']]);
    }
    customer_loyalty_refresh_customer((int)$customer['id']);
    $profile=customer_auth_profile($customer);
    $stmt=db()->prepare('SELECT email FROM customer_accounts WHERE id=? LIMIT 1');$stmt->execute([(int)$customer['id']]);
    $profile['customer']['email']=trim((string)($stmt->fetchColumn()?:''));
    customer_api_reply(200,['ok'=>true,'profile'=>$profile]);
}catch(JsonException $e){
    customer_api_reply(400,['ok'=>false,'error'=>'Некорректный JSON.']);
}catch(RuntimeException $e){
    if($e->getMessage()==='AUTH_REQUIRED')customer_api_reply(401,['ok'=>false,'error'=>'Требуется вход.']);
    customer_api_reply(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){customer_api_reply(500,['ok'=>false,'error'=>'Не удалось загрузить профиль.']);}
