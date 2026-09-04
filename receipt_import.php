<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/receipt_import.php';
require_once __DIR__.'/inc/receipt_packages.php';
require_once __DIR__.'/inc/cash_flow.php';

$coreReady=function_exists('receipt_create_draft')&&function_exists('receipt_draft')&&function_exists('receipt_update_draft')&&function_exists('receipt_commit_inventory');
$packageReady=receipt_package_schema_ready();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if(in_array($action,['test_receipt','scan_qr','import_json','save_draft','commit'],true)&&!$coreReady){
            throw new RuntimeException('Модуль QR-закупок загружен не полностью: на сервере устарел inc/receipt_import.php. Загрузите весь текущий main поверх public_html, не удаляя config.php.');
        }
        if(in_array($action,['save_draft','commit'],true)&&!$packageReady){
            throw new RuntimeException('Миграция 025 для вариантов упаковки ещё не применена. Сначала откройте апдейтер и примените 025_receipt_package_variants.sql.');
        }
        if($action==='test_receipt'){
            $stamp=date('YmdHis');
            $json=['ticket'=>['document'=>['receipt'=>[
                'user'=>'Тестовый поставщик Kapouch','userInn'=>'3800000000','fiscalDriveNumber'=>'TEST'.$stamp,
                'fiscalDocumentNumber'=>(int)date('His'),'fiscalSign'=>random_int(100000000,999999999),'dateTime'=>date('Y-m-d\TH:i:s'),'totalSum'=>356000,
                'items'=>[
                    ['name'=>'Молоко 3,2% 0,93 л','quantity'=>12,'price'=>9500,'sum'=>114000],
                    ['name'=>'Кофе зерно 1 кг','quantity'=>2,'price'=>68000,'sum'=>136000],
                    ['name'=>'Сок апельсиновый 1 л','quantity'=>1,'price'=>76000,'sum'=>76000],
                    ['name'=>'Шоколад личная покупка','quantity'=>2,'price'=>15000,'sum'=>30000],
                ],
            ]]]];
            $doc=receipt_document_from_json($json,null);$id=receipt_create_draft($doc,null,'test');receipt_package_reconcile_draft($id);flash('success','Тестовый чек создан. Kapouch определил упаковки и готов запомнить варианты.');redirect('receipt_import.php?id='.$id);
        }
        if($action==='scan_qr'){
            $qrRaw=trim((string)($_POST['qr_raw']??''));$qr=receipt_parse_qr($qrRaw);$json=receipt_fetch_by_qr($qr);$doc=receipt_document_from_json($json,$qr);$id=receipt_create_draft($doc,$qrRaw,'qr_api');receipt_package_reconcile_draft($id);redirect('receipt_import.php?id='.$id);
        }
        if($action==='import_json'){
            $qrRaw=trim((string)($_POST['qr_raw']??''));$qr=$qrRaw!==''?receipt_parse_qr($qrRaw):null;$json=json_decode((string)($_POST['receipt_json']??''),true);if(!is_array($json))throw new RuntimeException('Не удалось прочитать JSON электронного чека.');$doc=receipt_document_from_json($json,$qr);$id=receipt_create_draft($doc,$qrRaw?:null,'manual_json');receipt_package_reconcile_draft($id);redirect('receipt_import.php?id='.$id);
        }
        if($action==='save_draft'){
            $id=(int)($_POST['receipt_id']??0);receipt_update_draft($id,$_POST);receipt_package_save_rules($id,$_POST);receipt_package_reconcile_draft($id);flash('success','Сопоставления сохранены. Вариант упаковки тоже запомнен.');redirect('receipt_import.php?id='.$id);
        }
        if($action==='commit'){
            $id=(int)($_POST['receipt_id']??0);receipt_update_draft($id,$_POST);receipt_package_save_rules($id,$_POST);receipt_package_reconcile_draft($id);$result=receipt_commit_inventory($id,(int)($_POST['cash_flow_account_id']??0)?:null);flash('success','Чек оприходован: '.$result['items'].' поз., '.money((float)$result['amount']).'. Остатки обновлены с учётом упаковок.');redirect('purchases.php');
        }
        if($action==='save_connection'){
            receipt_connection_save(trim((string)($_POST['connection_name']??'')),trim((string)($_POST['endpoint_url']??'')),trim((string)($_POST['token']??'')));flash('success','Источник электронных чеков сохранён.');redirect('receipt_import.php');
        }
    }catch(Throwable $e){error_log('[Kapouch receipt import] '.$e->getMessage());flash('danger',$e->getMessage());redirect('receipt_import.php'.(!empty($_POST['receipt_id'])?'?id='.(int)$_POST['receipt_id']:''));}
}

