<?php
declare(strict_types=1);

function ensure_cash_register_tables(): void
{
    static $ready=false;
    if($ready)return;
    try{db()->query('SELECT id FROM cash_register_documents LIMIT 1');$ready=true;return;}catch(Throwable $e){}
    $migration=file_get_contents(__DIR__.'/../database/migrations/007_cash_register.sql');
    if($migration!==false)db()->exec($migration);
    $ready=true;
}

function evotor_cash_payment_amount(array $body): float
{
    $cash=0.0;
    foreach(($body['payments']??[]) as $payment){
        $type=(string)($payment['type']??$payment['payment_type']??'');
        if($type==='CASH')$cash+=(float)($payment['sum']??$payment['amount']??0);
    }
    return $cash;
}

function cash_document_values(array $document): array
{
    $type=(string)($document['type']??'UNKNOWN');
    $body=is_array($document['body']??null)?$document['body']:[];
    $cashDelta=0.0;$cashSale=0.0;$cashReturn=0.0;$amount=null;
    if($type==='SELL'){$cashSale=evotor_cash_payment_amount($body);$cashDelta=$cashSale;}
    elseif($type==='PAYBACK'){$cashReturn=evotor_cash_payment_amount($body);$cashDelta=-$cashReturn;}
    elseif($type==='CASH_INCOME'){$amount=(float)($body['sum']??0);$cashDelta=$amount;}
    elseif($type==='CASH_OUTCOME'){$amount=(float)($body['sum']??0);$cashDelta=-$amount;}

    return [
        'cash_delta'=>$cashDelta,
        'cash_sale_amount'=>$cashSale,
        'cash_return_amount'=>$cashReturn,
        'amount'=>$amount,
        'payment_category_id'=>isset($body['payment_category_id'])?(int)$body['payment_category_id']:null,
        'payment_category_name'=>$body['payment_category_name']??null,
        'description'=>$body['description']??null,
        'counterparty'=>$body['contributor']??$body['receiver']??null,
        'report_cash'=>isset($body['cash'])?(float)$body['cash']:null,
        'report_cash_in_sum'=>isset($body['cash_in_sum'])?(float)$body['cash_in_sum']:null,
        'report_cash_out_sum'=>isset($body['cash_out_sum'])?(float)$body['cash_out_sum']:null,
        'report_collection'=>isset($body['collection'])?(float)$body['collection']:null,
        'report_proceeds'=>isset($body['proceeds'])?(float)$body['proceeds']:null,
        'session_number'=>isset($body['session_number'])?(int)$body['session_number']:(isset($document['session_number'])?(int)$document['session_number']:null),
        'document_number'=>$body['document_number']??$document['number']??null,
    ];
}

