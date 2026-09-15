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
<meta name="theme-color" content="#120d0a">
<title>Kapouch — управление кофейней</title>
<meta name="description" content="Kapouch — единая система управления кофейней: продажи, склад, закупки, аналитика, онлайн-заказы, клиентское PWA и интеграции.">
<link rel="stylesheet" href="assets/style.css?v=20260903-6">
<link rel="stylesheet" href="assets/login.css?v=20260905-1">
<link rel="stylesheet" href="assets/login-v2.css?v=20260915-1">
</head>
<body class="auth-body">
<main class="login-shell">
  <section class="login-brand" aria-label="О проекте Kapouch">
    <div class="login-topline">
      <a class="login-logo" href="login.php" aria-label="Kapouch"><span class="login-logo-mark">K</span><span class="login-logo-copy"><strong>Kapouch</strong><span>coffee management</span></span></a>
      <div class="login-product-note">Рабочая система кофейни</div>
    </div>

    <div class="login-hero">
      <div class="login-hero-copy">
        <div class="login-eyebrow">Продажи · команда · гости</div>
        <h1>Кофейня, которая <span>работает как система.</span></h1>
        <p>Kapouch связывает кассу, склад, финансы и клиентский сервис в одном живом контуре. Меньше ручной рутины — больше времени на кофе, команду и рост.</p>
        <div class="login-hero-actions" aria-label="Ключевые возможности">
          <span class="login-hero-chip"><b>01</b> Продажи и прибыль</span>
          <span class="login-hero-chip"><b>02</b> Склад и закупки</span>
          <span class="login-hero-chip"><b>03</b> PWA и лояльность</span>
        </div>
      </div>

      <div class="login-stage" aria-hidden="true">
        <div class="login-stage-glow"></div>
        <div class="login-dashboard-preview">
          <div class="preview-top">
            <div class="preview-brand"><div class="preview-brand-mark">K</div><div><strong>Сегодня в Kapouch</strong><span>операционный центр</span></div></div>
            <div class="preview-live">данные обновлены</div>
          </div>
          <div class="preview-metrics">
            <div class="preview-metric"><span>Выручка</span><strong>48 620 ₽</strong><em>↑ 12,4% к вчера</em></div>
            <div class="preview-metric"><span>Средний чек</span><strong>438 ₽</strong><em>↑ 4,8%</em></div>
            <div class="preview-metric"><span>Заказы</span><strong>111</strong><em>сегодня</em></div>
          </div>
          <div class="preview-chart">
            <i class="preview-bar"></i><i class="preview-bar"></i><i class="preview-bar"></i><i class="preview-bar"></i><i class="preview-bar"></i><i class="preview-bar"></i><i class="preview-bar"></i><i class="preview-bar"></i><i class="preview-bar"></i><i class="preview-bar"></i>
          </div>
          <div class="preview-order">
            <div class="preview-order-top"><span class="preview-order-label">Новый заказ</span><span class="preview-order-status">оплачен</span></div>
            <strong>Капучино × 2</strong>
            <p>Овсяное молоко · сироп солёная карамель</p>
            <div class="preview-order-price"><span>Через PWA</span><span>780 ₽</span></div>
          </div>
        </div>
      </div>
    </div>

    <div class="login-features" aria-label="Возможности Kapouch">
      <article class="login-feature"><div class="login-feature-icon">₽</div><strong>Финансы</strong><span>Выручка, расходы, прибыль, касса и план-факт.</span></article>
      <article class="login-feature"><div class="login-feature-icon">◫</div><strong>Склад</strong><span>Ингредиенты, закупки, остатки и себестоимость.</span></article>
      <article class="login-feature"><div class="login-feature-icon">↗</div><strong>Аналитика</strong><span>Динамика, средний чек и контроль отклонений.</span></article>
      <article class="login-feature"><div class="login-feature-icon">K</div><strong>Гости</strong><span>Онлайн-заказы, бонусы, push и персональная QR-карта.</span></article>
    </div>

    <div class="login-trust"><span class="login-trust-item"><b>Evotor</b> продажи и заказы</span><span class="login-trust-item"><b>СБП</b> онлайн-оплата</span><span class="login-trust-item"><b>PWA</b> приложение для гостей</span><span class="login-trust-item"><b>QR</b> лояльность на кассе</span></div>
  </section>

  <section class="login-side" aria-label="Вход в Kapouch">
    <div class="login-card">
      <div class="login-card-top"><div class="login-card-kicker">Панель управления</div><h2>С возвращением</h2><p class="login-card-subtitle">Войдите в рабочее пространство Kapouch.</p></div>
      <?php if(isset($_GET['installed'])):?><div class="alert success">Установка завершена. Войдите в систему.</div><?php endif;?>
      <?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><label>Email<input type="email" name="email" autocomplete="username" required autofocus placeholder="name@example.ru"></label><label>Пароль<input type="password" name="password" autocomplete="current-password" required placeholder="Введите пароль"></label><button class="btn primary">Войти в Kapouch</button></form>
      <a class="login-customer-link" href="https://app.kapouch.store/" rel="noopener"><span><strong>Клиентское приложение</strong>Заказать кофе, открыть бонусы и QR-карту.</span><span>→</span></a>
      <div class="login-security"><span class="login-security-icon">✓</span><span>Доступ к данным кофейни защищён авторизацией. Пароли и ключи интеграций не отображаются на этой странице.</span></div>
    </div>
  </section>
</main>
</body>
</html>