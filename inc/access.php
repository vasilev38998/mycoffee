<?php
declare(strict_types=1);

function role_labels(): array{return ['owner'=>'Владелец','manager'=>'Управляющий','accountant'=>'Бухгалтер','employee'=>'Сотрудник'];}
function role_label(string $role): string{return role_labels()[$role]??$role;}

function role_pages(): array{
    $all=['index.php','owner_brief.php','control.php','analytics.php','economics.php','daily_report.php','planning.php','budget.php','online_orders.php','online_orders_feed.php','cash.php','cash_flow.php','sales.php','expenses.php','automatic_expenses.php','products.php','recipe.php','ingredients.php','inventory.php','purchases.php','receipt_import.php','receipt_proverkacheka.php','suppliers.php','purchase_prices.php','customer_app.php','customer_marketing.php','customer_payments.php','customer_refunds.php','customer_groups.php','customer_media.php','customer_modifiers.php','pwa_visibility.php','push_notifications.php','integrations.php','settings.php','updates.php','users.php','audit.php','data_quality.php'];
    return [
      'owner'=>$all,
      'manager'=>array_values(array_diff($all,['users.php','audit.php','updates.php'])),
      'accountant'=>['index.php','control.php','analytics.php','economics.php','daily_report.php','planning.php','budget.php','cash.php','cash_flow.php','sales.php','expenses.php','automatic_expenses.php','purchases.php','suppliers.php','purchase_prices.php','data_quality.php'],
      'employee'=>['index.php','online_orders.php','online_orders_feed.php','cash.php','sales.php','inventory.php','purchases.php'],
    ];
}
function can_access_page(string $page,?array $user=null): bool{$user=$user??current_user();if(!$user)return false;return in_array($page,role_pages()[$user['role']]??[],true);}
function kapouch_public_pages(): array{
    return [
      'login.php','logout.php','install.php','api_online_orders.php','receipt_proverkacheka_proxy.php',
      'customer_catalog.php','customer_order.php','customer_order_status.php','customer_order_detail.php','customer_favorites.php','customer_reorder.php','customer_manifest.php','customer_payment_yookassa_webhook.php',
      'customer_auth_request.php','customer_auth_verify.php','customer_profile.php','customer_logout.php',
      'customer_push_config.php','customer_push_subscribe.php','customer_push_unsubscribe.php'
    ];
}
function kapouch_sessionless_pages(): array{
    return array_values(array_diff(kapouch_public_pages(),['login.php','logout.php','install.php']));
}
function require_page_access(): void{
    if(PHP_SAPI==='cli')return;
    $page=basename($_SERVER['SCRIPT_NAME']??'');
    if(in_array($page,kapouch_public_pages(),true))return;
    $user=current_user();
    if(!$user){header('Location: login.php');exit;}
    if(!can_access_page($page,$user)){http_response_code(403);exit('Недостаточно прав для этого раздела.');}
}
