<?php
declare(strict_types=1);

require_once __DIR__.'/customer_auth.php';
require_once __DIR__.'/customer_loyalty.php';
require_once __DIR__.'/customer_loyalty_card.php';
require_once __DIR__.'/customer_drink_loyalty.php';

function evotor_loyalty_terminal_token(int $connectionId,?int $expiresAt=null): string
{
    if($connectionId<=0)throw new RuntimeException('Некорректное подключение Эвотор.');
    $expiresAt=$expiresAt??time()+180*86400;
    $payload='1|'.$connectionId.'|'.$expiresAt;
    $signature=substr(hash_hmac('sha256','kapouch-evotor-loyalty-terminal|'.$payload,customer_auth_secret_key()),0,40);
    return 'KLT1.'.$connectionId.'.'.$expiresAt.'.'.$signature;
}

function evotor_loyalty_terminal_connection_id(string $token): ?int
{
    $token=trim($token);
    if(!preg_match('/^KLT1\.(\d{1,10})\.(\d{10,12})\.([a-f0-9]{40})$/D',$token,$m))return null;
    $connectionId=(int)$m[1];$expiresAt=(int)$m[2];$signature=(string)$m[3];
    if($connectionId<=0||$expiresAt<time())return null;
    $payload='1|'.$connectionId.'|'.$expiresAt;
    $expected=substr(hash_hmac('sha256','kapouch-evotor-loyalty-terminal|'.$payload,customer_auth_secret_key()),0,40);
    if(!hash_equals($expected,$signature))return null;
    $stmt=db()->prepare('SELECT id FROM evotor_connections WHERE id=? AND enabled=1 LIMIT 1');$stmt->execute([$connectionId]);
    return $stmt->fetchColumn()?$connectionId:null;
}

