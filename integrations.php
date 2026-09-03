<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

// Миграция выполняется идемпотентно, поэтому страница работает и после обновления существующей установки.
$migration = file_get_contents(__DIR__ . '/database/migrations/002_evotor.sql');
if ($migration !== false) db()->exec($migration);

require_once __DIR__ . '/inc/evotor.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'connect') {
            $token = trim((string)($_POST['token'] ?? ''));
            $storeId = trim((string)($_POST['store_id'] ?? ''));
            if ($token === '') throw new RuntimeException('Введите токен Эвотор.');

            [$cipher, $iv, $tag] = evotor_encrypt_token($token);
            $temporary = [
                'token_ciphertext' => $cipher,
                'token_iv' => $iv,
                'token_tag' => $tag,
                'store_id' => $storeId,
            ];

            $storeName = null;
            if ($storeId === '') {
                $stores = evotor_request($temporary, '/stores');
                $items = $stores['items'] ?? [];
                if (count($items) === 1) {
                    $storeId = (string)($items[0]['id'] ?? '');
                    $storeName = (string)($items[0]['name'] ?? '');
                } elseif (count($items) > 1) {
                    $available = array_map(fn($s) => (($s['name'] ?? 'Магазин') . ' — ' . ($s['id'] ?? '')), $items);
                    throw new RuntimeException('У токена несколько магазинов. Укажите ID нужного магазина: ' . implode('; ', $available));
                } else {
                    throw new RuntimeException('Эвотор не вернул доступных магазинов для этого токена.');
                }
            } else {
                $stores = evotor_request($temporary, '/stores');
                foreach (($stores['items'] ?? []) as $store) {
                    if (($store['id'] ?? '') === $storeId) {
                        $storeName = (string)($store['name'] ?? '');
                        break;
                    }
                }
            }

            if ($storeId === '') throw new RuntimeException('Не удалось определить магазин Эвотор.');

            $stmt = db()->prepare('INSERT INTO evotor_connections(store_id,store_name,token_ciphertext,token_iv,token_tag,enabled) VALUES(?,?,?,?,?,1) ON DUPLICATE KEY UPDATE store_name=VALUES(store_name),token_ciphertext=VALUES(token_ciphertext),token_iv=VALUES(token_iv),token_tag=VALUES(token_tag),enabled=1');
            $stmt->execute([$storeId, $storeName ?: null, $cipher, $iv, $tag]);
            flash('success', 'Эвотор подключён. Теперь можно запустить первую синхронизацию.');
        }

        if ($action === 'sync') {
            $id = (int)($_POST['connection_id'] ?? 0);
            $type = in_array($_POST['sync_type'] ?? '', ['products','documents','full'], true) ? $_POST['sync_type'] : 'full';
            $connection = evotor_connection($id);
            if (!$connection) throw new RuntimeException('Подключение не найдено.');
            $result = evotor_run_sync($connection, $type);
            flash('success', 'Синхронизация завершена. Обработано объектов: ' . $result['processed']);
        }

        if ($action === 'disable') {
            $id = (int)($_POST['connection_id'] ?? 0);
            $stmt = db()->prepare('UPDATE evotor_connections SET enabled=0 WHERE id=?');
            $stmt->execute([$id]);
            flash('success', 'Интеграция отключена. Данные не удалены.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('integrations.php');
}

$connections = db()->query('SELECT * FROM evotor_connections ORDER BY id')->fetchAll();
$logs = db()->query('SELECT l.*,c.store_name,c.store_id FROM evotor_sync_log l LEFT JOIN evotor_connections c ON c.id=l.connection_id ORDER BY l.id DESC LIMIT 20')->fetchAll();
page_header('Интеграции');
?>
<div class="card">
    <h2>Эвотор</h2>
    <p class="muted">Продажи и возвраты будут автоматически загружаться из Облака Эвотор. Токен хранится в базе в зашифрованном виде и не попадает в GitHub.</p>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="connect">
        <label>Токен пользователя Эвотор<input type="password" name="token" autocomplete="off" required></label>
        <label>ID магазина <span class="muted">(можно оставить пустым, если магазин один)</span><input name="store_id" placeholder="UUID магазина"></label>
        <div><button class="btn primary">Подключить Эвотор</button></div>
    </form>
</div>

<?php foreach($connections as $c): ?>
<div class="card section">
    <div class="actions" style="justify-content:space-between;align-items:center">
        <div><h2 style="margin:0"><?=e($c['store_name'] ?: 'Магазин Эвотор')?></h2><div class="muted"><?=e($c['store_id'])?></div></div>
        <span class="pill"><?=$c['enabled'] ? 'Подключено' : 'Отключено'?></span>
    </div>
    <p>Последняя синхронизация товаров: <strong><?=$c['last_products_sync_ms'] ? e(date('d.m.Y H:i:s', (int)($c['last_products_sync_ms']/1000))) : 'ещё не выполнялась'?></strong><br>
    Последняя синхронизация документов: <strong><?=$c['last_documents_sync_ms'] ? e(date('d.m.Y H:i:s', (int)($c['last_documents_sync_ms']/1000))) : 'ещё не выполнялась'?></strong></p>
    <?php if($c['enabled']): ?>
    <div class="actions">
        <form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="connection_id" value="<?=$c['id']?>"><input type="hidden" name="sync_type" value="full"><button class="btn primary">Синхронизировать всё</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="connection_id" value="<?=$c['id']?>"><input type="hidden" name="sync_type" value="documents"><button class="btn">Только новые чеки</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="disable"><input type="hidden" name="connection_id" value="<?=$c['id']?>"><button class="btn">Отключить</button></form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card section">
    <h2>Журнал синхронизаций</h2>
    <table><thead><tr><th>Время</th><th>Тип</th><th>Статус</th><th>Обработано</th><th>Сообщение</th></tr></thead><tbody>
    <?php foreach($logs as $log): ?><tr><td><?=e(date('d.m.Y H:i',strtotime($log['finished_at'])))?></td><td><?=e($log['sync_type'])?></td><td><?=e($log['status'])?></td><td><?=e((string)$log['processed_count'])?></td><td><?=e((string)$log['message'])?></td></tr><?php endforeach; ?>
    </tbody></table>
</div>
<?php page_footer(); ?>