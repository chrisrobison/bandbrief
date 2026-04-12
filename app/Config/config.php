<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => getenv('APP_ENV') ?: 'development',
        'debug' => (getenv('APP_DEBUG') ?: '1') === '1',
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'bandbrief',
        'user' => getenv('DB_USER') ?: 'bandbrief',
        'pass' => getenv('DB_PASS') ?: 'bandbrief',
        'charset' => 'utf8mb4',
    ],
    'sources' => [
        'spotify' => [
            'client_id' => getenv('SPOTIFY_CLIENT_ID') ?: '',
            'client_secret' => getenv('SPOTIFY_CLIENT_SECRET') ?: '',
        ],
        'lastfm' => [
            'api_key' => getenv('LASTFM_API_KEY') ?: '',
        ],
        'reddit' => [
            'user_agent' => getenv('REDDIT_USER_AGENT') ?: 'BandBrief/1.0 (+https://example.com)',
        ],
        'bandcamp' => [
            'enabled' => (getenv('BANDCAMP_ENABLED') ?: '1') === '1',
        ],
    ],
];
