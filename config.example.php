<?php
return [
    'app' => [
        'name' => 'MyCoffee',
        'timezone' => 'Asia/Irkutsk',
        'currency' => '₽',
    ],
    'db' => [
        'host' => 'localhost',
        'name' => 'YOUR_DB_NAME',
        'user' => 'YOUR_DB_USER',
        'pass' => 'YOUR_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        // Сгенерируйте случайную строку не короче 32 байт. Установщик создаёт её автоматически.
        'encryption_key' => 'CHANGE_ME_TO_A_RANDOM_SECRET',
    ],
];
