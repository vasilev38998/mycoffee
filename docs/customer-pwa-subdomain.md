# Клиентский PWA на app.kapouch.store

Рабочая схема после миграции:

- `https://kapouch.store/` — админка Kapouch, API, Evotor, cron и webhooks.
- `https://app.kapouch.store/` — канонический адрес клиентского PWA.
- `https://kapouch.store/customer/` — совместимый старый маршрут; он не используется как основной публичный адрес и оставлен только для ранее сохранённых ссылок/установок.

## Beget

`app.kapouch.store` прикреплён к тому же сайту (директории), что и `kapouch.store`, то есть к существующему сайту `kapouch.store/public_html` в терминах панели Beget.

Файлы PWA не копируются в отдельный `app.kapouch.store/public_html`, внешний 301/302 для рабочего поддомена не нужен. Корневой `.htaccess` различает домены по `HTTP_HOST`: запросы к `app.kapouch.store` внутренне переписываются в каталог `customer/`, поэтому адрес в браузере остаётся `https://app.kapouch.store/...`.

Через клиентский поддомен `/index.php`, `/settings.php`, `/inc/...` и другие пути основного проекта не открывают админку: запрос обслуживается только клиентским деревом либо получает 404/403.

## Разделение origin

PWA обращается к API по `https://kapouch.store/api`. API разрешает точный клиентский origin `https://app.kapouch.store`; произвольные внешние origin блокируются.

Канонические ссылки формируются централизованно:

- клиентское приложение и QR: `https://app.kapouch.store/`;
- возврат после ЮKassa: `https://app.kapouch.store/payment-return.html`;
- API и действия Эвотор: `https://kapouch.store/api/...`.

Миграция `037_customer_pwa_subdomain_finalize.sql` закрепляет эти адреса и переводит старый QR `https://kapouch.store/customer/` на новый поддомен, не перезаписывая специально заданный внешний QR-адрес.

## Проверенный функционал

На новом origin проверены каталог, изображения, SMS-вход, профиль, персональная QR-карта и юридические документы. Корзина и заказ работают через API основного домена. Старый `/customer/` остаётся только как совместимый маршрут и не конфликтует с `app.kapouch.store`, потому что новый PWA имеет отдельный origin и собственный service worker scope.
