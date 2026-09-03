<?php
declare(strict_types=1);
function page_header(string $title): void {
    $user=current_user(); $flash=pull_flash();
    $current=basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $navGroups=[
        ['label'=>'Обзор','items'=>[
            ['index.php','Дашборд'],
            ['control.php','Контроль'],
            ['analytics.php','Аналитика'],
            ['daily_report.php','Отчёт дня'],
            ['planning.php','Планирование'],
        ]],
        ['label'=>'Операции','items'=>[
            ['cash.php','Касса'],
            ['sales.php','Продажи'],
            ['expenses.php','Расходы'],
            ['automatic_expenses.php','Авторасходы'],
        ]],
        ['label'=>'Меню и склад','items'=>[
            ['products.php','Меню и техкарты'],
            ['ingredients.php','Ингредиенты'],
            ['inventory.php','Склад'],
            ['purchases.php','Закупки'],
        ]],
        ['label'=>'Система','items'=>[
            ['integrations.php','Интеграции'],
            ['settings.php','Настройки'],
            ['updates.php','Обновления'],
        ]],
    ];
    $coffeeName=(string)app_setting('coffee_name','Kapouch');
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#111827"><title><?=e($title)?> — <?=e($coffeeName)?></title><link rel="stylesheet" href="assets/style.css?v=20260903-10"><link rel="stylesheet" href="assets/sidebar-groups.css?v=20260903-1"></head><body><div class="app"><aside class="sidebar"><a class="brand" href="index.php"><span class="brand-mark">K</span><span class="brand-copy"><strong><?=e($coffeeName)?></strong><small>управление кофейней</small></span></a><nav class="sidebar-groups"><?php foreach($navGroups as $group):$groupActive=false;foreach($group['items'] as $item){if($current===$item[0]){$groupActive=true;break;}}?><details class="nav-group" <?=$groupActive?'open':''?>><summary><span class="group-icon"></span><span class="group-label"><?=e($group['label'])?></span><span class="group-chevron">›</span></summary><div class="nav-submenu"><?php foreach($group['items'] as [$href,$label]):?><a class="<?=$current===$href?'active':''?>" href="<?=e($href)?>"><span class="nav-dot"></span><span class="nav-label"><?=e($label)?></span></a><?php endforeach;?></div></details><?php endforeach;?></nav><div class="sidebar-foot"><a class="user-badge" href="settings.php"><span><?=e(mb_strtoupper(mb_substr($user['name'] ?? 'U',0,1)))?></span><div><strong><?=e($user['name'] ?? '')?></strong><small>Профиль и настройки</small></div></a><a class="logout-link" href="logout.php">Выйти</a></div></aside><main class="content"><header class="topbar"><div><div class="eyebrow">Панель владельца · <?=e(date_default_timezone_get())?></div><h1><?=e($title)?></h1></div><div class="topbar-date"><?=e(date('d.m.Y H:i'))?></div></header><?php foreach($flash as $m): ?><div class="alert <?=e($m['type'])?>"><?=e($m['message'])?></div><?php endforeach; ?><?php
    $updateError=$GLOBALS['kapouch_update_error']??null;
    if($updateError && $current!=='updates.php'){?><div class="alert danger"><strong>Обновление базы не завершено.</strong> <a href="updates.php">Открыть «Обновления»</a> и посмотреть причину.</div><?php }
    if($current==='index.php'){
        $revenueGoal=(float)app_setting('monthly_revenue_goal','0');
        $profitGoal=(float)app_setting('monthly_profit_goal','0');
        if($revenueGoal>0 || $profitGoal>0){
            $month=dashboard_metrics(date('Y-m-01'),date('Y-m-d'));
            $revPct=$revenueGoal>0?min(999,$month['revenue']/$revenueGoal*100):0;
            $profitPct=$profitGoal>0?min(999,max(0,$month['operating_profit']/$profitGoal*100)):0;
            ?><div class="three-col"><div class="insight-card"><div class="kicker">Цель выручки месяца</div><strong><?=number_format($revPct,0,',',' ')?>%</strong><p><?=money($month['revenue'])?> из <?=money($revenueGoal)?>.</p></div><div class="insight-card"><div class="kicker">Цель прибыли месяца</div><strong><?=number_format($profitPct,0,',',' ')?>%</strong><p><?=money($month['operating_profit'])?> из <?=money($profitGoal)?>.</p></div><div class="insight-card"><div class="kicker">Локальное время</div><strong><?=e(date('H:i'))?></strong><p>Часовой пояс <?=e(app_timezone())?>.</p></div></div><?php
        }
        try{
            require_once __DIR__.'/control.php';
            $control=control_summary();
            if($control['total']>0){$kind=$control['critical']>0?'bad':'warn';?><a href="control.php" class="alert-item <?=e($kind)?> section" style="display:flex"><span class="alert-dot"></span><div><strong><?=$control['critical']>0?'Есть критичные сигналы':'Есть предупреждения'?></strong><p>Центр контроля: критичных <?=$control['critical']?>, предупреждений <?=$control['warning']?>. Нажми, чтобы открыть рекомендации.</p></div></a><?php }
        }catch(Throwable $e){}
    }
}
function page_footer(): void { ?><script>(function(){var groups=document.querySelectorAll('.sidebar .nav-group');groups.forEach(function(group){group.addEventListener('toggle',function(){if(!group.open)return;groups.forEach(function(other){if(other!==group)other.open=false;});});});})();</script></main></div></body></html><?php }
