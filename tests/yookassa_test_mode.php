<?php
declare(strict_types=1);

require dirname(__DIR__).'/inc/customer_payments.php';

function assert_method(array $connection,string $expected,string $message): void
{
    $actual=customer_payment_yookassa_checkout_method($connection);
    if($actual!==$expected)throw new RuntimeException($message.': expected '.$expected.', got '.$actual);
    echo "OK: {$message}\n";
}

assert_method(['test_mode'=>1],'bank_card','test YooKassa shop uses bank card');
assert_method(['test_mode'=>'1'],'bank_card','string test flag also uses bank card');
assert_method(['test_mode'=>0],'sbp','production YooKassa shop keeps SBP');
assert_method([],'sbp','missing test flag defaults to SBP');

echo "YOOKASSA TEST MODE PASSED\n";
