<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

$migration = file_get_contents(__DIR__ . '/database/migrations/002_evotor.sql');
if ($migration !== false) db()->exec($migration);
require_once __DIR__ . '/inc/evotor.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'connect') {
            $existingEnabled=(int)db()->query('SELECT COUNT(*) FROM evotor_connections WHERE enabled=1')->fetchColumn();
            if($existingEnabled>0) throw new RuntimeException('Эвотор уже подключён. Повторный ввод токена не требуется.');
            $token = trim((string)($_POST['token'] ?? ''));
            $storeId = trim((string)($_POST['store_id'] ?? ''));
            if ($token === '') throw new RuntimeException('Введите токен Эвотор.');
            [$cipher, $iv, $tag] = evotor_encrypt_token($token);
            $temporary = ['token_ciphertext'=>$cipher,'token_iv'=>$iv,'token_tag'=>$tag,'store_id'=>$storeId];
            $storeName = null;
            $stores = evotor_request($temporary, '/stores');
            $items = $stores['items'] ?? [];
            if ($storeId === '') {
                if (count($items) === 1) {
                    $storeId=(string)($items[0]['id']??'');
                    $storeName=(string)($items[0]['name']??'');
                } elseif (count($items)>1) {
                    $available=array_map(fn($s)=>(($s['name']??'Магазин').' — '.($s['id']??'')),$items);
                    throw new RuntimeException('У токена несколько магазинов. Укажите ID нужного магазина: '.implode('; ',$available));
                } else throw new RuntimeException('Эвотор не вернул доступных магазинов для этого токена.');
            } else {
                foreach($items as $store){if(($store['id']??'')===$storeId){$storeName=(string)($store['name']??'');break;}}
            }
            if($storeId==='') throw new RuntimeException('Не удалось определить магазин Эвотор.');
            $stmt=db()->prepare('INSERT INTO evotor_connections(store_id,store_name,token_ciphertext,token_iv,token_tag,enabled) VALUES(?,?,?,?,?,1) ON DUPLICATE KEY UPDATE store_name=VALUES(store_name),token_ciphertext=VALUES(token_ciphertext),token_iv=VALUES(token_iv),token_tag=VALUES(token_tag),enabled=1');
            $stmt->execute([$storeId,$storeName?:null,$cipher,$iv,$tag]);
            flash('success','Эвотор подключён. Токен сохранён в зашифрованном виде. Повторно вводить его не нужно.');
        }
        if ($action === 'sync') {
            $id=(int)($_POST['connection_id']??0);
            $type=in_array($_POST['sync_type']??'',['products','documents','full'],true)?$_POST['sync_type']:'full';
            $connection=evotor_connection($id);
            if(!$connection) throw new RuntimeException('Подключение не найдено.');
            $result=evotor_run_sync($connection,$type);
            $message='Синхронизация завершена. Обработано объектов: '.$result['processed'];
            if(!empty($result['cleaned'])) $message.=' · удалено дублей: '.$result['cleaned'];
            flash('success',$message);
        }
    } catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('integrations.php');
}

$connections=db()->query('SELECT * FROM evotor_connections WHERE enabled=1 ORDER BY id')->fetchAll();
$logs=db()->query('SELECT l.*,c.store_name,c.store_id FROM evotor_sync_log l LEFT JOIN evotor_connections c ON c.id=l.connection_id ORDER BY l.id DESC LIMIT 20')->fetchAll();
$isConnected=count($connections)>0;
page_header('Интеграции');
?>
<?php if(!$isConnected): ?>
<div class="card"><div class="chart-head"><div><h2>Подключить Эвотор</h2><p>Настройка выполняется один раз. После успешного подключения токен будет сохранён в зашифрованном виде.</p></div><span class="pill">Не подключено</span></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="connect"><label>Токен пользователя Эвотор<input type="password" name="token" autocomplete="off" required placeholder="Вставьте токен"></label><label>ID магазина <span class="muted">можно оставить пустым, если магазин один</span><input name="store_id" placeholder="UUID магазина"></label><div><button class="btn primary">Подключить Эвотор</button></div></form></div>
<?php endif; ?>

<?php foreach($connections as $c): ?>
<div class="card">
    <div class="integration-hero"><div><div class="eyebrow">Автоматическая интеграция</div><h2 style="font-size:24px;margin:5px 0 4px"><?=e($c['store_name'] ?: 'Мой магазин')?></h2><div class="muted"><?=e($c['store_id'])?></div></div><span class="pill connected">● Подключено</span></div>
    <div class="section"><div class="muted" style="font-size:12px;margin-bottom:7px">Токен Эвотор</div><div class="token-box">••••••••••••••••••••</div><div class="muted" style="font-size:11px;margin-top:7px">Токен закреплён за интеграцией и хранится зашифрованным. Повторный ввод не требуется.</div></div>
    <div class="sync-status"><div><small>Номенклатура</small><strong><?=$c['last_products_sync_ms']?e(date('d.m.Y H:i',(int)($c['last_products_sync_ms']/1000))):'ещё не синхронизировалась'?></strong></div><div><small>Чеки и возвраты</small><strong><?=$c['last_documents_sync_ms']?e(date('d.m.Y H:i',(int)($c['last_documents_sync_ms']/1000))):'ещё не синхронизировались'?></strong></div></div>
    <div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="connection_id" value="<?=$c['id']?>"><input type="hidden" name="sync_type" value="full"><button class="btn primary">↻ Синхронизировать всё</button></form><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="connection_id" value="<?=$c['id']?>"><input type="hidden" name="sync_type" value="documents"><button class="btn ghost">Только новые чеки</button></form></div>
</div>
<?php endforeach; ?>

<div class="card table-card section"><div class="chart-head"><div><h2>Журнал синхронизаций</h2><p>Последние обращения к Облаку Эвотор</p></div></div><table><thead><tr><th>Время</th><th>Тип</th><th>Статус</th><th>Обработано</th><th>Сообщение</th></tr></thead><tbody><?php foreach($logs as $log):?><tr><td><?=e(date('d.m.Y H:i',strtotime($log['finished_at'])))?></td><td><?=e($log['sync_type'])?></td><td><span class="pill <?=$log['status']==='success'?'connected':''?>"><?=e($log['status']==='success'?'Успешно':'Ошибка')?></span></td><td><?=e((string)$log['processed_count'])?></td><td><?=e((string)$log['message'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>