$id=(int)($_GET['id']??0);$draft=null;
if($id&&function_exists('receipt_draft')){try{$draft=receipt_draft($id);}catch(Throwable $e){error_log('[Kapouch receipt import GET draft] '.$e->getMessage());}}
if($draft&&$draft['status']==='draft'&&$packageReady){try{receipt_package_reconcile_draft($id);$draft=receipt_draft($id);}catch(Throwable $e){error_log('[Kapouch receipt packages reconcile] '.$e->getMessage());$packageReady=false;}}
try{$ingredients=db()->query('SELECT id,name,unit,stock_quantity FROM ingredients ORDER BY name')->fetchAll();}catch(Throwable $e){$ingredients=[];}
try{$accounts=array_values(array_filter(cashflow_accounts(true),fn($a)=>$a['account_type']!=='acquiring'));}catch(Throwable $e){$accounts=[];}
try{$connection=function_exists('receipt_connection')?receipt_connection():null;}catch(Throwable $e){$connection=null;}
try{$recent=db()->query("SELECT id,receipt_at,seller_name,total_amount,status,source FROM purchase_receipts ORDER BY id DESC LIMIT 20")->fetchAll();}catch(Throwable $e){$recent=[];}
page_header('QR-чек закупки');
?>
<div class="card"><div class="chart-head"><div><h2>Умный приход по кассовому чеку</h2><p>Kapouch различает варианты упаковки: 1 л, 1,5 л, 930 мл, 1 кг и другие размеры. Для каждого варианта можно сохранить свой коэффициент прихода.</p></div><a class="btn ghost" href="purchases.php">← Закупки</a></div></div>
<?php if(!$coreReady):?><div class="alert danger section"><strong>QR-модуль загружен не полностью.</strong> На сервере используется устаревший <code>inc/receipt_import.php</code>. Загрузите весь свежий <code>main</code> поверх текущего <code>public_html</code>, сохранив серверный <code>config.php</code>. После этого обновите страницу.</div><?php endif;?>
<?php if($coreReady&&!$packageReady):?><div class="alert warning section"><strong>Нужна миграция 025.</strong> QR-мастер больше не падает, но сохранение вариантов упаковки отключено до применения <code>025_receipt_package_variants.sql</code> через апдейтер.</div><?php endif;?>

<?php if(!$draft):?>
<div class="card section test-receipt-card"><div><div class="eyebrow">Проверка логики упаковок</div><h2>Тестовый чек</h2><p>Молоко 0,93 л, кофе 1 кг, сок 1 л и личная покупка.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="test_receipt"><button class="btn primary" <?=$coreReady?'':'disabled'?>>Создать тестовый чек</button></form></div>
<div class="two-col section">
  <div class="card"><h2>1. Сканировать QR</h2><p>После получения состава Kapouch автоматически попробует определить объём или вес упаковки из названия товара.</p><form method="post" id="qrForm" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="scan_qr"><label>QR-код<input id="qrRaw" name="qr_raw" required placeholder="t=...&s=...&fn=...&i=...&fp=...&n=1"></label><label>Фото QR<input id="qrImage" type="file" accept="image/*" capture="environment"></label><div><button class="btn primary" <?=$coreReady?'':'disabled'?>>Получить чек</button></div></form><div id="qrHint" class="muted" style="margin-top:10px">Если браузер поддерживает BarcodeDetector, QR с фото заполнится автоматически.</div></div>
  <div class="card"><h2>Резервный JSON</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="import_json"><label>QR, если есть<input name="qr_raw"></label><label>JSON чека<textarea name="receipt_json" rows="9" required></textarea></label><div><button class="btn primary" <?=$coreReady?'':'disabled'?>>Разобрать чек</button></div></form></div>
