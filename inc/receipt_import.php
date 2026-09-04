<?php
declare(strict_types=1);

require_once __DIR__.'/inventory.php';
require_once __DIR__.'/evotor.php';
require_once __DIR__.'/cash_flow.php';

function receipt_normalize_name(string $name): string
{
    $name=mb_strtolower(trim($name));
    $name=preg_replace('/\s+/u',' ',$name)??$name;
    $name=preg_replace('/[^\p{L}\p{N}%.,+\-\/ ]+/u','',$name)??$name;
    return trim($name);
}

function receipt_parse_qr(string $raw): array
{
    $raw=trim($raw);if($raw==='')throw new RuntimeException('QR-код пустой.');
    parse_str(str_replace(';','&',$raw),$q);
    $t=(string)($q['t']??'');$s=(string)($q['s']??'');$fn=preg_replace('/\D+/','',(string)($q['fn']??''));$fd=preg_replace('/\D+/','',(string)($q['i']??$q['fd']??''));$fp=preg_replace('/\D+/','',(string)($q['fp']??''));$n=(string)($q['n']??'1');
    if($fn===''||$fd===''||$fp===''||$t===''||$s==='')throw new RuntimeException('Это не похоже на QR фискального чека: не хватает ФН/ФД/ФП/даты/суммы.');
    $dt=null;foreach(['Ymd\THi','Ymd\THis'] as $fmt){$d=DateTimeImmutable::createFromFormat($fmt,$t);if($d){$dt=$d;break;}}
    if(!$dt)throw new RuntimeException('Не удалось распознать дату из QR чека.');
    return ['raw'=>$raw,'fn'=>$fn,'fd'=>$fd,'fp'=>$fp,'type'=>(int)$n,'sum'=>(float)str_replace(',','.',$s),'receipt_at'=>$dt->format('Y-m-d H:i:s')];
}

function receipt_money(mixed $value,bool $kopecks=false): float
{
    if(is_string($value))$value=str_replace([' ',' ',','],['','','.'],$value);
    $n=(float)$value;return round($kopecks?$n/100:$n,2);
}

function receipt_extract_document(array $json): array
{
    $nodes=[
      $json['ticket']['document']['receipt']??null,
      $json['document']['receipt']??null,
      $json['receipt']??null,
      $json['ticket']??null,
      $json,
    ];
    foreach($nodes as $node)if(is_array($node)&&isset($node['items'])&&is_array($node['items']))return $node;
    throw new RuntimeException('В JSON не найден список товаров чека.');
}

function receipt_document_from_json(array $json,?array $qr=null): array
{
    $r=receipt_extract_document($json);
    $fnsStyle=array_key_exists('totalSum',$r)||array_key_exists('fiscalDocumentNumber',$r)||array_key_exists('fiscalDriveNumber',$r);
    $items=[];$line=0;
    foreach($r['items'] as $item){if(!is_array($item))continue;$line++;$name=trim((string)($item['name']??$item['productName']??$item['title']??''));if($name==='')$name='Позиция '.$line;$qty=(float)($item['quantity']??$item['qty']??1);if($qty<=0)$qty=1;
        $priceRaw=$item['price']??$item['unitPrice']??$item['unit_price']??0;$sumRaw=$item['sum']??$item['total']??$item['lineTotal']??$item['line_total']??null;
        $unit=receipt_money($priceRaw,$fnsStyle&&array_key_exists('price',$item));$total=$sumRaw!==null?receipt_money($sumRaw,$fnsStyle&&array_key_exists('sum',$item)):round($unit*$qty,2);
        $items[]=['line_no'=>$line,'name'=>$name,'normalized_name'=>receipt_normalize_name($name),'quantity'=>$qty,'unit_price'=>$unit,'line_total'=>$total];
    }
    if(!$items)throw new RuntimeException('В чеке нет товарных позиций.');
    $total=0.0;foreach($items as $i)$total+=$i['line_total'];
    $seller=trim((string)($r['user']??$r['seller']??$r['retailPlace']??$r['organization']??''));$inn=preg_replace('/\D+/','',(string)($r['userInn']??$r['sellerInn']??$r['inn']??''));
    $dateRaw=$r['dateTime']??$r['receiptDateTime']??null;$receiptAt=$qr['receipt_at']??null;if($dateRaw){if(is_numeric($dateRaw))$receiptAt=date('Y-m-d H:i:s',(int)$dateRaw);elseif(strtotime((string)$dateRaw)!==false)$receiptAt=date('Y-m-d H:i:s',strtotime((string)$dateRaw));}
    $fn=(string)($r['fiscalDriveNumber']??$r['fn']??($qr['fn']??''));$fd=(string)($r['fiscalDocumentNumber']??$r['fd']??($qr['fd']??''));$fp=(string)($r['fiscalSign']??$r['fp']??($qr['fp']??''));
    $reported=$r['totalSum']??$r['total']??$r['total_amount']??null;if($reported!==null)$total=receipt_money($reported,$fnsStyle&&array_key_exists('totalSum',$r));
    return ['fn'=>$fn,'fd'=>$fd,'fp'=>$fp,'receipt_at'=>$receiptAt,'seller_name'=>$seller,'seller_inn'=>$inn,'total_amount'=>round($total,2),'items'=>$items,'raw_json'=>$json];
}

