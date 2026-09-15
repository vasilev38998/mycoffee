<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/online_orders.php';
require_once dirname(__DIR__).'/inc/customer_loyalty.php';

function spend_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);}
function spend_close(float $a,float $b): bool{return abs($a-$b)<0.011;}

$pdo=db();
$suffix=bin2hex(random_bytes(4));
$phone='+7999'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT);
$pdo->prepare('INSERT INTO customer_accounts(phone,name,loyalty_balance) VALUES(?,?,120.00)')->execute([$phone,'Bonus Runtime']);
$customerId=(int)$pdo->lastInsertId();

$order=online_orders_upsert_from_api([
    'external_id'=>'bonus-runtime-'.$suffix,
    'order_number'=>'BR-'.$suffix,
    'source'=>'customer-web',
    'customer'=>['name'=>'Bonus Runtime','phone'=>$phone],
    'fulfillment'=>['type'=>'pickup','label'=>'Самовывоз'],
    'payment_status'=>'unpaid',
    'total_amount'=>300,
    'items'=>[['external_id'=>'runtime-coffee','name'=>'Капучино runtime','quantity'=>3,'unit_price'=>100,'line_total'=>300]],
]);
$orderId=(int)$order['id'];
$pdo->prepare('INSERT INTO customer_order_access(order_id,customer_id,tracking_token) VALUES(?,?,?)')->execute([$orderId,$customerId,bin2hex(random_bytes(32))]);

$applied=customer_loyalty_apply_order_spend($orderId,$customerId,100);
spend_assert(spend_close((float)$applied['applied'],99.99),'quantity rounding must never spend more than requested and should apply 99.99');
spend_assert(spend_close(customer_loyalty_balance($customerId),20.01),'customer balance must decrease by actual spend');
$total=(float)$pdo->query('SELECT total_amount FROM online_orders WHERE id='.(int)$orderId)->fetchColumn();
$lines=(float)$pdo->query('SELECT COALESCE(SUM(line_total),0) FROM online_order_items WHERE order_id='.(int)$orderId)->fetchColumn();
spend_assert(spend_close($total,200.01),'order total must be discounted by actual spend');
spend_assert(spend_close($lines,$total),'discounted item lines must stay equal to order total for fiscal receipt');

$again=customer_loyalty_apply_order_spend($orderId,$customerId,100);
spend_assert(spend_close((float)$again['applied'],99.99),'repeated checkout call must be idempotent');
spend_assert(spend_close(customer_loyalty_balance($customerId),20.01),'idempotent call must not spend twice');

$pdo->prepare("UPDATE online_orders SET status='cancelled',cancelled_at=NOW() WHERE id=?")->execute([$orderId]);
$restored=customer_loyalty_restore_order_spend($orderId,'runtime cancel');
spend_assert(spend_close($restored,99.99),'cancel must restore spent points');
spend_assert(spend_close(customer_loyalty_balance($customerId),120.00),'restored balance must equal starting balance');
spend_assert(spend_close(customer_loyalty_restore_order_spend($orderId,'runtime cancel again'),0.0),'restore must be idempotent');

$external=online_orders_upsert_from_api([
    'external_id'=>'bonus-runtime-external-'.$suffix,
    'order_number'=>'BRE-'.$suffix,
    'source'=>'evotor',
    'customer'=>['name'=>'Bonus Runtime','phone'=>$phone],
    'fulfillment'=>['type'=>'pickup','label'=>'Касса'],
    'payment_status'=>'unpaid',
    'total_amount'=>100,
    'items'=>[['external_id'=>'runtime-coffee-2','name'=>'Капучино касса','quantity'=>1,'unit_price'=>100,'line_total'=>100]],
]);
$externalId=(int)$external['id'];
$pdo->prepare('INSERT INTO customer_order_access(order_id,customer_id,tracking_token) VALUES(?,?,?)')->execute([$externalId,$customerId,bin2hex(random_bytes(32))]);
$blocked=false;
try{customer_loyalty_apply_order_spend($externalId,$customerId,50);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'только в приложении');}
spend_assert($blocked,'non-PWA order must not be allowed to spend ordinary points');
spend_assert(spend_close(customer_loyalty_balance($customerId),120.00),'cash-register attempt must not change balance');

echo "LOYALTY SPEND RUNTIME PASSED\n";
