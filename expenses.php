<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
require_once __DIR__.'/inc/cash_flow.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $date = $_POST['spent_at'] ?? date('Y-m-d');
    $category = trim($_POST['category'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $accountId=(int)($_POST['cash_flow_account_id']??0);
    if ($category !== '' && $amount > 0) {
        $pdo=db();$pdo->beginTransaction();
        try{
            $stmt = $pdo->prepare('INSERT INTO expenses(spent_at,category,amount,description,cash_flow_account_id) VALUES(?,?,?,?,?)');
            $stmt->execute([$date,$category,$amount,$description,$accountId?:null]);$expenseId=(int)$pdo->lastInsertId();
            if($accountId>0){cashflow_add_entry(['occurred_at'=>$date.' 12:00:00','account_id'=>$accountId,'direction'=>'out','entry_type'=>'expense','amount'=>$amount,'source_type'=>'expense','source_id'=>(string)$expenseId,'category'=>$category,'description'=>$description?:'Расход Kapouch']);}
            $pdo->commit();flash('success','Расход добавлен'.($accountId>0?' и учтён в денежном потоке.':'.'));
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('danger','Не удалось добавить расход: '.$e->getMessage());}
    }
    redirect('expenses.php');
}

$accounts=array_values(array_filter(cashflow_accounts(true),fn($a)=>$a['account_type']!=='acquiring'));
$rows = db()->query('SELECT e.*,a.name cash_account_name FROM expenses e LEFT JOIN cash_flow_accounts a ON a.id=e.cash_flow_account_id ORDER BY e.spent_at DESC,e.id DESC LIMIT 200')->fetchAll();
page_header('Расходы');
?>
<div class="card"><h2>Добавить расход</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Дата<input type="date" name="spent_at" value="<?=date('Y-m-d')?>" required></label><label>Категория<input name="category" list="expense-categories" placeholder="Аренда, ФОТ, коммунальные..." required><datalist id="expense-categories"><option>Аренда</option><option>ФОТ</option><option>Коммунальные</option><option>Эквайринг</option><option>Маркетинг</option><option>Ремонт</option><option>Прочее</option></datalist></label><label>Сумма<input type="number" step="0.01" min="0" name="amount" required></label><label>Оплачено со счёта<select name="cash_flow_account_id"><option value="0">Не учитывать в Cash Flow</option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach;?></select></label><label>Комментарий<input name="description"></label><div><button class="btn primary">Добавить расход</button></div></form></div>
<div class="card section"><table><thead><tr><th>Дата</th><th>Категория</th><th>Описание</th><th>Денежный счёт</th><th>Сумма</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(date('d.m.Y',strtotime($r['spent_at'])))?></td><td><?=e($r['category'])?></td><td><?=e((string)$r['description'])?></td><td><?=e((string)($r['cash_account_name']??'—'))?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>