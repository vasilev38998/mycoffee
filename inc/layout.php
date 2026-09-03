<?php
declare(strict_types=1);
function page_header(string $title): void {
    $user=current_user(); $flash=pull_flash();
    $current=basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $nav=[
        ['index.php','Дашборд'],
        ['planning.php','Планирование'],
        ['ingredients.php','Ингредиенты'],
        ['products.php','Меню и техкарты'],
        ['inventory.php','Склад'],
        ['purchases.php','Закупки'],
        ['sales.php','Продажи'],
        ['expenses.php','Расходы'],
        ['automatic_expenses.php','Авторасходы'],
        ['integrations.php','Интеграции'],
        ['settings.php','Настройки'],
    ];
    $coffeeName=(string)app_setting('coffee_name','MyCoffee');
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#17130f"><title><?=e($title)?> — <?=e($coffeeName)?></title><link rel="stylesheet" href="assets/style.css?v=20260903-4"></head><body><div class="app"><aside class="sidebar"><a class="brand" href="index.php"><span class="brand-mark">M</span><span class="brand-copy"><strong><?=e($coffeeName)?></strong><small>управление кофейней</small></span></a><nav><?php foreach($nav as [$href,$label]):?><a class="<?=$current===$href?'active':''?>" href="<?=e($href)?>"><span class="nav-dot"></span><span class="nav-label"><?=e($label)?></span></a><?php endforeach;?></nav><div class="sidebar-foot"><a class="user-badge" href="settings.php"><span><?=e(mb_strtoupper(mb_substr($user['name'] ?? 'U',0,1)))?></span><div><strong><?=e($user['name'] ?? '')?></strong><small>Профиль и настройки</small></div></a><a class="logout-link" href="logout.php">Выйти</a></div></aside><main class="content"><header class="topbar"><div><div class="eyebrow">Панель владельца · <?=e(date_default_timezone_get())?></div><h1><?=e($title)?></h1></div><div class="topbar-date"><?=e(date('d.m.Y H:i'))?></div></header><?php foreach($flash as $m): ?><div class="alert <?=e($m['type'])?>"><?=e($m['message'])?></div><?php endforeach; ?><?php
}
function page_footer(): void { ?></main></div></body></html><?php }
