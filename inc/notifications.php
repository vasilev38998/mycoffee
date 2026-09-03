<?php
declare(strict_types=1);

function notification_crypto_key(): string
{
    global $config;
    return hash('sha256',(string)($config['security']['encryption_key']??'kapouch'),true);
}

function encrypt_notification_secret(string $secret): array
{
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($secret,'aes-256-gcm',notification_crypto_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)throw new RuntimeException('Не удалось зашифровать секрет уведомлений.');
    return [base64_encode($cipher),base64_encode($iv),base64_encode($tag)];
}

function decrypt_notification_secret(array $row): string
{
    $secret=openssl_decrypt(base64_decode((string)$row['secret_ciphertext'],true),'aes-256-gcm',notification_crypto_key(),OPENSSL_RAW_DATA,base64_decode((string)$row['secret_iv'],true),base64_decode((string)$row['secret_tag'],true));
    if($secret===false)throw new RuntimeException('Не удалось расшифровать Telegram token.');
    return $secret;
}

function telegram_notification_settings(): ?array
{
    try{$row=db()->query("SELECT * FROM notification_settings WHERE channel='telegram' LIMIT 1")->fetch();return $row?:null;}catch(Throwable $e){return null;}
}

function send_telegram_message(string $text): void
{
    $row=telegram_notification_settings();
    if(!$row||!(int)$row['enabled']||empty($row['destination'])||empty($row['secret_ciphertext']))throw new RuntimeException('Telegram-уведомления не настроены.');
    $token=decrypt_notification_secret($row);
    $url='https://api.telegram.org/bot'.rawurlencode($token).'/sendMessage';
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>30,CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>$row['destination'],'text'=>$text,'disable_web_page_preview'=>1])]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$error!=='')throw new RuntimeException('Ошибка Telegram: '.$error);
    if($status<200||$status>=300)throw new RuntimeException('Telegram API HTTP '.$status.': '.$body);
}
