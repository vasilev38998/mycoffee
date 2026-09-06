<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_drink_loyalty.php';

function sixth_ok(bool $condition,string $message): void{if(!$condition)throw new RuntimeException('ASSERT FAILED: '.$message);echo "OK: {$message}\n";}
function sixth_throws(callable $fn,string $message): void{try{$fn();}catch(Throwable $e){echo "OK: {$message}\n";return;}throw new RuntimeException('ASSERT FAILED: expected exception: '.$message);}

$pdo=db();
set_app_setting('customer_sixth_drink_enabled','1');
set_app_setting('customer_sixth_drink_paid_count','5');
set_app_setting('customer_sixth_drink_products_mode','selected');
set_app_setting('customer_sixth_drink_started_at','2020-01-01 00:00:00');

$pdo->prepare("INSERT INTO products(name,category,sale_price,active) VALUES('Капучино 0,2 runtime','Кофе',200,1)")->execute();$capId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products(name,category,sale_price,active) VALUES('Латте runtime','Кофе',260,1)")->execute();$latteId=(int)$pdo->lastInsertId();
set_app_setting('customer_sixth_drink_product_ids',$capId.','.$latteId);set_app_setting('customer_sixth_drink_reference_product_id',(string)$capId);
$phone='+7999'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT);$pdo->prepare('INSERT INTO customer_accounts(phone,name) VALUES(?,?)')->execute([$phone,'Sixth Drink Runtime']);$customerId=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO sales(sold_at,total_amount,payment_method,note) VALUES(NOW(),1300,'card','sixth drink runtime')")->execute();$saleId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO sale_items(sale_id,product_id,quantity,unit_price,unit_cost) VALUES(?,?,?,?,0)')->execute([$saleId,$latteId,5,260]);
$added=customer_drink_loyalty_credit_sale($pdo,$customerId,$saleId,'runtime-doc-1');
sixth_ok($added===5,'five paid drinks create five stamps');
sixth_ok(customer_drink_loyalty_credit_sale($pdo,$customerId,$saleId,'runtime-doc-1')===0,'receipt stamp credit is idempotent');
$summary=customer_drink_loyalty_summary($customerId);
sixth_ok($summary['paid_stamps']===5&&$summary['available_rewards']===1,'five stamps unlock one free drink');
sixth_ok(abs((float)$summary['gift_cap']-200.0)<0.001,'gift cap equals reference cappuccino price');

$redeem=customer_drink_loyalty_redeem($customerId,'runtime_sale','gift-1',$latteId,260);
sixth_ok(abs((float)$redeem['discount']-200.0)<0.001&&abs((float)$redeem['customer_due']-60.0)<0.001,'more expensive drink charges only price difference');
$summary=customer_drink_loyalty_summary($customerId);sixth_ok($summary['available_rewards']===0,'reward is consumed exactly once');
sixth_throws(fn()=>customer_drink_loyalty_redeem($customerId,'runtime_sale','gift-2',$latteId,260),'cannot redeem without an available gift');

$pdo->prepare("INSERT INTO sales(sold_at,total_amount,payment_method,note) VALUES(NOW(),200,'cash','sixth drink runtime second')")->execute();$sale2=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO sale_items(sale_id,product_id,quantity,unit_price,unit_cost) VALUES(?,?,?,?,0)')->execute([$sale2,$capId,1,200]);customer_drink_loyalty_credit_sale($pdo,$customerId,$sale2,'runtime-doc-2');
$summary=customer_drink_loyalty_summary($customerId);sixth_ok($summary['progress']===1&&$summary['next_in']===4,'new cycle starts after redeemed gift');

$reversed=customer_drink_loyalty_reverse_source($customerId,'evotor_sale','runtime-doc-2','runtime refund');sixth_ok($reversed===1,'refund reverses drink stamp');
sixth_ok(customer_drink_loyalty_reverse_source($customerId,'evotor_sale','runtime-doc-2','runtime refund')===0,'drink stamp reversal is idempotent');
$summary=customer_drink_loyalty_summary($customerId);sixth_ok($summary['progress']===0&&$summary['available_rewards']===0,'refund restores loyalty state');

