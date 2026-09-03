<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

$migration = file_get_contents(__DIR__ . '/database/migrations/002_evotor.sql');
if ($migration !== false) db()->exec($migration);
require_once __DIR__ . '/inc/evotor.php';
require_once __DIR__ . '/inc/cash_register.php';
ensure_cash_register_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'connect') {
            $existingEnabled=(int)db()->query('SELECT COUNT(*) FROM evotor_connections WHERE enabled=1')->fetchColumn();
            if($existingEnabled>0) throw new RuntimeException('Эвотор уже подключён. Используйте кнопку «Редактировать» у токена.');
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
            flash('success','Эвотор подключён. Токен сохранён в зашифрованном виде.');
        }
        if ($action === 'update_token') {
            $id=(int)($_POST['connection_id']??0);
            $token=trim((string)($_POST['token']??''));
            if($id<=0) throw new RuntimeException('Подключение не найдено.');
            if($token==='') throw new RuntimeException('Введите новый токен Эвотор.');
            $connection=evotor_connection($id);
            if(!$connection) throw new RuntimeException('Подключение не найдено.');

            [$cipher,$iv,$tag]=evotor_encrypt_token($token);
            $temporary=[
                'token_ciphertext'=>$cipher,
                'token_iv'=>$iv,
                'token_tag'=>$tag,
                'store_id'=>(string)$connection['store_id'],
            ];
            $stores=evotor_request($temporary,'/stores');
            $items=$stores['items']??[];
            $storeFound=false;$storeName=(string)($connection['store_name']??'');
            foreach($items as $store){
                if((string)($store['id']??'')===(string)$connection['store_id']){
                    $storeFound=true;
                    $storeName=(string)($store['name']??$storeName);
                    break;
                }
            }
            if(!$storeFound) throw new RuntimeException('Новый токен не имеет доступа к подключённому магазину Эвотор. Токен не изменён.');

            $stmt=db()->prepare('UPDATE evotor_connections SET token_ciphertext=?,token_iv=?,token_tag=?,store_name=? WHERE id=? AND enabled=1');
            $stmt->execute([$cipher,$iv,$tag,$storeName?:null,$id]);
            flash('success','Токен Эвотор обновлён и проверен.');
        }
        if ($action === 'sync') {
            $id=(int)($_POST['connection_id']??0);
            $type=in_array($_POST['sync_type']??'',['products','documents','full'],true)?$_POST['sync_type']:'full';
            $connection=evotor_connection($id);
            if(!$connection) throw new RuntimeException('Подключение не найдено.');
            $result=evotor_run_sync($connection,$type);
            $cashProcessed=0;
            if($type==='full'||$type==='documents')$cashProcessed=sync_evotor_cash_register(evotor_connection($id)??$connection);
            $message='Синхронизация завершена. Обработано объектов: '.$result['processed'];
            if($cashProcessed)$message.=' · кассовых документов: '.$cashProcessed;
            if(!empty($result['cleaned'])) $message.=' · удалено дублей: '.$result['cleaned'];
            flash('success',$message);
        }
    } catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('integrations.php');
}

$connections=db()->query('SELECT * FROM evotor_connections WHERE enabled=1 ORDER BY id')->fetchAll();
$logs=db()->query('SELECT l.*,c.store_name,c.store_id FROM evotor_sync_log l LEFT JOIN evotor_connections c ON c.id=l.connection_id ORDER BY l.id DESC LIMIT 20')->fetchAll();
$isConnected=count($connections)>0;
$editTokenId=max(0,(int)($_GET['edit_token']??0));
page_header('Интеграции');
?>
<?php if(!$isConnected): ?>
<div class="card"><div class="chart-head"><div><h2>Подключить Эвотор</h2><p>Настройка выполняется один раз. После успешного подключения токен будет сохранён в зашифрованном виде.</p></div><span class="pill">Не подключено</span></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="connect"><label>Токен пользователя Эвотор<input type="password" name="token" autocomplete="new-password" required placeholder="Вставьте токен"></label><label>ID магазина <span class="muted">можно оставить пустым, если магазин один</span><input name="store_id" placeholder="UUID магазина"></label><div><button class="btn primary">Подключить Эвотор</button></div></form></div>
<?php endif; ?>

