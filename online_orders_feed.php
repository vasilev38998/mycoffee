<?php
declare(strict_types=1);

require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/online_orders.php';

$filter=(string)($_GET['filter']??'active');
if(!in_array($filter,['active','done','all'],true))$filter='active';

if(session_status()===PHP_SESSION_ACTIVE)session_write_close();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try{
    echo json_encode([
        'ok'=>true,
        'orders'=>online_orders_fetch($filter),
        'server_time'=>date('c'),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Не удалось получить онлайн-заказы.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