$phone2='+7988'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT);$pdo->prepare('INSERT INTO customer_accounts(phone,name) VALUES(?,?)')->execute([$phone2,'Sixth Online Runtime']);$customer2=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO sales(sold_at,total_amount,payment_method,note) VALUES(NOW(),1000,'card','sixth online seed')")->execute();$seedSale=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO sale_items(sale_id,product_id,quantity,unit_price,unit_cost) VALUES(?,?,?,?,0)')->execute([$seedSale,$capId,5,200]);customer_drink_loyalty_credit_sale($pdo,$customer2,$seedSale,'runtime-online-seed');
$quote=customer_drink_loyalty_quote_cart($customer2,[['product_id'=>$latteId,'quantity'=>1,'modifiers'=>[]]]);
sixth_ok(abs((float)$quote['subtotal']-260.0)<0.001&&abs((float)$quote['discount']-200.0)<0.001&&abs((float)$quote['total']-60.0)<0.001,'PWA quote shows sixth-drink discount and only price difference');

$external='sixth-online-'.bin2hex(random_bytes(5));$pdo->prepare("INSERT INTO online_orders(external_id,order_number,source,status,customer_name,customer_phone,fulfillment_type,payment_status,total_amount,created_at) VALUES(?,?,'customer-web','new','Sixth Online Runtime',?,'pickup','unpaid',260,NOW())")->execute([$external,'SIXTH-ONLINE',$phone2]);$orderId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO online_order_items(order_id,external_item_id,local_product_id,product_name,quantity,unit_price,line_total,sort_order) VALUES(?,?,?,?,1,260,260,0)')->execute([$orderId,(string)$latteId,$latteId,'Латте runtime']);$itemId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO customer_order_access(order_id,customer_id,tracking_token) VALUES(?,?,?)')->execute([$orderId,$customer2,bin2hex(random_bytes(32))]);
$applied=customer_drink_loyalty_apply_online_order_reward($orderId,$customer2);sixth_ok(!empty($applied['applied'])&&abs((float)$applied['discount']-200.0)<0.001,'online checkout automatically consumes available gift');
$total=(float)$pdo->query('SELECT total_amount FROM online_orders WHERE id='.(int)$orderId)->fetchColumn();$unit=(float)$pdo->query('SELECT unit_price FROM online_order_items WHERE id='.(int)$itemId)->fetchColumn();sixth_ok(abs($total-60.0)<0.001&&abs($unit-60.0)<0.001,'online order and item prices are discounted before payment');
$again=customer_drink_loyalty_apply_online_order_reward($orderId,$customer2);sixth_ok(!empty($again['applied'])&&abs((float)$again['discount']-200.0)<0.001,'online reward application is idempotent');
$pdo->prepare("UPDATE online_orders SET status='completed',completed_at=NOW() WHERE id=?")->execute([$orderId]);$credited=customer_drink_loyalty_credit_online_order($orderId,$customer2);sixth_ok($credited===0,'free sixth drink does not create a paid stamp');
$summary=customer_drink_loyalty_summary($customer2);sixth_ok($summary['available_rewards']===0,'online gift remains consumed after completed gift order');
$restored=customer_drink_loyalty_restore_online_order_reward($orderId,'runtime cancellation');sixth_ok($restored===1,'cancelled/refunded online order restores consumed gift');sixth_ok(customer_drink_loyalty_restore_online_order_reward($orderId,'runtime cancellation')===0,'online gift restoration is idempotent');
$summary=customer_drink_loyalty_summary($customer2);sixth_ok($summary['available_rewards']===1,'restored online gift becomes available again');

echo "SIXTH DRINK RUNTIME PASSED\n";
