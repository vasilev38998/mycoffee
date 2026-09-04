<?php
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/receipt_import.php';
require_once __DIR__.'/inc/cash_flow.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='scan_qr'){
            $qrRaw=trim((string)($_POST['qr_raw']??''));$qr=receipt_parse_qr($qrRaw);$json=receipt_fetch_by_qr($qr);$doc=receipt_document_from_json($json,$qr);$id=receipt_create_draft($doc,$qrRaw,'qr_api');redirect('receipt_import.php?id='.$id);
        }
        if($action==='import_json'){
            $qrRaw=trim((string)($_POST['qr_raw']??''));$qr=$qrRaw!==''?receipt_parse_qr($qrRaw):null;$json=json_decode((string)($_POST['receipt_json']??''),true);if(!is_array($json))throw new RuntimeException('Не удалось прочитать JSON электронного чека.');$doc=receipt_document_from_json($json,$qr);$id=receipt_create_draft($doc,$qrRaw?:null,'manual_json');redirect('receipt_import.php?id='.$id);
        }
        if($action==='save_draft'){
            $id=(int)($_POST['receipt_id']??0);receipt_update_draft($id,$_POST);flash('success','Сопоставления сохранены. Неиспользуемые позиции исключены из прихода.');redirect('receipt_import.php?id='.$id);
        }
        if($action==='commit'){
            $id=(int)($_POST['receipt_id']??0);receipt_update_draft($id,$_POST);$result=receipt_commit_inventory($id,(int)($_POST['cash_flow_account_id']??0)?:null);flash('success','Чек оприходован: '.$result['items'].' поз., '.money((float)$result['amount']).'. Остатки и закупочные цены обновлены.');redirect('purchases.php');
        }
        if($action==='save_connection'){
            receipt_connection_save(trim((string)($_POST['connection_name']??'')),trim((string)($_POST['endpoint_url']??'')),trim((string)($_POST['token']??'')));flash('success','Источник электронных чеков сохранён. Токен хранится зашифрованным.');redirect('receipt_import.php');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());redirect('receipt_import.php'.(!empty($_POST['receipt_id'])?'?id='.(int)$_POST['receipt_id']:''));}
}

$id=(int)($_GET['id']??0);$draft=$id?receipt_draft($id):null;$ingredients=db()->query('SELECT id,name,unit,stock_quantity FROM ingredients ORDER BY name')->fetchAll();$accounts=array_values(array_filter(cashflow_accounts(true),fn($a)=>$a['account_type']!=='acquiring'));$connection=receipt_connection();
$recent=db()->query("SELECT id,receipt_at,seller_name,total_amount,status,source FROM purchase_receipts ORDER BY id DESC LIMIT 20")->fetchAll();
page_header('QR-чек закупки');
?>
<div class="card"><div class="chart-head"><div><h2>Умный приход по кассовому чеку</h2><p>Сканируй QR, убирай личные покупки, один раз связывай товар с ингредиентом — следующие одинаковые позиции Kapouch сопоставит автоматически.</p></div><a class="btn ghost" href="purchases.php">← Закупки</a></div></div>

<?php if(!$draft):?>
<div class="two-col section">
  <div class="card"><div class="chart-head"><div><h2>1. Сканировать QR</h2><p>QR содержит фискальные реквизиты. Если подключён источник электронных чеков, Kapouch сразу запросит полный состав.</p></div></div>
    <form method="post" id="qrForm" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="scan_qr">
      <label>QR-код<input id="qrRaw" name="qr_raw" required placeholder="t=20260904T...&s=...&fn=...&i=...&fp=...&n=1"></label>
      <label>Фото QR с телефона<input id="qrImage" type="file" accept="image/*" capture="environment"></label>
      <div><button class="btn primary">Получить чек</button></div>
    </form><div id="qrHint" class="muted" style="margin-top:10px">На поддерживаемых телефонах фото QR распознаётся прямо в браузере. Если камера не поддерживает BarcodeDetector, QR можно вставить строкой.</div>
  </div>
  <div class="card"><div class="chart-head"><div><h2>Резервный импорт JSON</h2><p>Можно вставить цифровую копию чека в JSON — удобно до подключения серверного API или для диагностики.</p></div></div>
    <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="import_json"><label>QR, если есть<input name="qr_raw" placeholder="необязательно"></label><label>JSON электронного чека<textarea name="receipt_json" rows="9" required placeholder='{"ticket":{"document":{"receipt":{"items":[...]}}}}'></textarea></label><div><button class="btn primary">Разобрать чек</button></div></form>
  </div>
