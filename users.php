<?php
require __DIR__.'/inc/bootstrap.php';
require_auth();
require __DIR__.'/inc/layout.php';

$roles=role_labels();$me=current_user();
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='create'){
            $name=trim((string)($_POST['name']??''));$email=mb_strtolower(trim((string)($_POST['email']??'')));$role=(string)($_POST['role']??'employee');$password=(string)($_POST['password']??'');
            if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!isset($roles[$role])||strlen($password)<8)throw new RuntimeException('Проверь имя, email, роль и пароль не короче 8 символов.');
            $stmt=db()->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,1)');$stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]);
            audit_write('user_created','Создан пользователь '.$email,'user',(string)db()->lastInsertId(),['role'=>$role]);flash('success','Пользователь создан.');
        }elseif($action==='update'){
            $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$role=(string)($_POST['role']??'employee');$active=isset($_POST['active'])?1:0;$password=(string)($_POST['password']??'');
            if(!$id||$name===''||!isset($roles[$role]))throw new RuntimeException('Некорректные данные пользователя.');
            if($id===(int)$me['id']&&(!$active||$role!=='owner'))throw new RuntimeException('Нельзя отключить собственный аккаунт владельца или снять с него роль владельца.');
            $owners=(int)db()->query("SELECT COUNT(*) FROM users WHERE role='owner' AND active=1")->fetchColumn();$old=db()->prepare('SELECT * FROM users WHERE id=?');$old->execute([$id]);$old=$old->fetch();
            if(!$old)throw new RuntimeException('Пользователь не найден.');
            if($old['role']==='owner'&&(int)$old['active']===1&&($role!=='owner'||!$active)&&$owners<=1)throw new RuntimeException('В системе должен остаться хотя бы один активный владелец.');
            if($password!==''){if(strlen($password)<8)throw new RuntimeException('Новый пароль должен быть не короче 8 символов.');$stmt=db()->prepare('UPDATE users SET name=?,role=?,active=?,password_hash=? WHERE id=?');$stmt->execute([$name,$role,$active,password_hash($password,PASSWORD_DEFAULT),$id]);}
            else{$stmt=db()->prepare('UPDATE users SET name=?,role=?,active=? WHERE id=?');$stmt->execute([$name,$role,$active,$id]);}
            audit_write('user_updated','Изменён пользователь '.$old['email'],'user',(string)$id,['old_role'=>$old['role'],'new_role'=>$role,'active'=>$active,'password_changed'=>$password!=='']);flash('success','Пользователь обновлён.');
        }
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('users.php');
}
$users=db()->query('SELECT id,name,email,role,active,created_at FROM users ORDER BY active DESC,role,name')->fetchAll();
page_header('Пользователи');
?>
<div class="two-col">
<div class="card"><div class="chart-head"><div><h2>Новый пользователь</h2><p>Доступ задаётся ролью и может быть отключён без удаления аккаунта.</p></div></div><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="create"><label>Имя<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Роль<select name="role"><?php foreach($roles as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach;?></select></label><label>Пароль<input type="password" name="password" minlength="8" required></label><button class="btn primary">Создать пользователя</button></form></div>
<div class="card"><div class="chart-head"><div><h2>Модель доступа</h2><p>Права фиксированы и предсказуемы.</p></div></div><div class="alerts"><div class="alert-item good"><span class="alert-dot"></span><div><strong>Владелец</strong><p>Полный доступ, пользователи, журнал, настройки и обновления.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Управляющий</strong><p>Операционное управление и аналитика без управления пользователями, журнала и обновлений.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Бухгалтер</strong><p>Финансы, экономика, отчёты, продажи, расходы, закупки и контроль качества.</p></div></div><div class="alert-item"><span class="alert-dot"></span><div><strong>Сотрудник</strong><p>Базовые операции: дашборд, касса, продажи, склад и закупки.</p></div></div></div></div>
</div>
<div class="card table-card section"><div class="chart-head"><div><h2>Аккаунты</h2><p><?=count($users)?> пользователей</p></div></div><table><thead><tr><th>Пользователь</th><th>Роль</th><th>Статус</th><th>Создан</th><th>Управление</th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><strong><?=e($u['name'])?></strong><br><span class="muted"><?=e($u['email'])?></span></td><td><?=e(role_label($u['role']))?></td><td><span class="pill <?=(int)$u['active']?'connected':''?>"><?=(int)$u['active']?'Активен':'Отключён'?></span></td><td><?=e(date('d.m.Y',strtotime($u['created_at'])))?></td><td><details><summary class="btn ghost">Изменить</summary><form method="post" class="stack" style="margin-top:10px;min-width:260px"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=$u['id']?>"><label>Имя<input name="name" value="<?=e($u['name'])?>" required></label><label>Роль<select name="role"><?php foreach($roles as $k=>$v):?><option value="<?=e($k)?>" <?=$u['role']===$k?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label><label style="display:flex;grid-template-columns:auto 1fr;align-items:center"><input type="checkbox" name="active" value="1" style="width:auto" <?=(int)$u['active']?'checked':''?>> Активен</label><label>Новый пароль <span class="muted">(необязательно)</span><input type="password" name="password" minlength="8"></label><button class="btn primary">Сохранить</button></form></details></td></tr><?php endforeach;?></tbody></table></div>
<?php page_footer(); ?>