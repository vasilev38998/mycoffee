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
date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Moscow');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
