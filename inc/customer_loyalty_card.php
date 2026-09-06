<?php
declare(strict_types=1);

require_once __DIR__.'/customer_auth.php';

function customer_loyalty_card_version(): int{return 1;}

function customer_loyalty_card_signature(int $customerId): string
{
    if($customerId<=0)return '';
    $payload='kapouch-loyalty-card|'.customer_loyalty_card_version().'|'.$customerId;
    return substr(hash_hmac('sha256',$payload,customer_auth_secret_key()),0,32);
}

function customer_loyalty_card_code(int $customerId): string
{
    if($customerId<=0)throw new RuntimeException('Некорректный клиент для карты лояльности.');
    return 'KAPOUCH:LOYALTY:'.customer_loyalty_card_version().':'.$customerId.':'.customer_loyalty_card_signature($customerId);
}

function customer_loyalty_card_customer_id(string $code): ?int
{
    $code=trim($code);
    if(!preg_match('/^KAPOUCH:LOYALTY:(\d+):(\d{1,20}):([a-f0-9]{32})$/D',$code,$m))return null;
    $version=(int)$m[1];$customerId=(int)$m[2];$signature=(string)$m[3];
    if($version!==customer_loyalty_card_version()||$customerId<=0)return null;
    $expected=customer_loyalty_card_signature($customerId);
    return $expected!==''&&hash_equals($expected,$signature)?$customerId:null;
}

function customer_loyalty_card_payload(int $customerId): array
{
    $stmt=db()->prepare('SELECT id,name,loyalty_balance FROM customer_accounts WHERE id=? LIMIT 1');
    $stmt->execute([$customerId]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Клиент не найден.');
    return [
        'code'=>customer_loyalty_card_code($customerId),
        'version'=>customer_loyalty_card_version(),
        'customer'=>[
            'id'=>(int)$row['id'],
            'name'=>trim((string)($row['name']??'')),
            'loyalty_balance'=>round((float)($row['loyalty_balance']??0),2),
        ],
    ];
}
