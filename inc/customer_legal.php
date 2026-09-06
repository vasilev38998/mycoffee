<?php
declare(strict_types=1);

function customer_legal_settings(): array
{
    return [
        'enabled'=>(string)app_setting('customer_legal_enabled','1')==='1',
        'seller_name'=>trim((string)app_setting('customer_legal_seller_name','')),
        'inn'=>trim((string)app_setting('customer_legal_inn','')),
        'ogrnip'=>trim((string)app_setting('customer_legal_ogrnip','')),
        'legal_address'=>trim((string)app_setting('customer_legal_legal_address','')),
        'trade_address'=>trim((string)app_setting('customer_legal_trade_address',(string)app_setting('customer_pickup_label',''))),
        'bank_name'=>trim((string)app_setting('customer_legal_bank_name','')),
        'bik'=>trim((string)app_setting('customer_legal_bik','')),
        'correspondent_account'=>trim((string)app_setting('customer_legal_correspondent_account','')),
        'settlement_account'=>trim((string)app_setting('customer_legal_settlement_account','')),
        'contact_email'=>trim((string)app_setting('customer_legal_contact_email','')),
        'contact_phone'=>trim((string)app_setting('customer_legal_contact_phone',(string)app_setting('customer_support_phone',''))),
        'offer_title'=>trim((string)app_setting('customer_legal_offer_title','Публичная оферта о продаже товаров через Kapouch')),
        'offer_version'=>trim((string)app_setting('customer_legal_offer_version','1.0')),
        'offer_date'=>trim((string)app_setting('customer_legal_offer_date','')),
        'offer_text'=>trim((string)app_setting('customer_legal_offer_text','')),
        'extra_terms'=>trim((string)app_setting('customer_legal_extra_terms','')),
    ];
}

function customer_legal_validate_digits(string $value,int $length,string $label): string
{
    $value=preg_replace('/\D+/','',$value)??'';
    if($value!==''&&strlen($value)!==$length)throw new RuntimeException($label.' должен содержать '.$length.' цифр.');
    return $value;
}

function customer_legal_validate_input(array $data): array
{
    $date=trim((string)($data['offer_date']??''));
    if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('Дата оферты должна быть в формате ГГГГ-ММ-ДД.');
    if($date!==''&&strtotime($date.' 00:00:00')===false)throw new RuntimeException('Проверьте дату начала действия оферты.');
    $email=mb_strtolower(trim((string)($data['contact_email']??'')));
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Проверьте email для обращений покупателей.');
    $seller=mb_substr(trim((string)($data['seller_name']??'')),0,255);
    $legalAddress=mb_substr(trim((string)($data['legal_address']??'')),0,500);
    $tradeAddress=mb_substr(trim((string)($data['trade_address']??'')),0,500);
    $phone=mb_substr(trim((string)($data['contact_phone']??'')),0,80);
    $bank=mb_substr(trim((string)($data['bank_name']??'')),0,255);
    $title=mb_substr(trim((string)($data['offer_title']??'')),0,255);
    $version=mb_substr(trim((string)($data['offer_version']??'')),0,40);
    $text=trim((string)($data['offer_text']??''));
    $extra=trim((string)($data['extra_terms']??''));
    if(mb_strlen($text)>40000)throw new RuntimeException('Текст оферты слишком длинный. Максимум 40 000 символов.');
    if(mb_strlen($extra)>10000)throw new RuntimeException('Дополнительные условия слишком длинные. Максимум 10 000 символов.');
    return [
        'enabled'=>!empty($data['enabled']),
        'seller_name'=>$seller,
        'inn'=>customer_legal_validate_digits((string)($data['inn']??''),12,'ИНН ИП'),
        'ogrnip'=>customer_legal_validate_digits((string)($data['ogrnip']??''),15,'ОГРНИП'),
        'legal_address'=>$legalAddress,
        'trade_address'=>$tradeAddress,
        'bank_name'=>$bank,
        'bik'=>customer_legal_validate_digits((string)($data['bik']??''),9,'БИК'),
        'correspondent_account'=>customer_legal_validate_digits((string)($data['correspondent_account']??''),20,'Корреспондентский счёт'),
        'settlement_account'=>customer_legal_validate_digits((string)($data['settlement_account']??''),20,'Расчётный счёт'),
        'contact_email'=>$email,
        'contact_phone'=>$phone,
        'offer_title'=>$title!==''?$title:'Публичная оферта о продаже товаров через Kapouch',
        'offer_version'=>$version!==''?$version:'1.0',
        'offer_date'=>$date,
        'offer_text'=>$text,
        'extra_terms'=>$extra,
    ];
}

