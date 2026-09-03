<?php
declare(strict_types=1);
function page_header(string $title): void {
    $user=current_user(); $flash=pull_flash();
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> — MyCoffee</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app"><aside class="sidebar"><div class="brand">☕ MyCoffee</div><nav><a href="index.php">Дашборд</a><a href="ingredients.php">Ингредиенты</a><a href="products.php">Меню и техкарты</a><a href="sales.php">Продажи</a><a href="expenses.php">Расходы</a></nav><div class="sidebar-foot"><div><?=e($user['name'] ?? '')?></div><a href="logout.php">Выйти</a></div></aside><main class="content"><header class="topbar"><h1><?=e($title)?></h1></header><?php foreach($flash as $m): ?><div class="alert <?=e($m['type'])?>"><?=e($m['message'])?></div><?php endforeach; ?><?php
}
function page_footer(): void { ?></main></div></body></html><?php }
