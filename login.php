<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) redirect('index.php');
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    if (attempt_login(mb_strtolower(trim($_POST['email'] ?? '')), (string)($_POST['password'] ?? ''))) redirect('index.php');
    $error='Неверный email или пароль.';
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход — Kapouch</title><link rel="stylesheet" href="assets/style.css?v=20260903-6"></head><body class="auth-body"><div class="auth-card"><div style="font-size:22px;font-weight:800;margin-bottom:20px">Kapouch</div><h1>Вход</h1><?php if(isset($_GET['installed'])):?><div class="alert success">Установка завершена. Войдите в систему.</div><?php endif;?><?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Email<input type="email" name="email" required autofocus></label><label>Пароль<input type="password" name="password" required></label><button class="btn primary">Войти</button></form></div></body></html>