function evotor_customer_loyalty_register_scan(int $connectionId,int $customerId,?string $deviceUuid=null): array
{
    if($connectionId<=0||$customerId<=0)throw new RuntimeException('Не удалось определить терминал или клиента.');
    $pdo=db();$now=time();$expires=$now+1800;
    $pdo->beginTransaction();
    try{
        $customer=$pdo->prepare('SELECT id,name,loyalty_balance FROM customer_accounts WHERE id=? FOR UPDATE');$customer->execute([$customerId]);$row=$customer->fetch();
        if(!$row)throw new RuntimeException('Клиент не найден.');
        // A terminal can have only one customer waiting for the next receipt. Without
        // this supersession, customer A could remain pending after customer B scanned
        // and be attached to a later unrelated sale after B's scan was consumed.
        $pdo->prepare("UPDATE evotor_customer_scans SET status=CASE WHEN expires_at_unix<? THEN 'expired' ELSE 'cancelled' END WHERE connection_id=? AND status='pending'")->execute([$now,$connectionId]);
        $stmt=$pdo->prepare("INSERT INTO evotor_customer_scans(connection_id,customer_id,card_version,device_uuid,status,scanned_at,scanned_at_unix,expires_at,expires_at_unix) VALUES(?,?,1,?,'pending',NOW(),?,DATE_ADD(NOW(),INTERVAL 30 MINUTE),?)");
        $stmt->execute([$connectionId,$customerId,$deviceUuid!==null&&trim($deviceUuid)!==''?mb_substr(trim($deviceUuid),0,200):null,$now,$expires]);
        $scanId=(int)$pdo->lastInsertId();$pdo->commit();
        return ['scan_id'=>$scanId,'customer'=>['id'=>$customerId,'name'=>trim((string)($row['name']??'')),'loyalty_balance'=>round((float)$row['loyalty_balance'],2)],'expires_at'=>$expires];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function evotor_customer_loyalty_attach_sale(PDO $pdo,array $connection,array $document,?int $saleId): ?array
{
    if(($document['type']??'')!=='SELL'||$saleId===null||$saleId<=0||empty($document['id']))return null;
    $connectionId=(int)($connection['id']??0);if($connectionId<=0)return null;
    $documentId=(string)$document['id'];
    $check=$pdo->prepare('SELECT id FROM evotor_customer_sales WHERE connection_id=? AND evotor_document_id=? LIMIT 1');$check->execute([$connectionId,$documentId]);if($check->fetchColumn())return null;

    try{$closeTs=(new DateTime((string)($document['close_date']??'now')))->getTimestamp();}catch(Throwable $e){$closeTs=time();}
    $minTs=$closeTs-1800;$maxTs=$closeTs+180;
    $scan=$pdo->prepare("SELECT * FROM evotor_customer_scans WHERE connection_id=? AND status='pending' AND scanned_at_unix BETWEEN ? AND ? AND expires_at_unix>=? ORDER BY scanned_at_unix DESC,id DESC LIMIT 1 FOR UPDATE");
    $scan->execute([$connectionId,$minTs,$maxTs,$closeTs]);$row=$scan->fetch();if(!$row)return null;

    $body=is_array($document['body']??null)?$document['body']:[];$gross=round(max(0,(float)($body['result_sum']??0)),2);
    $customerId=(int)$row['customer_id'];$earned=customer_loyalty_preview($gross);
    $insert=$pdo->prepare('INSERT INTO evotor_customer_sales(connection_id,evotor_document_id,sale_id,customer_id,scan_id,gross_amount,loyalty_earned,loyalty_spent) VALUES(?,?,?,?,?,?,?,0)');
    $insert->execute([$connectionId,$documentId,$saleId,$customerId,(int)$row['id'],$gross,$earned]);
    if($earned>0){
        $note='Начисление за покупку на Эвоторе · чек '.($document['number']??$documentId);
        $pdo->prepare("INSERT INTO customer_loyalty_ledger(customer_id,order_id,amount,operation_type,note) VALUES(?,NULL,?,'earn',?)")->execute([$customerId,$earned,mb_substr($note,0,255)]);
        $pdo->prepare('UPDATE customer_accounts SET loyalty_balance=loyalty_balance+? WHERE id=?')->execute([$earned,$customerId]);
    }
    $drinkStamps=customer_drink_loyalty_credit_sale($pdo,$customerId,$saleId,$documentId);
    $pdo->prepare("UPDATE evotor_customer_scans SET status='consumed',consumed_at=NOW(),consumed_document_id=? WHERE id=?")->execute([$documentId,(int)$row['id']]);
    return ['customer_id'=>$customerId,'scan_id'=>(int)$row['id'],'gross_amount'=>$gross,'loyalty_earned'=>$earned,'drink_stamps'=>$drinkStamps];
}

function evotor_customer_loyalty_attach_synced_sales(array $connection,int $limit=100): array
{
    $connectionId=(int)($connection['id']??0);if($connectionId<=0)return ['processed'=>0,'linked'=>0,'earned'=>0.0,'drink_stamps'=>0];
    $limit=max(1,min(500,$limit));$pdo=db();
    $stmt=$pdo->prepare("SELECT d.evotor_document_id,d.imported_sale_id,d.raw_json FROM evotor_documents d LEFT JOIN evotor_customer_sales cs ON cs.connection_id=d.connection_id AND cs.evotor_document_id=d.evotor_document_id WHERE d.connection_id=? AND d.document_type='SELL' AND d.imported_sale_id IS NOT NULL AND d.close_date>=DATE_SUB(NOW(),INTERVAL 2 DAY) AND cs.id IS NULL ORDER BY d.close_date DESC,d.id DESC LIMIT {$limit}");
    $stmt->execute([$connectionId]);$rows=$stmt->fetchAll();$linked=0;$earned=0.0;$drinkStamps=0;
    foreach($rows as $row){
        $document=json_decode((string)$row['raw_json'],true);if(!is_array($document)||empty($document['id']))continue;
        $pdo->beginTransaction();
        try{
            $result=evotor_customer_loyalty_attach_sale($pdo,$connection,$document,(int)$row['imported_sale_id']);
            $pdo->commit();
            if($result!==null){$linked++;$earned+=(float)$result['loyalty_earned'];$drinkStamps+=(int)($result['drink_stamps']??0);}
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    $pdo->prepare("UPDATE evotor_customer_scans SET status='expired' WHERE connection_id=? AND status='pending' AND expires_at_unix<?")->execute([$connectionId,time()]);
    return ['processed'=>count($rows),'linked'=>$linked,'earned'=>round($earned,2),'drink_stamps'=>$drinkStamps];
}
