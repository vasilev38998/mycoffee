<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require __DIR__.'/inc/layout.php';
$rows=audit_recent(200);
page_header('Журнал действий');
?>
<div class="card"><div class="chart-head"><div><h2>История изменений</h2><p>Последние <?=count($rows)?> действий пользователей. Чувствительные поля автоматически скрываются.</p></div></div><div class="alert-item good"><span class="alert-dot"></span><div><strong>Журнал нельзя использовать как хранилище секретов</strong><p>Пароли, токены, CSRF и похожие поля заменяются на «[скрыто]» ещё до записи.</p></div></div></div>
<div class="card table-card section"><table><thead><tr><th>Время</th><th>Пользователь</th><th>Роль</th><th>Действие</th><th>Раздел</th><th>Описание</th><th>Контекст</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(date('d.m.Y H:i:s',strtotime($r['created_at'])))?></td><td><?=e($r['user_name']??'Система')?></td><td><?=e($r['user_role']?role_label($r['user_role']):'—')?></td><td><code><?=e($r['action'])?></code></td><td><?=e($r['request_path']??'—')?></td><td><?=e($r['description']??'—')?></td><td><?php if($r['context_json']):?><details><summary class="btn ghost">Показать</summary><pre style="white-space:pre-wrap;max-width:480px;font-size:10px"><?=e(json_encode(json_decode($r['context_json'],true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></details><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>