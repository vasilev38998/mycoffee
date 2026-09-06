<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_auth();

$migration = file_get_contents(__DIR__ . '/database/migrations/002_evotor.sql');
if ($migration !== false) db()->exec($migration);
require_once __DIR__ . '/inc/evotor.php';
require_once __DIR__ . '/inc/evotor_order_notifications.php';
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
        if ($action === 'save_order_push') {
            $id=(int)($_POST['connection_id']??0);
            $saved=evotor_order_push_save($id,[
                'enabled'=>isset($_POST['push_enabled']),
                'application_id'=>(string)($_POST['push_application_id']??''),
                'device_uuid'=>(string)($_POST['push_device_uuid']??''),
                'publisher_token'=>(string)($_POST['push_publisher_token']??''),
            ]);
            audit_write('evotor_order_push_settings',!empty($saved['push_enabled'])?'Включены уведомления о PWA-заказах на Эвотор':'Выключены уведомления о PWA-заказах на Эвотор','evotor_connection',(string)$id);
            flash('success',!empty($saved['push_enabled'])?'Уведомления новых заказов на экран Эвотор включены.':'Уведомления новых заказов на экран Эвотор выключены. Настройки сохранены.');
        }
        if ($action === 'test_order_push') {
            $id=(int)($_POST['connection_id']??0);
            evotor_order_push_test($id);
            audit_write('evotor_order_push_test','Отправлено тестовое уведомление на Эвотор','evotor_connection',(string)$id);
            flash('success','Тестовое уведомление принято Облаком Эвотор. Проверьте экран терминала.');
        }
        if ($action === 'retry_order_push') {
            $result=evotor_order_push_retry_pending(20);
            flash('success','Повторная отправка: обработано '.$result['processed'].', успешно отправлено '.$result['sent'].'.');
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

<?php foreach($connections as $c): $pushReady=evotor_order_push_ready($c);$pushLogs=evotor_order_push_recent((int)$c['id'],10); ?>
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

    <div class="section" style="border-top:1px solid rgba(255,255,255,.08);padding-top:22px">
        <div class="chart-head"><div><h2>Новые PWA-заказы на экране Эвотор</h2><p>Kapouch отправляет адресный push в приложение Kapouch Orders на выбранном смарт-терминале. Наличный заказ — сразу после оформления; СБП — только после подтверждённой оплаты.</p></div><span class="pill <?=!empty($c['push_enabled'])&&$pushReady?'connected':''?>"><?=!empty($c['push_enabled'])&&$pushReady?'● Включено':($pushReady?'Настроено · выключено':'Нужно настроить')?></span></div>
        <form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_order_push"><input type="hidden" name="connection_id" value="<?=$c['id']?>">
            <label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="push_enabled" value="1" style="width:auto" <?=!empty($c['push_enabled'])?'checked':''?>> Отправлять уведомления о новых заказах на экран Эвотор</label>
            <div class="form-grid">
                <label>Application ID приложения Kapouch Orders<input name="push_application_id" maxlength="64" value="<?=e((string)($c['push_application_id']??''))?>" placeholder="xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx"></label>
                <label>UUID терминала или IMEI<input name="push_device_uuid" maxlength="100" value="<?=e((string)($c['push_device_uuid']??''))?>" placeholder="UUID устройства или 15-значный IMEI"></label>
                <label>Ключ издателя Эвотор <span class="muted"><?=!empty($c['push_token_ciphertext'])?'уже сохранён — оставьте пустым, чтобы не менять':''?></span><input type="password" name="push_publisher_token" autocomplete="new-password" placeholder="Ключ с правом push-notification:write"></label>
            </div>
            <div class="alert info"><strong>Нужен отдельный ключ издателя.</strong> Обычный пользовательский токен синхронизации выше не заменяет его. Для ключа издателя требуется право <code>push-notification:write</code>. Сам ключ хранится зашифрованным и после сохранения не показывается.</div>
            <?php if(!empty($c['push_last_error'])):?><div class="alert danger"><strong>Последняя ошибка отправки:</strong> <?=e((string)$c['push_last_error'])?></div><?php elseif(!empty($c['push_last_sent_at'])):?><div class="alert success"><strong>Последняя успешная отправка:</strong> <?=e(date('d.m.Y H:i:s',strtotime((string)$c['push_last_sent_at'])))?></div><?php endif;?>
            <div class="actions"><button class="btn primary">Сохранить уведомления Эвотор</button></div>
        </form>
        <div class="actions" style="margin-top:10px">
            <form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="test_order_push"><input type="hidden" name="connection_id" value="<?=$c['id']?>"><button class="btn ghost" <?=$pushReady?'':'disabled'?>>Отправить тест на экран</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="retry_order_push"><button class="btn ghost">Повторить недоставленные</button></form>
        </div>
        <p class="muted" style="font-size:11px;margin-top:10px">В самом приложении Kapouch Orders на терминале будет второй независимый переключатель. Даже если серверная отправка включена, бариста сможет временно скрыть уведомления на конкретном Эвоторе.</p>
        <?php if($pushLogs):?><div class="table-wrap" style="margin-top:16px"><table><thead><tr><th>Время</th><th>Заказ</th><th>Статус</th><th>Попыток</th><th>Ошибка</th></tr></thead><tbody><?php foreach($pushLogs as $pushLog):?><tr><td><?=e(date('d.m.Y H:i:s',strtotime((string)$pushLog['created_at'])))?></td><td><?=e((string)$pushLog['order_number'])?></td><td><span class="pill <?=$pushLog['status']==='sent'?'connected':''?>"><?=e((string)$pushLog['status'])?></span></td><td><?=(int)$pushLog['attempts']?></td><td><?=e((string)($pushLog['last_error']??''))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
</div>
<?php endforeach; ?>

<div class="card table-card section"><div class="chart-head"><div><h2>Журнал синхронизаций</h2><p>Последние обращения к Облаку Эвотор</p></div></div><table><thead><tr><th>Время</th><th>Тип</th><th>Статус</th><th>Обработано</th><th>Сообщение</th></tr></thead><tbody><?php foreach($logs as $log):?><tr><td><?=e(date('d.m.Y H:i',strtotime($log['finished_at'])))?></td><td><?=e($log['sync_type'])?></td><td><span class="pill <?=$log['status']==='success'?'connected':''?>"><?=e($log['status']==='success'?'Успешно':'Ошибка')?></span></td><td><?=e((string)$log['processed_count'])?></td><td><?=e((string)$log['message'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>