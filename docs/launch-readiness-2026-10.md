# Kapouch PWA — launch readiness review

Дата ревизии: 2026-10-03.

## Цель

Подготовить клиентский PWA к публичному запуску без изменения бизнес-критичных интеграций Evotor/YooKassa: убрать визуальные и runtime-регрессии, сделать обновления Service Worker предсказуемыми, улучшить работу при плохой сети, доступность и наблюдаемость production.

## Что проверено

- главный экран, меню, карточка товара, корзина и checkout;
- вход по телефону и профиль;
- QR-карта и программа шестого напитка;
- wheel/growth/current-order/push модули;
- offline/cache/service worker/update flow;
- manifest и standalone PWA поведение;
- production monitoring;
- существующие PHP/JS/runtime/contract тесты.

## Принципы релизного hardening

1. Версионный app shell не должен смешивать старый и новый дизайн.
2. Новый Service Worker устанавливается в фоне и активируется после явного обновления пользователем, а не посреди сессии.
3. При медленном интернете меню быстро использует последнюю сохранённую копию, а приватные API не кладутся в общий SW cache.
4. Pinch-to-zoom не блокируется; интерактивные элементы имеют нормальные touch targets и видимый keyboard focus.
5. Runtime-слои должны быть идемпотентными и не плодить MutationObserver/event listeners при повторной загрузке.
6. Production доступность проверяется независимо от Beget с диагностикой DNS/TLS/HTTP.

## Не менялось

- протокол обмена с терминалом Evotor;
- URL и payload Evotor endpoints;
- логика YooKassa/SBP;
- схема расчёта бонусов и шестого напитка;
- пользовательские данные и production secrets.
