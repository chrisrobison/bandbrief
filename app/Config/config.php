<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => getenv('APP_ENV') ?: 'development',
        'debug' => (getenv('APP_DEBUG') ?: '1') === '1',
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'bandbrief',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];