function customer_legal_save(array $data): array
{
    $v=customer_legal_validate_input($data);
    $map=[
        'enabled'=>'customer_legal_enabled','seller_name'=>'customer_legal_seller_name','inn'=>'customer_legal_inn','ogrnip'=>'customer_legal_ogrnip',
        'legal_address'=>'customer_legal_legal_address','trade_address'=>'customer_legal_trade_address','bank_name'=>'customer_legal_bank_name','bik'=>'customer_legal_bik',
        'correspondent_account'=>'customer_legal_correspondent_account','settlement_account'=>'customer_legal_settlement_account','contact_email'=>'customer_legal_contact_email',
        'contact_phone'=>'customer_legal_contact_phone','offer_title'=>'customer_legal_offer_title','offer_version'=>'customer_legal_offer_version','offer_date'=>'customer_legal_offer_date',
        'offer_text'=>'customer_legal_offer_text','extra_terms'=>'customer_legal_extra_terms',
    ];
    foreach($map as $key=>$setting)set_app_setting($setting,$key==='enabled'?($v[$key]?'1':'0'):(string)$v[$key]);
    return $v;
}

function customer_legal_missing(array $s): array
{
    $required=[
        'seller_name'=>'Наименование ИП','inn'=>'ИНН','ogrnip'=>'ОГРНИП','legal_address'=>'Адрес регистрации ИП','trade_address'=>'Адрес точки самовывоза',
        'contact_email'=>'Email для обращений','contact_phone'=>'Телефон для обращений','bank_name'=>'Банк','bik'=>'БИК','settlement_account'=>'Расчётный счёт','correspondent_account'=>'Корреспондентский счёт',
    ];
    $missing=[];foreach($required as $key=>$label)if(trim((string)($s[$key]??''))==='')$missing[]=$label;return $missing;
}

function customer_legal_default_offer(array $s): string
{
    $seller=$s['seller_name']!==''?$s['seller_name']:'Продавец';
    $pickup=$s['trade_address']!==''?$s['trade_address']:(string)app_setting('customer_pickup_label','точка самовывоза');
    $email=$s['contact_email']!==''?$s['contact_email']:'email, указанный в реквизитах';
    $phone=$s['contact_phone']!==''?$s['contact_phone']:'телефон, указанный в реквизитах';
    $ts=$s['offer_date']!==''?strtotime($s['offer_date'].' 00:00:00'):false;$effective=$ts!==false?date('d.m.Y',$ts):'даты публикации';
    $text=<<<TXT
1. Общие положения
Настоящий документ является публичной офертой {$seller} (далее — «Продавец») и определяет условия заказа и приобретения товаров через клиентское веб-приложение Kapouch. Оформляя заказ и подтверждая согласие с офертой, покупатель принимает её условия в полном объёме.

2. Предмет оферты
Продавец обязуется приготовить и передать покупателю выбранные в актуальном меню товары, а покупатель — принять и оплатить заказ выбранным доступным способом. Наименование, состав, цена и доступные варианты товара показываются в приложении до оформления заказа.

3. Оформление заказа
Покупатель самостоятельно выбирает товары, количество, доступные модификаторы, способ оплаты и время самовывоза, если такая функция включена. До отправки заказа покупатель обязан проверить состав корзины, контактные данные и итоговую сумму. Заказ считается принятым системой после успешного ответа сервера. Для онлайн-оплаты заказ поступает в рабочую очередь после подтверждения платежа платёжным сервисом.

4. Цена и оплата
Все цены указываются в рублях Российской Федерации и включают применимые налоги в соответствии с режимом налогообложения Продавца. Доступные способы оплаты отображаются при оформлении заказа. При оплате через СБП платёж обрабатывается ЮKassa. Электронный кассовый чек направляется на email покупателя, если это предусмотрено выбранным способом оплаты и настройками фискализации.

5. Получение заказа
Получение осуществляется самовывозом по адресу: {$pickup}. Указанное в приложении плановое время приготовления является ориентировочным и может измениться из-за загрузки кофейни, сложности заказа или иных объективных обстоятельств. Покупатель получает уведомление о фактической готовности заказа, если соответствующий канал уведомлений включён.

6. Отмена, возврат и претензии
До начала приготовления покупатель может обратиться к Продавцу для уточнения возможности отмены заказа. Возврат денежных средств за оплаченный заказ производится на исходный способ оплаты в случаях и порядке, предусмотренных законодательством Российской Федерации и правилами платёжного сервиса. Требования по качеству товара и иные обращения принимаются по телефону {$phone} и email {$email}. Особенности возврата пищевой продукции и товаров надлежащего качества определяются применимым законодательством.

7. Права и обязанности сторон
Продавец обязан передать товар, соответствующий оформленному заказу и обязательным требованиям к качеству и безопасности. Покупатель обязан предоставить корректные контактные данные, своевременно оплатить заказ и получить его в разумный срок. Продавец вправе приостановить приём заказов, изменить ассортимент или временно скрыть недоступные позиции до оформления нового заказа.

8. Персональные данные и электронные уведомления
Контактные данные покупателя используются для оформления и сопровождения заказа, входа в профиль, отправки кассового чека и сервисных уведомлений. Push-уведомления отправляются только после отдельного разрешения пользователя. Передача данных платёжному сервису выполняется в объёме, необходимом для оплаты и фискализации.

9. Ответственность и разрешение споров
Стороны несут ответственность в соответствии с законодательством Российской Федерации. Споры рекомендуется сначала урегулировать путём обращения к Продавцу по опубликованным контактам. Если договориться не удалось, покупатель вправе использовать предусмотренные законом способы защиты своих прав.

10. Действие оферты
Оферта действует с {$effective} до публикации новой редакции. К конкретному заказу применяется редакция, опубликованная на момент его оформления. Версия документа: {$s['offer_version']}.
TXT;
    if($s['extra_terms']!=='')$text.="\n\n11. Дополнительные условия\n".$s['extra_terms'];
    return $text;
}