function sync_evotor_cash_register(array $connection): int
{
    ensure_cash_register_tables();
    $pdo=db();$processed=0;$cursor=null;$first=true;$syncUntilMs=(int)floor(microtime(true)*1000);
    do{
        $query=[];
        if($first){
            if(!empty($connection['last_cash_sync_ms']))$query['since']=(int)$connection['last_cash_sync_ms'];
            $query['until']=$syncUntilMs;
            $query['type']='SELL,PAYBACK,CASH_INCOME,CASH_OUTCOME,OPEN_SESSION,CLOSE_SESSION,X_REPORT,Z_REPORT';
        }elseif($cursor){$query=['cursor'=>$cursor];}

        $response=evotor_request($connection,'/stores/'.rawurlencode($connection['store_id']).'/documents',$query);
        foreach(($response['items']??[]) as $document){
            if(empty($document['id']))continue;
            $values=cash_document_values($document);
            $stmt=$pdo->prepare('INSERT INTO cash_register_documents(connection_id,evotor_document_id,document_type,occurred_at,device_id,session_id,session_number,document_number,cash_delta,cash_sale_amount,cash_return_amount,amount,payment_category_id,payment_category_name,description,counterparty,report_cash,report_cash_in_sum,report_cash_out_sum,report_collection,report_proceeds,raw_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE document_type=VALUES(document_type),occurred_at=VALUES(occurred_at),device_id=VALUES(device_id),session_id=VALUES(session_id),session_number=VALUES(session_number),document_number=VALUES(document_number),cash_delta=VALUES(cash_delta),cash_sale_amount=VALUES(cash_sale_amount),cash_return_amount=VALUES(cash_return_amount),amount=VALUES(amount),payment_category_id=VALUES(payment_category_id),payment_category_name=VALUES(payment_category_name),description=VALUES(description),counterparty=VALUES(counterparty),report_cash=VALUES(report_cash),report_cash_in_sum=VALUES(report_cash_in_sum),report_cash_out_sum=VALUES(report_cash_out_sum),report_collection=VALUES(report_collection),report_proceeds=VALUES(report_proceeds),raw_json=VALUES(raw_json)');
            $stmt->execute([
                (int)$connection['id'],(string)$document['id'],(string)($document['type']??'UNKNOWN'),evotor_document_datetime($document),
                $document['device_id']??null,$document['session_id']??null,$values['session_number'],$values['document_number'],
                $values['cash_delta'],$values['cash_sale_amount'],$values['cash_return_amount'],$values['amount'],$values['payment_category_id'],$values['payment_category_name'],$values['description'],$values['counterparty'],
                $values['report_cash'],$values['report_cash_in_sum'],$values['report_cash_out_sum'],$values['report_collection'],$values['report_proceeds'],
                json_encode($document,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
            $processed++;
        }
        $cursor=$response['paging']['next_cursor']??null;$first=false;
    }while($cursor);
    $stmt=$pdo->prepare('UPDATE evotor_connections SET last_cash_sync_ms=? WHERE id=?');$stmt->execute([$syncUntilMs,(int)$connection['id']]);
    return $processed;
}

function current_cash_balance(?int $connectionId=null): array
{
    ensure_cash_register_tables();
    $pdo=db();$params=[];$where='';
    if($connectionId){$where=' AND connection_id=?';$params[]=$connectionId;}

    $stmt=$pdo->prepare("SELECT * FROM cash_register_documents WHERE document_type='OPEN_SESSION'{$where} ORDER BY occurred_at DESC,id DESC LIMIT 1");
    $stmt->execute($params);$open=$stmt->fetch()?:null;

    $closeParams=[];$closeWhere='';
    if($connectionId){$closeWhere=' AND connection_id=?';$closeParams[]=$connectionId;}
    $stmt=$pdo->prepare("SELECT * FROM cash_register_documents WHERE document_type IN ('CLOSE_SESSION','Z_REPORT'){$closeWhere} ORDER BY occurred_at DESC,id DESC LIMIT 1");
    $stmt->execute($closeParams);$close=$stmt->fetch()?:null;

    if($open && (!$close || strtotime((string)$open['occurred_at'])>strtotime((string)$close['occurred_at']))){
        $sql='SELECT COALESCE(SUM(cash_delta),0) FROM cash_register_documents WHERE cash_delta<>0 AND occurred_at>=?';
        $args=[$open['occurred_at']];
        if(!empty($open['session_id'])){$sql.=' AND session_id=?';$args[]=$open['session_id'];}
        elseif(!empty($open['session_number'])){$sql.=' AND session_number=?';$args[]=$open['session_number'];}
        if($connectionId){$sql.=' AND connection_id=?';$args[]=$connectionId;}
        $stmt=$pdo->prepare($sql);$stmt->execute($args);$balance=(float)$stmt->fetchColumn();
        return ['balance'=>$balance,'report'=>null,'delta_after_report'=>$balance,'shift_open'=>true,'shift'=>$open,'inferred_open'=>false];
    }

    if($close){
        $reportSql="SELECT * FROM cash_register_documents WHERE document_type='X_REPORT' AND report_cash IS NOT NULL AND occurred_at>?";
        $reportArgs=[$close['occurred_at']];
        if($connectionId){$reportSql.=' AND connection_id=?';$reportArgs[]=$connectionId;}
        $reportSql.=' ORDER BY occurred_at DESC,id DESC LIMIT 1';
        $stmt=$pdo->prepare($reportSql);$stmt->execute($reportArgs);$currentReport=$stmt->fetch()?:null;

        if($currentReport){
            $sql='SELECT COALESCE(SUM(cash_delta),0) FROM cash_register_documents WHERE cash_delta<>0 AND occurred_at>?';
            $args=[$currentReport['occurred_at']];
            if($connectionId){$sql.=' AND connection_id=?';$args[]=$connectionId;}
            $stmt=$pdo->prepare($sql);$stmt->execute($args);$after=(float)$stmt->fetchColumn();
            return ['balance'=>(float)$currentReport['report_cash']+$after,'report'=>$currentReport,'delta_after_report'=>$after,'shift_open'=>true,'shift'=>$open,'inferred_open'=>true];
        }

        $sql='SELECT COUNT(*) movement_count,COALESCE(SUM(cash_delta),0) balance FROM cash_register_documents WHERE cash_delta<>0 AND occurred_at>?';
        $args=[$close['occurred_at']];
        if($connectionId){$sql.=' AND connection_id=?';$args[]=$connectionId;}
        $stmt=$pdo->prepare($sql);$stmt->execute($args);$movement=$stmt->fetch()?:[];
        if((int)($movement['movement_count']??0)>0){
            $balance=(float)($movement['balance']??0);
            return ['balance'=>$balance,'report'=>null,'delta_after_report'=>$balance,'shift_open'=>true,'shift'=>$open,'inferred_open'=>true];
        }

        return ['balance'=>0.0,'report'=>$close['document_type']==='Z_REPORT'?$close:null,'delta_after_report'=>0.0,'shift_open'=>false,'shift'=>$open,'inferred_open'=>false];
    }

    $fallbackParams=[];$fallbackWhere='';
    if($connectionId){$fallbackWhere=' AND connection_id=?';$fallbackParams[]=$connectionId;}
    $stmt=$pdo->prepare("SELECT * FROM cash_register_documents WHERE document_type IN ('Z_REPORT','X_REPORT') AND report_cash IS NOT NULL{$fallbackWhere} ORDER BY occurred_at DESC,id DESC LIMIT 1");$stmt->execute($fallbackParams);$report=$stmt->fetch()?:null;
    $balance=0.0;$since=null;
    if($report){$balance=(float)$report['report_cash'];$since=$report['occurred_at'];}
    $sql='SELECT COALESCE(SUM(cash_delta),0) FROM cash_register_documents WHERE cash_delta<>0';$args=[];
    if($since){$sql.=' AND occurred_at>?';$args[]=$since;}
    if($connectionId){$sql.=' AND connection_id=?';$args[]=$connectionId;}
    $stmt=$pdo->prepare($sql);$stmt->execute($args);$after=(float)$stmt->fetchColumn();
    return ['balance'=>$balance+$after,'report'=>$report,'delta_after_report'=>$after,'shift_open'=>null,'shift'=>null,'inferred_open'=>false];
}

function cash_period_summary(string $from,string $to): array
{
    ensure_cash_register_tables();
    $stmt=db()->prepare("SELECT COALESCE(SUM(cash_sale_amount),0) cash_sales,COALESCE(SUM(cash_return_amount),0) cash_returns,COALESCE(SUM(CASE WHEN document_type='CASH_INCOME' THEN amount ELSE 0 END),0) cash_income,COALESCE(SUM(CASE WHEN document_type='CASH_OUTCOME' THEN amount ELSE 0 END),0) cash_outcome,COALESCE(SUM(CASE WHEN document_type='CASH_OUTCOME' AND payment_category_id=1 THEN amount ELSE 0 END),0) collection FROM cash_register_documents WHERE occurred_at>=? AND occurred_at<DATE_ADD(?,INTERVAL 1 DAY)");
    $stmt->execute([$from.' 00:00:00',$to]);$r=$stmt->fetch()?:[];
    foreach(['cash_sales','cash_returns','cash_income','cash_outcome','collection'] as $k)$r[$k]=(float)($r[$k]??0);
    $r['net_movement']=$r['cash_sales']+$r['cash_income']-$r['cash_returns']-$r['cash_outcome'];
    return $r;
}

function cash_session_reports(string $from,string $to): array
{
    ensure_cash_register_tables();
    $stmt=db()->prepare("SELECT z.session_id,z.session_number,z.occurred_at,z.report_cash,z.report_cash_in_sum,z.report_cash_out_sum,z.report_collection,z.report_proceeds,
        COALESCE(SUM(CASE WHEN d.document_type='SELL' THEN d.cash_sale_amount ELSE 0 END),0) cash_sales,
        COALESCE(SUM(CASE WHEN d.document_type='PAYBACK' THEN d.cash_return_amount ELSE 0 END),0) cash_returns,
        COALESCE(SUM(CASE WHEN d.document_type='CASH_INCOME' THEN d.amount ELSE 0 END),0) cash_income,
        COALESCE(SUM(CASE WHEN d.document_type='CASH_OUTCOME' THEN d.amount ELSE 0 END),0) cash_outcome
        FROM cash_register_documents z LEFT JOIN cash_register_documents d ON d.connection_id=z.connection_id AND ((z.session_id IS NOT NULL AND d.session_id=z.session_id) OR (z.session_id IS NULL AND d.session_number=z.session_number))
        WHERE z.document_type='Z_REPORT' AND z.occurred_at>=? AND z.occurred_at<DATE_ADD(?,INTERVAL 1 DAY)
        GROUP BY z.id ORDER BY z.occurred_at DESC");
    $stmt->execute([$from.' 00:00:00',$to]);return $stmt->fetchAll();
}
