<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require_once __DIR__.'/inc/budget.php';
require __DIR__.'/inc/layout.php';

$month=$_GET['month']??date('Y-m');$monthStart=budget_month_start($month);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $monthStart=budget_month_start((string)($_POST['month']??date('Y-m')));
        $id=budget_save($monthStart,(float)($_POST['revenue_plan']??0),(float)($_POST['profit_plan']??0),(float)($_POST['purchases_plan']??0),trim((string)($_POST['notes']??'')),(array)($_POST['category']??[]),(array)($_POST['planned_amount']??[]));
        audit_write('budget_saved','Сохранён бюджет на '.date('m.Y',strtotime($monthStart)),'monthly_budget',(string)$id);
        flash('success','Бюджет сохранён. План-факт пересчитан.');
    }catch(Throwable $e){flash('danger','Не удалось сохранить бюджет: '.$e->getMessage());}
    redirect('budget.php?month='.date('Y-m',strtotime($monthStart)));
}
$pf=budget_plan_fact($monthStart);$budget=$pf['budget'];$lines=budget_expense_lines((int)$budget['id']);$risks=budget_risks($pf);
$revChange=(float)($_GET['revenue_change']??-10);$expenseChange=(float)($_GET['expense_change']??10);$scenario=budget_scenario($pf,$revChange,$expenseChange);
$knownCats=db()->query("SELECT DISTINCT category FROM expenses WHERE category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
page_header('Бюджет и план-факт');
?>
<div class="card filter-card"><form method="get" style="display:contents"><label>Месяц<input type="month" name="month" value="<?=e(substr($monthStart,0,7))?>"></label><div class="period-note">Прошло <?=$pf['elapsed_days']?> из <?=$pf['days_total']?> дней · <?=number_format($pf['progress']*100,0,',',' ')?>% месяца</div><button class="btn primary">Показать</button></form></div>

<div class="grid section">
<div class="card metric"><div class="label">Выручка: факт / план</div><div class="value"><?=money($pf['fact']['metrics']['revenue'])?></div><div class="meta">План <?=money((float)$budget['revenue_plan'])?> · прогноз <?=money($pf['forecast_revenue'])?></div></div>
<div class="card metric"><div class="label">Прибыль: факт / план</div><div class="value"><?=money($pf['fact']['metrics']['operating_profit'])?></div><div class="meta">План <?=money((float)$budget['profit_plan'])?> · прогноз <?=money($pf['forecast_profit'])?></div></div>
<div class="card metric"><div class="label">Закупки: факт / план</div><div class="value"><?=money($pf['fact']['purchases'])?></div><div class="meta">План <?=money((float)$budget['purchases_plan'])?> · прогноз <?=money($pf['forecast_purchases'])?></div></div>
<div class="card metric"><div class="label">Риски бюджета</div><div class="value"><?=count($risks)?></div><div class="meta"><?=count(array_filter($risks,fn($r)=>$r['severity']==='critical'))?> критичных</div></div>
</div>

<?php if($risks):?><div class="card section"><div class="chart-head"><div><h2>Ранние сигналы</h2><p>Kapouch сравнивает текущий темп расходов и выручки с бюджетом месяца.</p></div></div><div class="alerts"><?php foreach($risks as $r):?><div class="alert-item <?=$r['severity']==='critical'?'bad':'warn'?>"><span class="alert-dot"></span><div><strong><?=e($r['title'])?></strong><p><?=e($r['message'])?></p></div></div><?php endforeach;?></div></div><?php endif;?>

<div class="two-col section">
<div class="card"><div class="chart-head"><div><h2>Бюджет месяца</h2><p>Фиксируем цели, чтобы план не менялся вместе с фактом.</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="month" value="<?=e(substr($monthStart,0,7))?>"><div class="form-grid"><label>План выручки<input type="number" min="0" step="100" name="revenue_plan" value="<?=e((string)$budget['revenue_plan'])?>"></label><label>План прибыли<input type="number" step="100" name="profit_plan" value="<?=e((string)$budget['profit_plan'])?>"></label><label>План закупок<input type="number" min="0" step="100" name="purchases_plan" value="<?=e((string)$budget['purchases_plan'])?>"></label></div><label>Комментарий<textarea name="notes" rows="2"><?=e((string)($budget['notes']??''))?></textarea></label><div class="chart-head"><div><h3>Бюджет расходов по категориям</h3><p>Факт берётся из ручных расходов с теми же названиями категорий.</p></div></div><div id="budget-lines"><?php $displayLines=$lines?:[['category'=>'','planned_amount'=>'']];foreach($displayLines as $line):?><div class="form-grid budget-line"><label>Категория<input name="category[]" value="<?=e((string)$line['category'])?>" list="expense-categories"></label><label>План<input type="number" min="0" step="100" name="planned_amount[]" value="<?=e((string)$line['planned_amount'])?>"></label></div><?php endforeach;?></div><datalist id="expense-categories"><?php foreach($knownCats as $cat):?><option value="<?=e((string)$cat)?>"><?php endforeach;?></datalist><button type="button" class="btn ghost" onclick="addBudgetLine()">+ Добавить категорию</button><button class="btn primary">Сохранить бюджет</button></form></div>
<div class="card"><div class="chart-head"><div><h2>Стресс-сценарий</h2><p>Что будет с прогнозом при изменении выручки и расходов.</p></div></div><form method="get" class="stack"><input type="hidden" name="month" value="<?=e(substr($monthStart,0,7))?>"><label>Изменение выручки, %<input type="number" step="1" name="revenue_change" value="<?=e((string)$revChange)?>"></label><label>Изменение расходов, %<input type="number" step="1" name="expense_change" value="<?=e((string)$expenseChange)?>"></label><button class="btn">Пересчитать сценарий</button></form><div class="section insight-card"><div class="kicker">Сценарная выручка</div><strong><?=money($scenario['revenue'])?></strong><p><?=$revChange>=0?'+':''?><?=number_format($revChange,0,',',' ')?>% к текущему прогнозу</p></div><div class="section insight-card"><div class="kicker">Сценарная прибыль</div><strong><?=money($scenario['profit'])?></strong><p>Маржа <?=number_format($scenario['margin'],1,',',' ')?>% при изменении расходов на <?=$expenseChange>=0?'+':''?><?=number_format($expenseChange,0,',',' ')?>%</p></div></div>
</div>

<div class="card table-card section"><div class="chart-head"><div><h2>План-факт расходов</h2><p>Burn rate показывает, сколько бюджета уже израсходовано; прогноз — куда придём к концу месяца при текущем темпе.</p></div></div><table><thead><tr><th>Категория</th><th>План</th><th>Факт</th><th>Burn rate</th><th>Прогноз</th><th>Прогноз / план</th></tr></thead><tbody><?php foreach($pf['expense_rows'] as $r):?><tr><td><strong><?=e($r['category'])?></strong></td><td><?=$r['plan']>0?money($r['plan']):'Не задан'?></td><td><?=money($r['actual'])?></td><td><?=$r['used_pct']===null?'—':number_format($r['used_pct'],0,',',' ').'%'?></td><td><?=money($r['forecast'])?></td><td><?php if($r['forecast_pct']===null):?><span class="pill">Вне бюджета</span><?php else:?><span class="pill <?=$r['forecast_pct']<=100?'connected':''?>"><?=number_format($r['forecast_pct'],0,',',' ')?>%</span><?php endif;?></td></tr><?php endforeach;?></tbody></table><?php if(!$pf['expense_rows']):?><p class="muted">Добавь категории расходов в бюджет — Kapouch начнёт отслеживать их автоматически.</p><?php endif;?></div>

<div class="alert warning section"><strong>Ограничение прогноза.</strong> Forecast использует текущий среднедневной темп месяца. Он не знает заранее о разовых платежах в будущем, если они ещё не внесены в расходы. Поэтому бюджет — план, а прогноз — ранний индикатор, а не обещание конечного результата.</div>
<script>function addBudgetLine(){var box=document.getElementById('budget-lines');var row=document.createElement('div');row.className='form-grid budget-line';row.innerHTML='<label>Категория<input name="category[]" list="expense-categories"></label><label>План<input type="number" min="0" step="100" name="planned_amount[]"></label>';box.appendChild(row);}</script>
<?php page_footer(); ?>