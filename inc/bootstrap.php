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
date_default_timezone_set(app_timezone());
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