</div>
<div class="card section"><div class="chart-head"><div><h2>Источник электронных чеков</h2><p>Текущий провайдер используется для получения полного состава по QR.</p></div><span class="pill <?=$connection?'connected':''?>"><?=$connection?'Подключено':'Не настроено'?></span></div></div>
<?php else:?>
<div class="card section"><div class="chart-head"><div><div class="eyebrow">Черновик чека #<?=$draft['id']?></div><h2><?=e($draft['seller_name']?:'Кассовый чек')?></h2><p><?=e($draft['receipt_at']?date('d.m.Y H:i',strtotime($draft['receipt_at'])):'Дата не определена')?> · <?=money((float)$draft['total_amount'])?></p></div><span class="pill <?=$draft['status']==='imported'?'connected':''?>"><?=e($draft['status']==='draft'?'Черновик':'Оприходован')?></span></div></div>
<?php if($draft['status']==='draft'):?>
<div class="alert info section"><strong>Как считаем:</strong> количество на склад = количество упаковок в чеке × «сколько приходуем с 1 шт.». Например, 2 пачки сока по 1,5 л при коэффициенте 1500 мл дадут +3000 мл.</div>
<form method="post" id="draftForm"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="receipt_id" value="<?=$draft['id']?>"><input type="hidden" name="action" id="draftAction" value="save_draft">
<div class="card section table-card"><div style="overflow:auto"><table id="receiptItems"><thead><tr><th>Позиция</th><th>Упаковка</th><th>Ингредиент</th><th>С 1 шт.</th><th>Правило</th><th></th></tr></thead><tbody>
<?php foreach($draft['items'] as $row):$rid=(int)$row['id'];$pack=receipt_package_label(isset($row['detected_package_quantity'])?(float)$row['detected_package_quantity']:null,$row['detected_package_unit']??null);?>
<tr class="receipt-row <?=!$row['included']?'excluded':''?>"><td><label class="receipt-include"><input type="checkbox" name="included[<?=$rid?>]" value="1" <?=$row['included']?'checked':''?>><span><strong><?=e($row['raw_name'])?></strong><small><?=e((string)$row['receipt_quantity'])?> × <?=money((float)$row['unit_price'])?> = <?=money((float)$row['line_total'])?></small></span></label><?php if(!empty($row['package_warning'])):?><div class="alert warning" style="margin-top:7px;padding:7px 9px"><?=e($row['package_warning'])?></div><?php endif;?></td><td><?php if($pack):?><span class="pill connected"><?=e($pack)?></span><?php else:?><span class="pill">Не найдено</span><?php endif;?></td><td><select name="ingredient[<?=$rid?>]"><option value="0">— выбрать —</option><?php foreach($ingredients as $i):?><option value="<?=$i['id']?>" <?=((int)$row['ingredient_id']===(int)$i['id'])?'selected':''?>><?=e($i['name'])?> · <?=e($i['unit'])?></option><?php endforeach;?></select></td><td><input type="number" step="0.001" min="0" name="per_item[<?=$rid?>]" value="<?=e((string)($row['quantity_per_item']??''))?>" placeholder="1000 / 1500 / 930"><div class="muted">в единицах ингредиента: г / мл / шт.</div></td><td><label style="display:flex;gap:7px;align-items:center"><input type="checkbox" name="save_rule[<?=$rid?>]" value="1" style="width:auto" <?=$packageReady?'':'disabled'?>> Запомнить этот размер</label></td><td><button type="button" class="btn ghost exclude-btn" data-exclude="<?=$rid?>"><?=$row['included']?'Не для кофейни':'Вернуть'?></button></td></tr>
<?php endforeach;?></tbody></table></div></div>
<div class="card section"><div class="form-grid"><label>Денежный счёт<select name="cash_flow_account_id"><option value="0">Не учитывать в Cash Flow</option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach;?></select></label><div class="actions" style="align-self:end"><button class="btn ghost" type="submit" <?=$coreReady&&$packageReady?'':'disabled'?> onclick="document.getElementById('draftAction').value='save_draft'">Сохранить</button><button class="btn primary" type="submit" <?=$coreReady&&$packageReady?'':'disabled'?> onclick="document.getElementById('draftAction').value='commit'">Оприходовать на склад</button></div></div></div>
</form>
<?php endif;?>
<?php endif;?>

<div class="card section table-card"><div class="chart-head"><div><h2>Последние чеки</h2><p>Повторно оприходовать один и тот же фискальный чек нельзя.</p></div></div><?php if($recent):?><table><thead><tr><th>Дата</th><th>Поставщик</th><th>Сумма</th><th>Статус</th><th></th></tr></thead><tbody><?php foreach($recent as $r):?><tr><td><?=e($r['receipt_at']?date('d.m.Y H:i',strtotime($r['receipt_at'])):'—')?></td><td><?=e($r['seller_name']?:'—')?></td><td><?=money((float)$r['total_amount'])?></td><td><?=e($r['status'])?></td><td><a class="btn ghost" href="receipt_import.php?id=<?=$r['id']?>">Открыть</a></td></tr><?php endforeach;?></tbody></table><?php else:?><div class="muted">Чеков пока нет или таблица ещё не готова.</div><?php endif;?></div>
<script>
(function(){
 document.querySelectorAll('.exclude-btn').forEach(function(b){b.addEventListener('click',function(){var tr=b.closest('tr'),cb=tr&&tr.querySelector('input[type=checkbox][name^="included"]');if(!cb)return;cb.checked=!cb.checked;tr.classList.toggle('excluded',!cb.checked);b.textContent=cb.checked?'Не для кофейни':'Вернуть';});});
 var image=document.getElementById('qrImage'),raw=document.getElementById('qrRaw'),hint=document.getElementById('qrHint');
 if(image&&raw){image.addEventListener('change',async function(){var file=image.files&&image.files[0];if(!file)return;if(!('BarcodeDetector' in window)){if(hint)hint.textContent='Этот браузер не умеет распознавать QR из фото. Вставь QR строкой.';return;}try{var detector=new BarcodeDetector({formats:['qr_code']}),bitmap=await createImageBitmap(file),codes=await detector.detect(bitmap);if(codes[0]&&codes[0].rawValue){raw.value=codes[0].rawValue;if(hint)hint.textContent='QR распознан.';}else if(hint)hint.textContent='QR на фото не найден.';}catch(e){if(hint)hint.textContent='Не удалось распознать фото QR.';}});}
})();
</script>
<?php page_footer();?>
