<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $date = $_POST['spent_at'] ?? date('Y-m-d');
    $category = trim($_POST['category'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    if ($category !== '' && $amount > 0) {
        $stmt = db()->prepare('INSERT INTO expenses(spent_at,category,amount,description) VALUES(?,?,?,?)');
        $stmt->execute([$date,$category,$amount,$description]);
        flash('success','Расход добавлен.');
    }
    redirect('expenses.php');
}

$rows = db()->query('SELECT * FROM expenses ORDER BY spent_at DESC,id DESC LIMIT 200')->fetchAll();
page_header('Расходы');
?>
<div class="card"><h2>Добавить расход</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Дата<input type="date" name="spent_at" value="<?=date('Y-m-d')?>" required></label><label>Категория<input name="category" list="expense-categories" placeholder="Аренда, ФОТ, коммунальные..." required><datalist id="expense-categories"><option>Аренда</option><option>ФОТ</option><option>Коммунальные</option><option>Эквайринг</option><option>Маркетинг</option><option>Ремонт</option><option>Прочее</option></datalist></label><label>Сумма<input type="number" step="0.01" min="0" name="amount" required></label><label>Комментарий<input name="description"></label><div><button class="btn primary">Добавить расход</button></div></form></div>
<div class="card section"><table><thead><tr><th>Дата</th><th>Категория</th><th>Описание</th><th>Сумма</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(date('d.m.Y',strtotime($r['spent_at'])))?></td><td><?=e($r['category'])?></td><td><?=e((string)$r['description'])?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>