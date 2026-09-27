<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/customer_api.php';

customer_api_headers();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='OPTIONS'){http_response_code(204);exit;}
customer_api_guard_origin();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')customer_api_reply(405,['ok'=>false,'error'=>'Method not allowed']);

$pickup=trim((string)app_setting('customer_pickup_label','Самовывоз из кофейни'));
$query=rawurlencode(trim('Kapouch '.$pickup));
$twoGis=trim((string)app_setting('customer_2gis_url',''));
$twoGisReview=trim((string)app_setting('customer_2gis_review_url',''));
$yandex=trim((string)app_setting('customer_yandex_maps_url',''));
$yandexReview=trim((string)app_setting('customer_yandex_review_url',''));
if($twoGis==='')$twoGis='https://2gis.ru/search/'.$query;
if($yandex==='')$yandex='https://yandex.ru/maps/?text='.$query;
if($twoGisReview==='')$twoGisReview=$twoGis;
if($yandexReview==='')$yandexReview=$yandex;
customer_api_reply(200,['ok'=>true,'maps'=>['pickup_label'=>$pickup,'two_gis_url'=>$twoGis,'two_gis_review_url'=>$twoGisReview,'yandex_maps_url'=>$yandex,'yandex_review_url'=>$yandexReview]]);
