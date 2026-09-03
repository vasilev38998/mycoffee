<?php
declare(strict_types=1);
function page_header(string $title): void {
    $user=current_user(); $flash=pull_flash();
    $current=basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $nav=[
        ['index.php','Дашборд'],
        ['analytics.php','Аналитика'],
        ['daily_report.php','Отчёт дня'],
        ['planning.php','Планирование'],
        ['cash.php','Касса'],
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
    $coffeeName=(string)app_setting('coffee_name','Kapouch');
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#111827"><title><?=e($title)?> — <?=e($coffeeName)?></title><link rel="stylesheet" href="assets/style.css?v=20260903-7"></head><body><div class="app"><aside class="sidebar"><a class="brand" href="index.php"><span class="brand-mark">K</span><span class="brand-copy"><strong><?=e($coffeeName)?></strong><small>управление кофейней</small></span></a><nav><?php foreach($nav as [$href,$label]):?><a class="<?=$current===$href?'active':''?>" href="<?=e($href)?>"><span class="nav-dot"></span><span class="nav-label"><?=e($label)?></span></a><?php endforeach;?></nav><div class="sidebar-foot"><a class="user-badge" href="settings.php"><span><?=e(mb_strtoupper(mb_substr($user['name'] ?? 'U',0,1)))?></span><div><strong><?=e($user['name'] ?? '')?></strong><small>Профиль и настройки</small></div></a><a class="logout-link" href="logout.php">Выйти</a></div></aside><main class="content"><header class="topbar"><div><div class="eyebrow">Панель владельца · <?=e(date_default_timezone_get())?></div><h1><?=e($title)?></h1></div><div class="topbar-date"><?=e(date('d.m.Y H:i'))?></div></header><?php foreach($flash as $m): ?><div class="alert <?=e($m['type'])?>"><?=e($m['message'])?></div><?php endforeach; ?><?php
    if($current==='index.php'){
        $revenueGoal=(float)app_setting('monthly_revenue_goal','0');
        $profitGoal=(float)app_setting('monthly_profit_goal','0');
        if($revenueGoal>0 || $profitGoal>0){
            $month=dashboard_metrics(date('Y-m-01'),date('Y-m-d'));
            $revPct=$revenueGoal>0?min(999,$month['revenue']/$revenueGoal*100):0;
            $profitPct=$profitGoal>0?min(999,max(0,$month['operating_profit']/$profitGoal*100)):0;
            ?><div class="three-col"><div class="insight-card"><div class="kicker">Цель выручки месяца</div><strong><?=number_format($revPct,0,',',' ')?>%</strong><p><?=money($month['revenue'])?> из <?=money($revenueGoal)?>.</p></div><div class="insight-card"><div class="kicker">Цель прибыли месяца</div><strong><?=number_format($profitPct,0,',',' ')?>%</strong><p><?=money($month['operating_profit'])?> из <?=money($profitGoal)?>.</p></div><div class="insight-card"><div class="kicker">Локальное время</div><strong><?=e(date('H:i'))?></strong><p>Часовой пояс <?=e(app_timezone())?>.</p></div></div><?php
        }
    }
}
function page_footer(): void { ?></main></div></body></html><?php }