<?php foreach($connections as $c): ?>
<div class="card">
    <div class="integration-hero"><div><div class="eyebrow">Автоматическая интеграция</div><h2 style="font-size:24px;margin:5px 0 4px"><?=e($c['store_name'] ?: 'Мой магазин')?></h2><div class="muted"><?=e($c['store_id'])?></div></div><span class="pill connected">● Подключено</span></div>
    <div class="section">
        <div class="muted" style="font-size:12px;margin-bottom:7px">Токен Эвотор</div>
        <?php if($editTokenId===(int)$c['id']): ?>
            <form method="post" class="form-grid" style="align-items:end">
                <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                <input type="hidden" name="action" value="update_token">
                <input type="hidden" name="connection_id" value="<?=$c['id']?>">
                <label>Новый токен<input type="password" name="token" autocomplete="new-password" required placeholder="Вставьте новый токен Эвотор" autofocus></label>
                <div class="actions"><button class="btn primary">Сохранить токен</button><a class="btn ghost" href="integrations.php">Отмена</a></div>
            </form>
            <div class="muted" style="font-size:11px;margin-top:7px">Перед сохранением Kapouch проверит новый токен через Эвотор и убедится, что у него есть доступ к этому магазину.</div>
        <?php else: ?>
            <div class="token-box">••••••••••••••••••••</div>
            <div class="actions" style="margin-top:10px"><a class="btn ghost" href="integrations.php?edit_token=<?=$c['id']?>">Редактировать</a></div>
            <div class="muted" style="font-size:11px;margin-top:7px">Токен хранится зашифрованным и никогда не показывается на странице целиком.</div>
        <?php endif; ?>
    </div>
    <div class="sync-status"><div><small>Номенклатура</small><strong><?=$c['last_products_sync_ms']?e(date('d.m.Y H:i',(int)($c['last_products_sync_ms']/1000))):'ещё не синхронизировалась'?></strong></div><div><small>Чеки и возвраты</small><strong><?=$c['last_documents_sync_ms']?e(date('d.m.Y H:i',(int)($c['last_documents_sync_ms']/1000))):'ещё не синхронизировались'?></strong></div><div><small>Касса</small><strong><?=$c['last_cash_sync_ms']?e(date('d.m.Y H:i',(int)($c['last_cash_sync_ms']/1000))):'ещё не синхронизировалась'?></strong></div></div>
    <div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="connection_id" value="<?=$c['id']?>"><input type="hidden" name="sync_type" value="full"><button class="btn primary">↻ Синхронизировать всё</button></form><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="connection_id" value="<?=$c['id']?>"><input type="hidden" name="sync_type" value="documents"><button class="btn ghost">Новые чеки + касса</button></form><a class="btn ghost" href="cash.php">Открыть кассу</a></div>
</div>
<?php endforeach; ?>

<div class="card table-card section"><div class="chart-head"><div><h2>Журнал синхронизаций</h2><p>Последние обращения к Облаку Эвотор</p></div></div><table><thead><tr><th>Время</th><th>Тип</th><th>Статус</th><th>Обработано</th><th>Сообщение</th></tr></thead><tbody><?php foreach($logs as $log):?><tr><td><?=e(date('d.m.Y H:i',strtotime($log['finished_at'])))?></td><td><?=e($log['sync_type'])?></td><td><span class="pill <?=$log['status']==='success'?'connected':''?>"><?=e($log['status']==='success'?'Успешно':'Ошибка')?></span></td><td><?=e((string)$log['processed_count'])?></td><td><?=e((string)$log['message'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>