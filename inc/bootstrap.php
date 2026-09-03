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
