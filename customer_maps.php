<?php
declare(strict_types=1);
require __DIR__.'/inc/bootstrap.php';
require __DIR__.'/inc/layout.php';
require_auth();

$user=current_user();if(!in_array($user['role']??'',['owner','manager'],true)){http_response_code(403);exit('Недостаточно прав.');}
function customer_maps_admin_url(string $value): string{$value=trim($value);if($value==='')return '';if(!filter_var($value,FILTER_VALIDATE_URL)||!str_starts_with(mb_strtolower($value),'https://'))throw new RuntimeException('Все ссылки должны быть полными и начинаться с https://');return mb_substr($value,0,1000);}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        foreach(['customer_2gis_url','customer_2gis_review_url','customer_yandex_maps_url','customer_yandex_review_url'] as $key)set_app_setting($key,customer_maps_admin_url((string)($_POST[$key]??'')));
        audit_write('customer_maps_links_updated','Обновлены ссылки на 2ГИС и Яндекс Карты для клиентского PWA');flash('success','Ссылки на карты и отзывы сохранены.');
    }catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('customer_maps.php');
}
$twoGis=(string)app_setting('customer_2gis_url','');$twoGisReview=(string)app_setting('customer_2gis_review_url','');$yandex=(string)app_setting('customer_yandex_maps_url','');$yandexReview=(string)app_setting('customer_yandex_review_url','');$pickup=(string)app_setting('customer_pickup_label','Самовывоз из кофейни');
page_header('Карты и отзывы');
?>
<div class="card"><div class="chart-head"><div><h2>Карты и отзывы</h2><p>Добавьте прямые ссылки на карточку Kapouch в 2ГИС и Яндекс Картах и, по возможности, отдельные ссылки для формы отзыва.</p></div><a class="btn ghost" href="customer_app.php">← Клиентское PWA</a></div></div>
<div class="alert info section"><strong>Подсказка:</strong> откройте карточку кофейни в каждом сервисе и скопируйте ссылку из браузера или кнопки «Поделиться». Для «Оставить отзыв» лучше использовать прямую ссылку на карточку/форму отзыва; если поле оставить пустым, PWA поведёт пользователя на обычную карточку кофейни.</div>
<form method="post" class="card section"><input type="hidden" name="csrf" value="<?=csrf_token()?>">
<div class="chart-head"><div><h2>2ГИС</h2><p>Ссылка на карточку и отдельная ссылка для отзыва.</p></div></div><div class="form-grid section" style="margin-top:14px">
<label>Карточка в 2ГИС<input type="url" name="customer_2gis_url" value="<?=e($twoGis)?>" placeholder="https://2gis.ru/..."></label>
<label>Оставить отзыв в 2ГИС<input type="url" name="customer_2gis_review_url" value="<?=e($twoGisReview)?>" placeholder="https://2gis.ru/..."><small class="muted">Можно оставить пустым — тогда используется ссылка на карточку.</small></label>
</div>
<div class="chart-head section"><div><h2>Яндекс Карты</h2><p>Ссылка на карточку и отдельная ссылка для отзыва.</p></div></div><div class="form-grid" style="margin-top:14px">
<label>Карточка в Яндекс Картах<input type="url" name="customer_yandex_maps_url" value="<?=e($yandex)?>" placeholder="https://yandex.ru/maps/..."></label>
<label>Оставить отзыв в Яндекс Картах<input type="url" name="customer_yandex_review_url" value="<?=e($yandexReview)?>" placeholder="https://yandex.ru/maps/..."><small class="muted">Можно оставить пустым — тогда используется ссылка на карточку.</small></label>
</div><div class="actions section"><button class="btn primary">Сохранить ссылки</button><?php if($twoGis):?><a class="btn ghost" href="<?=e($twoGis)?>" target="_blank" rel="noopener">Открыть 2ГИС ↗</a><?php endif;?><?php if($yandex):?><a class="btn ghost" href="<?=e($yandex)?>" target="_blank" rel="noopener">Открыть Яндекс ↗</a><?php endif;?></div></form>
<div class="card section"><h2>Как это будет выглядеть</h2><p class="muted">В PWA появится отдельная живая карточка «Найти нас и оставить отзыв» под популярными напитками. Если прямые ссылки ещё не заданы, Kapouch построит поиск по точке самовывоза: <strong><?=e($pickup)?></strong>.</p></div>
<?php page_footer();?>