function customer_legal_public_data(): array
{
    $s=customer_legal_settings();$missing=customer_legal_missing($s);$configured=$s['enabled']&&!$missing;
    return [
        'enabled'=>$s['enabled'],'configured'=>$configured,'missing'=>$missing,
        'seller'=>[
            'name'=>$s['seller_name'],'inn'=>$s['inn'],'ogrnip'=>$s['ogrnip'],'legal_address'=>$s['legal_address'],'trade_address'=>$s['trade_address'],
            'contact_email'=>$s['contact_email'],'contact_phone'=>$s['contact_phone'],
        ],
        'bank'=>['name'=>$s['bank_name'],'bik'=>$s['bik'],'settlement_account'=>$s['settlement_account'],'correspondent_account'=>$s['correspondent_account']],
        'offer'=>[
            'title'=>$s['offer_title'],'version'=>$s['offer_version'],'effective_date'=>$s['offer_date'],
            'text'=>$s['offer_text']!==''?$s['offer_text']:customer_legal_default_offer($s),
        ],
    ];
}

function customer_legal_checkout_acceptance(array $data): ?array
{
    $legal=customer_legal_public_data();
    if(!$legal['configured'])return null;
    $accept=is_array($data['offer_acceptance']??null)?$data['offer_acceptance']:[];
    if(empty($accept['accepted']))throw new RuntimeException('Для оформления заказа примите публичную оферту.');
    $currentVersion=(string)($legal['offer']['version']??'');$clientVersion=trim((string)($accept['version']??''));
    if($currentVersion!==''&&!hash_equals($currentVersion,$clientVersion))throw new RuntimeException('Публичная оферта обновилась. Обновите страницу и подтвердите новую редакцию.');
    return ['version'=>$currentVersion!==''?$currentVersion:'1.0','hash'=>hash('sha256',(string)$legal['offer']['text']),'accepted_at'=>date('Y-m-d H:i:s')];
}

function customer_legal_record_acceptance(int $orderId,?array $acceptance): void
{
    if($orderId<=0||$acceptance===null)return;
    $stmt=db()->prepare('INSERT IGNORE INTO customer_order_legal_acceptance(order_id,offer_version,offer_hash,accepted_at) VALUES(?,?,?,?)');
    $stmt->execute([$orderId,mb_substr((string)$acceptance['version'],0,40),(string)$acceptance['hash'],(string)$acceptance['accepted_at']]);
}
