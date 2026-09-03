<?php
declare(strict_types=1);

session_start();

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: install.php');
        exit;
    }
    return;
}

$config = require $configFile;

// Иркутск — безопасный системный дефолт ещё до подключения БД.
date_default_timezone_set('Asia/Irkutsk');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/updater.php';

$GLOBALS['kapouch_update_result'] = ['applied'=>[],'failed'=>null];
$GLOBALS['kapouch_update_error'] = null;
try {
    $GLOBALS['kapouch_update_result'] = kapouch_apply_pending_migrations(db(), true);
} catch (Throwable $e) {
    // Не считаем неудачную миграцию применённой и сохраняем ошибку для экрана «Обновления».
    // Приложение продолжает загружаться, чтобы владелец мог увидеть статус и повторить обновление.
    $GLOBALS['kapouch_update_error'] = $e->getMessage();
}

require_once __DIR__ . '/settings.php';
ensure_settings_tables();

// Старые чеки ранее сохранялись в МСК. Одноразово переводим их в Иркутск (+5 часов)
// до того, как новая версия сможет импортировать очередной чек уже в правильном часовом поясе.
migrate_evotor_times_to_irkutsk_once();

$config['app']['timezone'] = app_timezone();
$config['app']['currency'] = app_currency();
date_default_timezone_set($config['app']['timezone']);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
