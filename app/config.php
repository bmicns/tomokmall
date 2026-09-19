<?php
declare(strict_types=1);

return [
    'app_name' => '건축자재몰',
    'base_url' => 'http://tomokmall.dothome.co.kr',
    // 시크릿은 코드에 두지 않는다 — /app/.env (운영) 또는 .env.local (로컬)에서 주입.
    'openai' => [
        'api_key' => getenv('OPENAI_API_KEY') ?: '',
        'model'   => 'gpt-4o',
    ],
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'name'    => getenv('DB_NAME') ?: 'tomokmall',
        'user'    => getenv('DB_USER') ?: 'tomokmall',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];

