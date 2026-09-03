<?php
declare(strict_types=1);

function cashflow_accounts(bool $activeOnly=false): array{
    return db()->query('SELECT * FROM cash_flow_accounts'.($activeOnly?' WHERE active=1':'').' ORDER BY FIELD(account_type,\'cash\',\'bank\',\'acquiring\',\'owner\',\'other\'),name')->fetchAll();
}
function cashflow_account_by_name(string $name): ?array{$s=db()->prepare('SELECT * FROM cash_flow_accounts WHERE name=? LIMIT 1');$s->execute([$name]);return $s->fetch()?:null;}
function cashflow_account_balance(int $accountId): float{
    $s=db()->prepare("SELECT a.opening_balance + COALESCE(SUM(CASE WHEN e.direction='in' THEN e.amount ELSE -e.amount END),0) FROM cash_flow_accounts a LEFT JOIN cash_flow_entries e ON e.account_id=a.id WHERE a.id=? GROUP BY a.id,a.opening_balance");$s->execute([$accountId]);return (float)($s->fetchColumn()?:0);
}
function cashflow_balances(): array{$rows=cashflow_accounts(false);foreach($rows as &$r)$r['balance']=cashflow_account_balance((int)$r['id']);unset($r);return $rows;}
function cashflow_set_opening_balance(int $accountId,float $balance): void{$s=db()->prepare('UPDATE cash_flow_accounts SET opening_balance=? WHERE id=?');$s->execute([$balance,$accountId]);}
function cashflow_update_account(int $accountId,string $name,string $provider,bool $active): void{
    $name=trim($name);if($accountId<=0||$name==='')throw new RuntimeException('Укажи название денежного счёта.');$s=db()->prepare('UPDATE cash_flow_accounts SET name=?,provider=?,active=? WHERE id=?');$s->execute([$name,trim($provider)?:null,$active?1:0,$accountId]);
}
function cashflow_add_entry(array $data): int{
    $u=current_user();$s=db()->prepare('INSERT INTO cash_flow_entries(occurred_at,account_id,direction,entry_type,amount,counter_account_id,source_type,source_id,category,description,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$data['occurred_at'],$data['account_id'],$data['direction'],$data['entry_type'],$data['amount'],$data['counter_account_id']??null,$data['source_type']??null,$data['source_id']??null,$data['category']??null,$data['description']??null,$u['id']??null]);return (int)db()->lastInsertId();
}
function cashflow_transfer(int $fromId,int $toId,float $amount,string $occurredAt,string $type='transfer',string $description=''): void{
    if($fromId<=0||$toId<=0||$fromId===$toId||$amount<=0)throw new RuntimeException('Проверь счета и сумму перевода.');
    $pdo=db();$pdo->beginTransaction();try{$key='manual_transfer_'.bin2hex(random_bytes(8));cashflow_add_entry(['occurred_at'=>$occurredAt,'account_id'=>$fromId,'direction'=>'out','entry_type'=>$type,'amount'=>$amount,'counter_account_id'=>$toId,'source_type'=>'manual_transfer','source_id'=>$key,'description'=>$description]);cashflow_add_entry(['occurred_at'=>$occurredAt,'account_id'=>$toId,'direction'=>'in','entry_type'=>$type,'amount'=>$amount,'counter_account_id'=>$fromId,'source_type'=>'manual_transfer','source_id'=>$key,'description'=>$description]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
function cashflow_manual_entry(int $accountId,string $direction,string $type,float $amount,string $occurredAt,string $category,string $description): int{
    if(!in_array($direction,['in','out'],true)||$amount<=0)throw new RuntimeException('Некорректная операция.');return cashflow_add_entry(['occurred_at'=>$occurredAt,'account_id'=>$accountId,'direction'=>$direction,'entry_type'=>$type,'amount'=>$amount,'source_type'=>'manual','source_id'=>bin2hex(random_bytes(12)),'category'=>$category?:null,'description'=>$description?:null]);}
function cashflow_settle_electronic(float $gross,float $fee,int $bankAccountId,string $occurredAt,string $description='Зачисление безналичных продаж'): void{
    $pending=cashflow_account_by_name((string)app_setting('cashflow_electron_account_name','Безнал / ожидаемые поступления'));if(!$pending)throw new RuntimeException('Не найден счёт ожидаемых безналичных поступлений.');$bankStmt=db()->prepare("SELECT * FROM cash_flow_accounts WHERE id=? AND account_type='bank' AND active=1");$bankStmt->execute([$bankAccountId]);$bank=$bankStmt->fetch();if(!$bank)throw new RuntimeException('Выбери активный банковский счёт.');if($gross<=0||$fee<0||$fee>$gross)throw new RuntimeException('Проверь сумму зачисления и комиссию.');$net=$gross-$fee;
    $pdo=db();$pdo->beginTransaction();try{$key='electron_settlement_'.bin2hex(random_bytes(8));if($net>0){cashflow_add_entry(['occurred_at'=>$occurredAt,'account_id'=>(int)$pending['id'],'direction'=>'out','entry_type'=>'transfer','amount'=>$net,'counter_account_id'=>(int)$bank['id'],'source_type'=>'electron_settlement','source_id'=>$key.':out','description'=>$description]);cashflow_add_entry(['occurred_at'=>$occurredAt,'account_id'=>(int)$bank['id'],'direction'=>'in','entry_type'=>'transfer','amount'=>$net,'counter_account_id'=>(int)$pending['id'],'source_type'=>'electron_settlement','source_id'=>$key.':in','description'=>$description]);}if($fee>0)cashflow_add_entry(['occurred_at'=>$occurredAt,'account_id'=>(int)$pending['id'],'direction'=>'out','entry_type'=>'fee','amount'=>$fee,'source_type'=>'electron_fee','source_id'=>$key.':fee','category'=>'Эквайринг','description'=>'Комиссия по безналичным платежам']);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}

function cashflow_payment_amount(array $payment): float{return (float)($payment['sum']??$payment['amount']??0);}
function cashflow_sync_evotor_payments(int $limit=5000): array{
    $cash=cashflow_account_by_name('Касса Эвотор');$electron=cashflow_account_by_name((string)app_setting('cashflow_electron_account_name','Безнал / ожидаемые поступления'));if(!$cash||!$electron)return ['processed'=>0,'cash'=>0,'electron'=>0];
    $s=db()->prepare("SELECT id,evotor_document_id,document_type,close_date,raw_json FROM evotor_documents WHERE document_type IN ('SELL','PAYBACK') ORDER BY id DESC LIMIT ?");$s->bindValue(1,$limit,PDO::PARAM_INT);$s->execute();$rows=array_reverse($s->fetchAll());$result=['processed'=>0,'cash'=>0.0,'electron'=>0.0];
    foreach($rows as $row){$doc=json_decode((string)$row['raw_json'],true);if(!is_array($doc))continue;$body=is_array($doc['body']??null)?$doc['body']:[];$refund=$row['document_type']==='PAYBACK';foreach(($body['payments']??[]) as $idx=>$p){$ptype=(string)($p['type']??'');if(!in_array($ptype,['CASH','ELECTRON'],true))continue;$amount=abs(cashflow_payment_amount($p));if($amount<=0)continue;$account=$ptype==='CASH'?$cash:$electron;$direction=$refund?'out':'in';$entryType=$refund?'refund':'sale';$sourceId=(string)$row['evotor_document_id'].':'.$idx.':'.$ptype;
            try{$stmt=db()->prepare("INSERT IGNORE INTO cash_flow_entries(occurred_at,account_id,direction,entry_type,amount,source_type,source_id,category,description) VALUES(?,?,?,?,?,'evotor_payment',?,'Продажи',?)");$stmt->execute([$row['close_date'],(int)$account['id'],$direction,$entryType,$amount,$sourceId,($ptype==='ELECTRON'?'Безнал':'Наличные').' · Эвотор '.$row['evotor_document_id']]);if($stmt->rowCount()>0){$result['processed']++;$result[$ptype==='CASH'?'cash':'electron']+=$refund?-$amount:$amount;}}catch(Throwable $e){}
        }}return $result;
}

function cashflow_period(string $from,string $to): array{
    $s=db()->prepare("SELECT e.*,a.name account_name,a.account_type,ca.name counter_name FROM cash_flow_entries e JOIN cash_flow_accounts a ON a.id=e.account_id LEFT JOIN cash_flow_accounts ca ON ca.id=e.counter_account_id WHERE e.occurred_at>=? AND e.occurred_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY e.occurred_at DESC,e.id DESC");$s->execute([$from.' 00:00:00',$to]);return $s->fetchAll();
}
function cashflow_summary(string $from,string $to): array{
    $rows=cashflow_period($from,$to);$in=0.0;$out=0.0;$operatingIn=0.0;$operatingOut=0.0;$ownerNet=0.0;
    foreach($rows as $r){$amount=(float)$r['amount'];if($r['direction']==='in')$in+=$amount;else$out+=$amount;if(in_array($r['entry_type'],['sale','refund','expense','purchase','fee','other'],true)){if($r['direction']==='in')$operatingIn+=$amount;else$operatingOut+=$amount;}if($r['entry_type']==='owner_in')$ownerNet+=$amount;if($r['entry_type']==='owner_out')$ownerNet-=$amount;}
    return ['in'=>$in,'out'=>$out,'net'=>$in-$out,'operating_in'=>$operatingIn,'operating_out'=>$operatingOut,'operating_net'=>$operatingIn-$operatingOut,'owner_net'=>$ownerNet,'rows'=>$rows];
}
function cashflow_readiness(int $minHistoryDays=7): array{
    $pdo=db();$accounts=cashflow_balances();$bankConfigured=false;
    foreach($accounts as $a){if($a['account_type']!=='bank'||!(int)$a['active'])continue;$hasIdentity=trim((string)($a['provider']??''))!==''||trim((string)$a['name'])!=='Банковский счёт';$hasOpening=abs((float)$a['opening_balance'])>0.009;$q=$pdo->prepare('SELECT COUNT(*) FROM cash_flow_entries WHERE account_id=?');$q->execute([(int)$a['id']]);$hasMovements=(int)$q->fetchColumn()>0;if($hasIdentity||$hasOpening||$hasMovements){$bankConfigured=true;break;}}
    $historyDays=(int)$pdo->query("SELECT COUNT(DISTINCT DATE(occurred_at)) FROM cash_flow_entries WHERE occurred_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND occurred_at<=NOW()")->fetchColumn();
    $to=date('Y-m-d');$from=date('Y-m-d',strtotime('-29 days'));$sum=cashflow_summary($from,$to);$reasons=[];
    if(!$bankConfigured)$reasons[]='Настрой банковский счёт или укажи его начальный остаток.';
    if($historyDays<$minHistoryDays)$reasons[]='Нужно хотя бы '.$minHistoryDays.' дней денежных движений; сейчас есть '.$historyDays.'.';
    if($sum['operating_out']<=0)$reasons[]='Пока нет достаточных денежных расходов для расчёта запаса в днях.';
    return ['ready'=>!$reasons,'reasons'=>$reasons,'history_days'=>$historyDays,'bank_configured'=>$bankConfigured,'operating_out'=>(float)$sum['operating_out']];
}
function cashflow_runway_days(int $lookbackDays=30): ?float{
    $readiness=cashflow_readiness(7);if(!$readiness['ready'])return null;$to=date('Y-m-d');$from=date('Y-m-d',strtotime('-'.($lookbackDays-1).' days'));$sum=cashflow_summary($from,$to);$dailyOut=$sum['operating_out']/max(1,$lookbackDays);if($dailyOut<=0)return null;$liquid=0.0;foreach(cashflow_balances() as $a){if(in_array($a['account_type'],['cash','bank'],true))$liquid+=(float)$a['balance'];}return $liquid/$dailyOut;
}
function cashflow_electronic_pending(): float{$a=cashflow_account_by_name((string)app_setting('cashflow_electron_account_name','Безнал / ожидаемые поступления'));return $a?cashflow_account_balance((int)$a['id']):0.0;}
function cashflow_profit_vs_money(string $from,string $to): array{$m=dashboard_metrics($from,$to);$cf=cashflow_summary($from,$to);return ['operating_profit'=>$m['operating_profit'],'cashflow_operating_net'=>$cf['operating_net'],'difference'=>$cf['operating_net']-$m['operating_profit']];}