</div>

<div class="card section"><div class="chart-head"><div><h2>Источник электронных чеков</h2><p>Kapouch умеет передать серверному источнику реквизиты QR: <code>fn</code>, <code>fd</code>, <code>fp</code>, <code>t</code>, <code>s</code>, <code>n</code> и ожидает JSON чека.</p></div><span class="pill <?=$connection?'connected':''?>"><?=$connection?'Подключено':'Не настроено'?></span></div>
<div class="alert info"><strong>ФНС:</strong> официальный «Открытый API проверки чека ККТ» требует предварительной регистрации внешнего пользователя и мастер-токена. Поэтому здесь используется настраиваемый HTTPS-адаптер: можно подключить официальный шлюз после получения доступа или другой ваш серверный источник.</div>
<form method="post" class="form-grid" style="margin-top:14px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_connection"><label>Название<input name="connection_name" value="<?=e((string)($connection['name']??'Электронные чеки'))?>"></label><label>HTTPS endpoint<input type="url" name="endpoint_url" value="<?=e((string)($connection['endpoint_url']??''))?>" placeholder="https://.../receipt" required></label><label>Bearer token<input type="password" name="token" autocomplete="new-password" placeholder="<?= $connection?'Оставь пустым, чтобы не менять':'Токен источника' ?>"></label><div><button class="btn ghost">Сохранить интеграцию</button></div></form></div>
<?php else:?>
<div class="card section"><div class="chart-head"><div><div class="eyebrow">Черновик чека #<?=$draft['id']?></div><h2><?=e($draft['seller_name']?:'Кассовый чек')?></h2><p><?=e($draft['receipt_at']?date('d.m.Y H:i',strtotime($draft['receipt_at'])):'Дата не определена')?> · ИНН <?=e($draft['seller_inn']?:'—')?> · чек <?=money((float)$draft['total_amount'])?></p></div><span class="pill <?=$draft['status']==='imported'?'connected':''?>"><?=e($draft['status']==='draft'?'Черновик':'Оприходован')?></span></div></div>
<?php if($draft['status']==='draft'):?>
<form method="post" id="draftForm"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="receipt_id" value="<?=$draft['id']?>"><input type="hidden" name="action" id="draftAction" value="save_draft">
<div class="card section table-card"><div class="chart-head"><div><h2>2. Проверить позиции</h2><p>Нажми «Не для кофейни» у личной покупки — она останется в исходном чеке, но не попадёт ни на склад, ни в сумму закупки Kapouch.</p></div></div><div style="overflow:auto"><table id="receiptItems"><thead><tr><th>Позиция чека</th><th>Цена</th><th>Ингредиент</th><th>Сколько приходуем с 1 шт.</th><th>Правило</th><th></th></tr></thead><tbody>
<?php foreach($draft['items'] as $row):$rid=(int)$row['id'];?><tr class="receipt-row <?=!$row['included']?'excluded':''?>" data-row="<?=$rid?>"><td><label class="receipt-include"><input type="checkbox" name="included[<?=$rid?>]" value="1" <?=$row['included']?'checked':''?>><span><strong><?=e($row['raw_name'])?></strong><small><?=e((string)$row['receipt_quantity'])?> × <?=money((float)$row['unit_price'])?> = <?=money((float)$row['line_total'])?></small></span></label></td><td><?=money((float)$row['line_total'])?></td><td><select name="ingredient[<?=$rid?>]"><option value="0">— выбрать —</option><?php foreach($ingredients as $i):?><option value="<?=$i['id']?>" <?=((int)$row['ingredient_id']===(int)$i['id'])?'selected':''?>><?=e($i['name'])?> · <?=e($i['unit'])?></option><?php endforeach;?></select><?php if($row['rule_id']):?><div class="muted">✓ найдено по сохранённому правилу</div><?php endif;?></td><td><input type="number" step="0.001" min="0" name="per_item[<?=$rid?>]" value="<?=e((string)($row['quantity_per_item']??''))?>" placeholder="например 930"><div class="muted">в единицах ингредиента: г / мл / шт.</div></td><td><label style="display:flex;gap:7px;align-items:center"><input type="checkbox" name="save_rule[<?=$rid?>]" value="1" style="width:auto" <?=(!$row['rule_id']&&$row['ingredient_id'])?'checked':''?>> Запомнить</label></td><td><button type="button" class="btn ghost exclude-btn" data-exclude="<?=$rid?>"><?=$row['included']?'Не для кофейни':'Вернуть'?></button></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="card section"><div class="chart-head"><div><h2>3. Оприходовать</h2><p>Для каждой включённой позиции количество на склад = количество в чеке × коэффициент упаковки. Себестоимость берётся из фактической суммы строки.</p></div></div><div class="form-grid"><label>Денежный счёт<select name="cash_flow_account_id"><option value="0">Не учитывать в Cash Flow</option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach;?></select></label><div class="actions" style="align-self:end"><button class="btn ghost" type="submit" onclick="document.getElementById('draftAction').value='save_draft'">Сохранить черновик</button><button class="btn primary" type="submit" onclick="document.getElementById('draftAction').value='commit'">Оприходовать на склад</button></div></div></div>
</form>
<?php endif;?>
<?php endif;?>

