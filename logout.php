<?php
require __DIR__ . '/inc/bootstrap.php';
logout_user();
header('Location: login.php');
exit;