function receipt_fingerprint(array $doc,?string $qrRaw=null): string
{
    $parts=[(string)($doc['fn']??''),(string)($doc['fd']??''),(string)($doc['fp']??'')];
    if(implode('',$parts)!=='')return hash('sha256',implode('|',$parts));
    return hash('sha256',(string)$qrRaw.'|'.json_encode($doc['raw_json']??$doc,JSON_UNESCAPED_UNICODE));
}

function receipt_rule_for_name(string $normalized): ?array
{
    $stmt=db()->prepare('SELECT r.*,i.name ingredient_name,i.unit FROM receipt_item_rules r JOIN ingredients i ON i.id=r.ingredient_id WHERE r.normalized_name=? AND r.auto_apply=1 LIMIT 1');$stmt->execute([$normalized]);return $stmt->fetch()?:null;
}

function receipt_create_draft(array $doc,?string $qrRaw=null,string $source='manual_json'): int
{
    $pdo=db();$fingerprint=receipt_fingerprint($doc,$qrRaw);$check=$pdo->prepare('SELECT id,status FROM purchase_receipts WHERE fingerprint=?');$check->execute([$fingerprint]);$existing=$check->fetch();if($existing){if($existing['status']==='imported')throw new RuntimeException('Этот чек уже был оприходован ранее.');return (int)$existing['id'];}
    $pdo->beginTransaction();try{$stmt=$pdo->prepare('INSERT INTO purchase_receipts(fingerprint,qr_raw,fiscal_fn,fiscal_fd,fiscal_fp,receipt_at,seller_name,seller_inn,total_amount,status,source,raw_json) VALUES(?,?,?,?,?,?,?,?,?,\'draft\',?,?)');$stmt->execute([$fingerprint,$qrRaw,$doc['fn']?:null,$doc['fd']?:null,$doc['fp']?:null,$doc['receipt_at']?:null,$doc['seller_name']?:null,$doc['seller_inn']?:null,$doc['total_amount'],$source,json_encode($doc['raw_json'],JSON_UNESCAPED_UNICODE)]);$id=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare('INSERT INTO purchase_receipt_items(receipt_id,line_no,raw_name,normalized_name,receipt_quantity,unit_price,line_total,included,ingredient_id,quantity_per_item,rule_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        foreach($doc['items'] as $item){$rule=receipt_rule_for_name($item['normalized_name']);$ins->execute([$id,$item['line_no'],$item['name'],$item['normalized_name'],$item['quantity'],$item['unit_price'],$item['line_total'],1,$rule?(int)$rule['ingredient_id']:null,$rule?(float)$rule['quantity_per_item']:null,$rule?(int)$rule['id']:null]);}
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function receipt_draft(int $id): ?array
{
    $stmt=db()->prepare('SELECT * FROM purchase_receipts WHERE id=?');$stmt->execute([$id]);$r=$stmt->fetch();if(!$r)return null;$stmt=db()->prepare('SELECT ri.*,i.name ingredient_name,i.unit ingredient_unit FROM purchase_receipt_items ri LEFT JOIN ingredients i ON i.id=ri.ingredient_id WHERE ri.receipt_id=? ORDER BY ri.line_no,ri.id');$stmt->execute([$id]);$r['items']=$stmt->fetchAll();return $r;
}

function receipt_update_draft(int $receiptId,array $post): void
{
    $draft=receipt_draft($receiptId);if(!$draft||$draft['status']!=='draft')throw new RuntimeException('Черновик чека недоступен для изменения.');$pdo=db();$pdo->beginTransaction();try{$up=$pdo->prepare('UPDATE purchase_receipt_items SET included=?,ingredient_id=?,quantity_per_item=?,rule_id=? WHERE id=? AND receipt_id=?');
        foreach($draft['items'] as $row){$iid=(int)$row['id'];$included=isset($post['included'][$iid])?1:0;$ingredient=(int)($post['ingredient'][$iid]??0);$per=(float)str_replace(',','.',(string)($post['per_item'][$iid]??0));$saveRule=isset($post['save_rule'][$iid]);$ruleId=null;if($included&&$ingredient>0&&$per>0&&$saveRule){$stmt=$pdo->prepare('INSERT INTO receipt_item_rules(normalized_name,ingredient_id,quantity_per_item,auto_apply,last_seen_at) VALUES(?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE ingredient_id=VALUES(ingredient_id),quantity_per_item=VALUES(quantity_per_item),auto_apply=1,last_seen_at=NOW()');$stmt->execute([$row['normalized_name'],$ingredient,$per]);$q=$pdo->prepare('SELECT id FROM receipt_item_rules WHERE normalized_name=? LIMIT 1');$q->execute([$row['normalized_name']]);$ruleId=(int)$q->fetchColumn();}elseif($row['rule_id'])$ruleId=(int)$row['rule_id'];$up->execute([$included,$ingredient?:null,$per>0?$per:null,$ruleId?:null,$iid,$receiptId]);}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function receipt_commit_inventory(int $receiptId,?int $cashAccountId=null): array
{
    $draft=receipt_draft($receiptId);if(!$draft)throw new RuntimeException('Чек не найден.');if($draft['status']==='imported')throw new RuntimeException('Этот чек уже был оприходован.');
    $included=array_values(array_filter($draft['items'],fn($x)=>(int)$x['included']===1));if(!$included)throw new RuntimeException('В чеке не осталось позиций для кофейни.');foreach($included as $row)if((int)$row['ingredient_id']<=0||(float)$row['quantity_per_item']<=0)throw new RuntimeException('Для позиции «'.$row['raw_name'].'» не выбран ингредиент или коэффициент упаковки.');
    $pdo=db();$pdo->beginTransaction();$purchaseIds=[];try{
        $purchase=$pdo->prepare('INSERT INTO purchases(purchased_at,ingredient_id,quantity,total_amount,supplier,supplier_id,cash_flow_account_id) VALUES(?,?,?,?,?,NULL,?)');$stock=$pdo->prepare('UPDATE ingredients SET stock_quantity=stock_quantity+?,purchase_price=?,purchase_quantity=? WHERE id=?');$movement=$pdo->prepare('INSERT INTO inventory_movements(ingredient_id,movement_type,quantity_delta,reference_type,reference_id,occurred_at,note) VALUES(?,?,?,?,?,?,?)');
        $date=$draft['receipt_at']?date('Y-m-d',strtotime($draft['receipt_at'])):date('Y-m-d');$occurred=$draft['receipt_at']?:($date.' 12:00:00');$seller=trim((string)$draft['seller_name']);$expense=0.0;
        foreach($included as $row){$qty=(float)$row['receipt_quantity']*(float)$row['quantity_per_item'];$amount=(float)$row['line_total'];$ingredient=(int)$row['ingredient_id'];$purchase->execute([$date,$ingredient,$qty,$amount,$seller,$cashAccountId?:null]);$pid=(int)$pdo->lastInsertId();$purchaseIds[]=$pid;$stock->execute([$qty,$amount,$qty,$ingredient]);$movement->execute([$ingredient,'purchase',$qty,'purchase',$pid,$occurred,'QR-чек'.($seller!==''?' · '.$seller:'').' · '.$row['raw_name']]);$expense+=$amount;
            if($row['rule_id'])db()->prepare('UPDATE receipt_item_rules SET usage_count=usage_count+1,last_seen_at=NOW() WHERE id=?')->execute([(int)$row['rule_id']]);db()->prepare('UPDATE purchase_receipt_items SET purchase_id=? WHERE id=?')->execute([$pid,(int)$row['id']]);}
        if($cashAccountId&&$expense>0)cashflow_add_entry(['occurred_at'=>$occurred,'account_id'=>$cashAccountId,'direction'=>'out','entry_type'=>'purchase','amount'=>$expense,'source_type'=>'purchase_receipt','source_id'=>(string)$receiptId,'category'=>'Закупки','description'=>'QR-чек'.($seller!==''?' · '.$seller:'')]);
        $pdo->prepare("UPDATE purchase_receipts SET status='imported',imported_at=NOW() WHERE id=? AND status='draft'")->execute([$receiptId]);$pdo->commit();audit_write('receipt_inventory_imported','Оприходован QR-чек','purchase_receipt',(string)$receiptId,['purchases'=>$purchaseIds,'items'=>count($included),'amount'=>$expense]);return ['items'=>count($included),'amount'=>$expense,'purchase_ids'=>$purchaseIds];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function receipt_connection(): ?array
{
    return db()->query('SELECT * FROM receipt_data_connections WHERE enabled=1 ORDER BY id LIMIT 1')->fetch()?:null;
}

function receipt_connection_save(string $name,string $endpoint,string $token): void
{
    $pdo=db();$old=$pdo->query('SELECT * FROM receipt_data_connections ORDER BY id LIMIT 1')->fetch();$cipher=$old['token_ciphertext']??null;$iv=$old['token_iv']??null;$tag=$old['token_tag']??null;if($token!=='')[$cipher,$iv,$tag]=evotor_encrypt_token($token);if(trim($endpoint)==='')throw new RuntimeException('Укажите URL источника электронного чека.');
    if(!filter_var($endpoint,FILTER_VALIDATE_URL)||!str_starts_with(strtolower($endpoint),'https://'))throw new RuntimeException('Источник чеков должен использовать полный HTTPS URL.');
    if($old)$pdo->prepare('UPDATE receipt_data_connections SET name=?,endpoint_url=?,token_ciphertext=?,token_iv=?,token_tag=?,enabled=1 WHERE id=?')->execute([$name?:'Источник чеков',$endpoint,$cipher,$iv,$tag,(int)$old['id']]);else $pdo->prepare('INSERT INTO receipt_data_connections(name,endpoint_url,token_ciphertext,token_iv,token_tag,enabled) VALUES(?,?,?,?,?,1)')->execute([$name?:'Источник чеков',$endpoint,$cipher,$iv,$tag]);
}

function receipt_fetch_by_qr(array $qr): array
{
    $c=receipt_connection();if(!$c)throw new RuntimeException('Источник электронных чеков ещё не настроен. Можно вставить JSON чека вручную или настроить интеграцию.');$url=(string)$c['endpoint_url'];$query=['fn'=>$qr['fn'],'fd'=>$qr['fd'],'fp'=>$qr['fp'],'t'=>str_replace(['-',':',' '],['','','T'],$qr['receipt_at']),'s'=>$qr['sum'],'n'=>$qr['type']];$url.=(str_contains($url,'?')?'&':'?').http_build_query($query);
    $host=(string)parse_url($url,PHP_URL_HOST);if($host===''||filter_var($host,FILTER_VALIDATE_IP))throw new RuntimeException('Некорректный адрес источника чеков.');
    $headers=['Accept: application/json'];if(!empty($c['token_ciphertext']))$headers[]='Authorization: Bearer '.evotor_decrypt_token($c);
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);if($body===false||$err!=='')throw new RuntimeException('Не удалось получить электронный чек: '.$err);$json=json_decode((string)$body,true);if($status<200||$status>=300||!is_array($json))throw new RuntimeException('Источник чека вернул HTTP '.$status.' или некорректный JSON.');return $json;
}