<div class="card section table-card"><div class="chart-head"><div><h2>Последние чеки</h2><p>Повторный импорт уже оприходованного фискального чека блокируется автоматически.</p></div></div><table><thead><tr><th>Дата</th><th>Продавец</th><th>Сумма</th><th>Статус</th><th>Источник</th><th></th></tr></thead><tbody><?php foreach($recent as $r):?><tr><td><?=e($r['receipt_at']?date('d.m.Y H:i',strtotime($r['receipt_at'])):'—')?></td><td><?=e($r['seller_name']?:'—')?></td><td><?=money((float)$r['total_amount'])?></td><td><?=e($r['status']==='imported'?'Оприходован':'Черновик')?></td><td><?=e($r['source'])?></td><td><a class="btn ghost" href="receipt_import.php?id=<?=$r['id']?>">Открыть</a></td></tr><?php endforeach;?><?php if(!$recent):?><tr><td colspan="6" class="muted">Чеков пока нет.</td></tr><?php endif;?></tbody></table></div>

<style>.receipt-include{display:flex;gap:9px;align-items:flex-start}.receipt-include input{width:auto;margin-top:3px}.receipt-include span{display:grid;gap:3px}.receipt-include small{color:var(--muted)}.receipt-row.excluded{opacity:.42}.receipt-row.excluded select,.receipt-row.excluded input[type=number]{pointer-events:none}.receipt-row.excluded td{text-decoration-color:var(--muted)}</style>
<script>
(function(){
  document.querySelectorAll('[data-exclude]').forEach(function(btn){btn.addEventListener('click',function(){var id=btn.dataset.exclude,row=document.querySelector('[data-row="'+id+'"]'),cb=row.querySelector('input[name="included['+id+']"]');cb.checked=!cb.checked;row.classList.toggle('excluded',!cb.checked);btn.textContent=cb.checked?'Не для кофейни':'Вернуть';});});
  var file=document.getElementById('qrImage'),out=document.getElementById('qrRaw'),hint=document.getElementById('qrHint');if(file)file.addEventListener('change',async function(){if(!file.files||!file.files[0])return;if(!('BarcodeDetector'in window)){hint.textContent='Этот браузер не умеет распознавать QR из фото. Сканируй QR штатной камерой телефона и вставь полученную строку.';return;}try{var detector=new BarcodeDetector({formats:['qr_code']}),bitmap=await createImageBitmap(file.files[0]),codes=await detector.detect(bitmap);if(!codes.length)throw new Error('QR не найден');out.value=codes[0].rawValue;hint.textContent='QR распознан ✓ Можно нажать «Получить чек».';}catch(e){hint.textContent='Не удалось распознать QR: '+e.message;}});
})();
</script>
<?php page_footer();?>
