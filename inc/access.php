<?php
declare(strict_types=1);

function role_labels(): array{return ['owner'=>'Владелец','manager'=>'Управляющий','accountant'=>'Бухгалтер','employee'=>'Сотрудник'];}
function role_label(string $role): string{return role_labels()[$role]??$role;}

function role_pages(): array{
    $all=['index.php','owner_brief.php','control.php','analytics.php','economics.php','daily_report.php','planning.php','budget.php','online_orders.php','online_orders_feed.php','cash.php','cash_flow.php','sales.php','expenses.php','automatic_expenses.php','products.php','recipe.php','ingredients.php','inventory.php','purchases.php','suppliers.php','purchase_prices.php','integrations.php','settings.php','updates.php','users.php','audit.php','data_quality.php'];
    return [
      'owner'=>$all,
      'manager'=>array_values(array_diff($all,['users.php','audit.php','updates.php'])),
      'accountant'=>['index.php','control.php','analytics.php','economics.php','daily_report.php','planning.php','budget.php','cash.php','cash_flow.php','sales.php','expenses.php','automatic_expenses.php','purchases.php','suppliers.php','purchase_prices.php','data_quality.php'],
      'employee'=>['index.php','online_orders.php','online_orders_feed.php','cash.php','sales.php','inventory.php','purchases.php'],
    ];
}
function can_access_page(string $page,?array $user=null): bool{$user=$user??current_user();if(!$user)return false;return in_array($page,role_pages()[$user['role']]??[],true);}
function require_page_access(): void{
    $page=basename($_SERVER['SCRIPT_NAME']??'');
    $public=['login.php','logout.php','install.php','api_online_orders.php','customer_catalog.php','customer_order.php','customer_order_status.php'];
    if(in_array($page,$public,true))return;
    $user=current_user();if(!$user)return;
    if(!can_access_page($page,$user)){http_response_code(403);exit('Недостаточно прав для этого раздела.');}
}
