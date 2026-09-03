<?php
declare(strict_types=1);
function page_header(string $title): void {
    $user=current_user(); $flash=pull_flash();
    $current=basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $nav=[
        ['index.php','◫','Дашборд'],
        ['ingredients.php','◉','Ингредиенты'],
        ['products.php','▦','Меню и техкарты'],
        ['inventory.php','□','Склад'],
        ['purchases.php','↓','Закупки'],
        ['sales.php','↑','Продажи'],
        ['expenses.php','−','Расходы'],
        ['automatic_expenses.php','↻','Авторасходы'],
        ['integrations.php','⌁','Интеграции'],
    ];
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#15130f"><title><?=e($title)?> — MyCoffee</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app"><aside class="sidebar"><div class="brand"><span class="brand-mark">M</span><div><strong>MyCoffee</strong><small>управление кофейней</small></div></div><nav><?php foreach($nav as [$href,$icon,$label]):?><a class="<?=$current===$href?'active':''?>" href="<?=e($href)?>"><span class="nav-icon"><?=e($icon)?></span><span><?=e($label)?></span></a><?php endforeach;?></nav><div class="sidebar-foot"><div class="user-badge"><span><?=e(mb_strtoupper(mb_substr($user['name'] ?? 'U',0,1)))?></span><div><strong><?=e($user['name'] ?? '')?></strong><small><?=e($user['role'] ?? 'owner')?></small></div></div><a class="logout-link" href="logout.php">Выйти</a></div></aside><main class="content"><header class="topbar"><div><div class="eyebrow">MyCoffee · Управленческий учёт</div><h1><?=e($title)?></h1></div><div class="topbar-date"><?=e(date('d.m.Y'))?></div></header><?php foreach($flash as $m): ?><div class="alert <?=e($m['type'])?>"><?=e($m['message'])?></div><?php endforeach; ?><?php
}
function page_footer(): void { ?></main></div></body></html><?php }
