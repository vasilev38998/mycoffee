<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();
require_once __DIR__ . '/inc/automatic_expenses.php';
ensure_automatic_expense_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $type = $_POST['rule_type'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $startsOn = $_POST['starts_on'] ?? date('Y-m-d');
        $endsOn = trim($_POST['ends_on'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($name !== '' && $category !== '' && in_array($type, ['per_shift','monthly_fixed','percent_revenue','percent_card_revenue'], true) && $amount >= 0) {
            $stmt = db()->prepare('INSERT INTO automatic_expense_rules(name,category,rule_type,amount,starts_on,ends_on,notes) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$name,$category,$type,$amount,$startsOn,$endsOn !== '' ? $endsOn : null,$notes !== '' ? $notes : null]);
            refresh_automatic_expenses(date('Y-m-01', strtotime($startsOn)), date('Y-m-d'));
            flash('success', 'Правило автоматического расхода добавлено.');
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('UPDATE automatic_expense_rules SET enabled = 1 - enabled WHERE id=?');
        $stmt->execute([$id]);
        refresh_automatic_expenses(date('Y-m-01'), date('Y-m-d'));
        flash('success', 'Статус правила изменён.');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM automatic_expense_rules WHERE id=?');
        $stmt->execute([$id]);
        flash('success', 'Правило удалено.');
    }

    redirect('automatic_expenses.php');
}

refresh_automatic_expenses(date('Y-m-01'), date('Y-m-d'));
$rules = db()->query('SELECT * FROM automatic_expense_rules ORDER BY enabled DESC, category, name')->fetchAll();
$recent = db()->query("SELECT a.*,r.name,r.category,r.rule_type FROM automatic_expense_accruals a JOIN automatic_expense_rules r ON r.id=a.rule_id ORDER BY a.accrual_date DESC,a.id DESC LIMIT 100")->fetchAll();
page_header('Автоматические расходы');
?>
<div class="card">
    <h2>Новое правило</h2>
    <p class="muted">Укажите расход один раз — дальше MyCoffee будет учитывать его автоматически в прибыли.</p>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="create">
        <label>Название<input name="name" placeholder="Зарплата бариста, аренда, эквайринг..." required></label>
        <label>Категория<input name="category" list="auto-expense-categories" required><datalist id="auto-expense-categories"><option>ФОТ</option><option>Аренда</option><option>Интернет</option><option>Обслуживание кассы</option><option>Эквайринг</option><option>Налоги</option><option>Коммунальные</option><option>Прочее</option></datalist></label>
        <label>Как начислять<select name="rule_type" required><option value="per_shift">Фиксированно за каждую смену</option><option value="monthly_fixed">Фиксированно в месяц</option><option value="percent_revenue">Процент от всей выручки</option><option value="percent_card_revenue">Процент от безналичной выручки</option></select></label>
        <label>Сумма / процент<input type="number" step="0.0001" min="0" name="amount" required><span class="muted">Для процентных правил укажите, например, 2.2. Для остальных — сумму в рублях.</span></label>
        <label>Действует с<input type="date" name="starts_on" value="<?=date('Y-m-01')?>" required></label>
        <label>Действует по <span class="muted">(необязательно)</span><input type="date" name="ends_on"></label>
        <label>Комментарий<input name="notes" placeholder="Например: ставка бариста за 12-часовую смену"></label>
        <div><button class="btn primary">Добавить правило</button></div>
    </form>
</div>

<div class="card section">
    <h2>Действующие правила</h2>
    <table><thead><tr><th>Название</th><th>Категория</th><th>Начисление</th><th>Значение</th><th>Период</th><th>Статус</th><th></th></tr></thead><tbody>
    <?php foreach($rules as $r): ?>
        <tr><td><?=e($r['name'])?></td><td><?=e($r['category'])?></td><td><?=e(automatic_rule_label($r['rule_type']))?></td><td><?=in_array($r['rule_type'],['percent_revenue','percent_card_revenue'],true) ? e((string)$r['amount']).'%' : money((float)$r['amount'])?></td><td><?=e(date('d.m.Y',strtotime($r['starts_on'])))?><?=!empty($r['ends_on']) ? ' — '.e(date('d.m.Y',strtotime($r['ends_on']))) : ''?></td><td><?=$r['enabled'] ? 'Активно' : 'Выключено'?></td><td><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn"><?=$r['enabled'] ? 'Выключить' : 'Включить'?></button></form><form method="post" onsubmit="return confirm('Удалить правило?')"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn">Удалить</button></form></div></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div>

<div class="card section">
    <h2>Последние автоматические начисления</h2>
    <table><thead><tr><th>Дата</th><th>Расход</th><th>Категория</th><th>Основание</th><th>Сумма</th></tr></thead><tbody>
    <?php foreach($recent as $a): ?>
        <tr><td><?=e(date('d.m.Y',strtotime($a['accrual_date'])))?></td><td><?=e($a['name'])?></td><td><?=e($a['category'])?></td><td><?=e((string)$a['basis_description'])?><?=!empty($a['shift_key']) ? ' · смена '.e($a['shift_key']) : ''?></td><td><?=money((float)$a['amount'])?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
<?php page_footer(); ?>