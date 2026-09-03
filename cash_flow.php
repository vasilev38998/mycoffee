<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/cash_flow.php';
require __DIR__.'/inc/layout.php';

try{cashflow_sync_evotor_payments();}catch(Throwable $e){}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='manual'){
            $id=cashflow_manual_entry((int)$_POST['account_id'],(string)$_POST['direction'],(string)$_POST['entry_type'],(float)$_POST['amount'],(string)($_POST['occurred_at']??date('Y-m-d H:i:s')),trim((string)($_POST['category']??'')),trim((string)($_POST['description']??'')));
            audit_write('cashflow_entry_created','Добавлена денежная операция','cash_flow_entry',(string)$id);flash('success','Операция добавлена.');
        }elseif($action==='transfer'){
            cashflow_transfer((int)$_POST['from_id'],(int)$_POST['to_id'],(float)$_POST['amount'],(string)($_POST['occurred_at']??date('Y-m-d H:i:s')),'transfer',trim((string)($_POST['description']??'')));audit_write('cashflow_transfer','Перевод между денежными счетами');flash('success','Перевод сохранён.');
        }elseif($action==='sber_settlement'){
            cashflow_settle_acquiring((float)$_POST['gross_amount'],(float)$_POST['fee_amount'],(string)($_POST['occurred_at']??date('Y-m-d H:i:s')),trim((string)($_POST['description']??'Зачисление Сбер')));audit_write('sber_settlement','Зачисление эквайринга Сбер');flash('success','Зачисление эквайринга учтено.');
        }elseif($action==='opening'){
            cashflow_set_opening_balance((int)$_POST['account_id'],(float)$_POST['opening_balance']);audit_write('cashflow_opening_balance','Изменён начальный остаток денежного счёта');flash('success','Начальный остаток обновлён.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('cash_flow.php');
}

$to=$_GET['to']??date('Y-m-d');$from=$_GET['from']??date('Y-m-d',strtotime('-29 days'));
$accounts=cashflow_balances();$summary=cashflow_summary($from,$to);$runway=cashflow_runway_days(30);$pending=cashflow_acquiring_pending();$pvsm=cashflow_profit_vs_money($from,$to);
$liquid=array_sum(array_map(fn($a)=>in_array($a['account_type'],['cash','bank'],true)?(float)$a['balance']:0,$accounts));
page_header('Денежный поток');
?>
<div class="card filter-card"><form method="get" style="display:contents"><label>С<input type="date" name="from" value="<?=e($from)?>"></label><label>По<input type="date" name="to" value="<?=e($to)?>"></label><button class="btn primary">Показать</button></form></div>

<div class="grid section">
<div class="card metric"><div class="label">Ликвидные деньги</div><div class="value"><?=money($liquid)?></div><div class="meta">Касса + банковские счета</div></div>
<div class="card metric"><div class="label">Сбер: ожидается зачисление</div><div class="value"><?=money($pending)?></div><div class="meta">Безнал из Эвотор минус учтённые зачисления и комиссии</div></div>
<div class="card metric"><div class="label">Операционный денежный поток</div><div class="value"><?=money($summary['operating_net'])?></div><div class="meta">За выбранный период</div></div>
<div class="card metric"><div class="label">Cash runway</div><div class="value"><?=$runway===null?'—':number_format($runway,1,',',' ')?></div><div class="meta">дней при текущем темпе денежных расходов</div></div>
</div>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Прибыль vs реальные деньги</h2><p>Разница между управленческой прибылью и фактическим денежным потоком.</p></div></div><table><tbody><tr><td>Операционная прибыль</td><td><strong><?=money($pvsm['operating_profit'])?></strong></td></tr><tr><td>Операционный Cash Flow</td><td><strong><?=money($pvsm['cashflow_operating_net'])?></strong></td></tr><tr><td>Разница</td><td><strong><?=($pvsm['difference']>=0?'+':'').money($pvsm['difference'])?></strong></td></tr></tbody></table><div class="alert-item warn section"><span class="alert-dot"></span><div><strong>Почему цифры отличаются</strong><p>Закупка запасов, вывод владельца, вложения, эквайринговые комиссии и задержка банковского зачисления меняют деньги, но не всегда совпадают по моменту с прибылью.</p></div></div></div>
<div class="card"><div class="chart-head"><div><h2>Сбер Эквайринг</h2><p>Эвотор автоматически создаёт ожидаемые карточные поступления. Здесь фиксируется фактическое зачисление на расчётный счёт.</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sber_settlement"><label>Дата и время<input type="datetime-local" name="occurred_at" value="<?=date('Y-m-d\TH:i')?>"></label><label>Сумма продаж до комиссии<input type="number" min="0.01" step="0.01" name="gross_amount" required></label><label>Комиссия Сбера<input type="number" min="0" step="0.01" name="fee_amount" value="0"></label><label>Комментарий<input name="description" value="Зачисление Сбер эквайринга"></label><button class="btn primary">Учесть зачисление</button></form><div class="section insight-card"><div class="kicker">Сейчас ожидается</div><strong><?=money($pending)?></strong><p>После фактического зачисления эта сумма уменьшается, а расчётный счёт увеличивается на сумму за вычетом комиссии.</p></div></div>
</div>

<div class="card table-card section"><div class="chart-head"><div><h2>Денежные счета</h2><p>Начальный остаток задаётся один раз на дату начала денежного учёта.</p></div></div><table><thead><tr><th>Счёт</th><th>Тип</th><th>Провайдер</th><th>Начальный остаток</th><th>Расчётный остаток</th><th>Настройка</th></tr></thead><tbody><?php foreach($accounts as $a):?><tr><td><strong><?=e($a['name'])?></strong></td><td><?=e($a['account_type'])?></td><td><?=e((string)($a['provider']??'—'))?></td><td><?=money((float)$a['opening_balance'])?></td><td><strong><?=money((float)$a['balance'])?></strong></td><td><details><summary class="btn ghost">Остаток</summary><form method="post" class="stack" style="margin-top:10px;min-width:220px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="opening"><input type="hidden" name="account_id" value="<?=$a['id']?>"><label>Начальный остаток<input type="number" step="0.01" name="opening_balance" value="<?=e((string)$a['opening_balance'])?>"></label><button class="btn">Сохранить</button></form></details></td></tr><?php endforeach;?></tbody></table></div>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Ручная денежная операция</h2><p>Для банковских расходов, вложений владельца и других движений, которых нет в Эвотор.</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="manual"><div class="form-grid"><label>Счёт<select name="account_id"><?php foreach($accounts as $a):?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach;?></select></label><label>Направление<select name="direction"><option value="out">Расход</option><option value="in">Поступление</option></select></label><label>Тип<select name="entry_type"><option value="expense">Расход</option><option value="purchase">Закупка</option><option value="owner_in">Вложение владельца</option><option value="owner_out">Вывод владельца</option><option value="fee">Комиссия</option><option value="other">Другое</option></select></label><label>Сумма<input type="number" min="0.01" step="0.01" name="amount" required></label></div><label>Дата и время<input type="datetime-local" name="occurred_at" value="<?=date('Y-m-d\TH:i')?>"></label><label>Категория<input name="category"></label><label>Комментарий<input name="description"></label><button class="btn primary">Добавить операцию</button></form></div>
<div class="card"><div class="chart-head"><div><h2>Перевод между счетами</h2><p>Например, инкассация кассы на расчётный счёт. Перевод не считается доходом или расходом бизнеса.</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="transfer"><label>Со счёта<select name="from_id"><?php foreach($accounts as $a):?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach;?></select></label><label>На счёт<select name="to_id"><?php foreach($accounts as $a):?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach;?></select></label><label>Сумма<input type="number" min="0.01" step="0.01" name="amount" required></label><label>Дата и время<input type="datetime-local" name="occurred_at" value="<?=date('Y-m-d\TH:i')?>"></label><label>Комментарий<input name="description"></label><button class="btn">Сохранить перевод</button></form></div>
</div>

<div class="card table-card section"><div class="chart-head"><div><h2>Движение денег</h2><p>Продажи из Эвотор синхронизируются автоматически и защищены от дублей.</p></div></div><table><thead><tr><th>Дата</th><th>Счёт</th><th>Операция</th><th>Категория</th><th>Сумма</th><th>Связанный счёт</th><th>Описание</th></tr></thead><tbody><?php foreach(array_slice($summary['rows'],0,300) as $r):?><tr><td><?=e(date('d.m.Y H:i',strtotime($r['occurred_at'])))?></td><td><?=e($r['account_name'])?></td><td><?=e($r['entry_type'])?></td><td><?=e((string)($r['category']??'—'))?></td><td><strong><?=$r['direction']==='in'?'+':'−'?><?=money((float)$r['amount'])?></strong></td><td><?=e((string)($r['counter_name']??'—'))?></td><td><?=e((string)($r['description']??'—'))?></td></tr><?php endforeach;?></tbody></table></div>
<div class="alert warning section"><strong>Сбер через Эвотор.</strong> Kapouch автоматически видит безналичную часть чеков как `ELECTRON`, но сам Эвотор не подтверждает фактический момент поступления денег на расчётный счёт. Поэтому «Сбер Эквайринг» используется как промежуточный счёт. Это защищает от двойного учёта продаж и банковских зачислений.</div>
<?php page_footer(); ?>