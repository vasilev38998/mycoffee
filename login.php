<?php
require __DIR__.'/inc/bootstrap.php';
if(current_user())redirect('index.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $email=mb_strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');$ip=kapouch_client_ip();
    $ipLimit=kapouch_rate_limit_hit('staff_login_ip',$ip,30,900);$accountLimit=kapouch_rate_limit_hit('staff_login_account',$ip.'|'.$email,10,900);
    if(!$ipLimit['allowed']||!$accountLimit['allowed'])$error='Слишком много попыток входа. Подождите несколько минут и попробуйте снова.';
    elseif(attempt_login($email,$password)){
        kapouch_rate_limit_reset('staff_login_ip',$ip);kapouch_rate_limit_reset('staff_login_account',$ip.'|'.$email);redirect('index.php');
    }else $error='Неверный email или пароль.';
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f0d0c">
<title>Kapouch — управление кофейней</title>
<meta name="description" content="Kapouch — единая система управления кофейней: продажи, склад, закупки, аналитика, онлайн-заказы, клиентское PWA и интеграции.">
<link rel="stylesheet" href="assets/style.css?v=20260903-6">
<link rel="stylesheet" href="assets/login.css?v=20260905-1">
</head>
<body class="auth-body">
<main class="login-shell">
  <section class="login-brand" aria-label="О проекте Kapouch">
    <a class="login-logo" href="login.php" aria-label="Kapouch"><span class="login-logo-mark">K</span><span class="login-logo-copy"><strong>Kapouch</strong><span>coffee management</span></span></a>
    <div class="login-hero">
      <div class="login-eyebrow">Управление кофейней в одном месте</div><h1>Кофейня под <span>контролем.</span></h1><p>Kapouch объединяет операционную работу, финансы и клиентский сервис. Продажи, остатки, закупки, себестоимость, онлайн-заказы и аналитика работают как одна система.</p>
      <div class="login-features" aria-label="Возможности Kapouch">
        <article class="login-feature"><div class="login-feature-icon">₽</div><strong>Финансы</strong><span>Выручка, расходы, прибыль, касса и план-факт.</span></article>
        <article class="login-feature"><div class="login-feature-icon">◫</div><strong>Склад</strong><span>Ингредиенты, закупки, остатки и себестоимость.</span></article>
        <article class="login-feature"><div class="login-feature-icon">↗</div><strong>Аналитика</strong><span>Динамика продаж, средний чек и контроль показателей.</span></article>
        <article class="login-feature"><div class="login-feature-icon">K</div><strong>Клиенты</strong><span>PWA, онлайн-заказы, бонусы, push и СБП.</span></article>
      </div>
    </div>
    <div class="login-trust"><span class="login-trust-item"><b>Evotor</b> синхронизация продаж</span><span class="login-trust-item"><b>QR-закупки</b> по кассовым чекам</span><span class="login-trust-item"><b>СБП</b> онлайн-оплата</span><span class="login-trust-item"><b>PWA</b> приложение для гостей</span></div>
  </section>
  <section class="login-side" aria-label="Вход в Kapouch"><div class="login-card"><div class="login-card-top"><div class="login-card-kicker">Панель управления</div><h2>С возвращением</h2><p class="login-card-subtitle">Войдите в рабочее пространство Kapouch.</p></div>
      <?php if(isset($_GET['installed'])):?><div class="alert success">Установка завершена. Войдите в систему.</div><?php endif;?>
      <?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Email<input type="email" name="email" autocomplete="username" required autofocus placeholder="name@example.ru"></label><label>Пароль<input type="password" name="password" autocomplete="current-password" required placeholder="Введите пароль"></label><button class="btn primary">Войти в Kapouch</button></form>
      <div class="login-security"><span class="login-security-icon">✓</span><span>Доступ к данным кофейни защищён авторизацией. Пароли и ключи интеграций не отображаются на этой странице.</span></div>
    </div></section>
</main>
</body>
</html>