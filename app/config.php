<?php
declare(strict_types=1);

return [
    'app_name' => '건축자재몰',
    'base_url' => 'http://tomokmall.dothome.co.kr',
    // 시크릿은 코드에 두지 않는다 — /app/.env (운영) 또는 .env.local (로컬)에서 주입.
    'openai' => [
        'api_key' => getenv('OPENAI_API_KEY') ?: '',
        'model'   => 'gpt-4o',
        // 테스트 전용: 로컬 목업 서버(http://127.0.0.1:…)만 허용 — 그 외 값은 무시하고 OpenAI 로 보낸다
        'chat_url' => preg_match('#^http://127\.0\.0\.1:\d+/#', (string) getenv('OPENAI_CHAT_URL')) ? (string) getenv('OPENAI_CHAT_URL') : 'https://api.openai.com/v1/chat/completions',
    ],
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'name'    => getenv('DB_NAME') ?: 'tomokmall',
        'user'    => getenv('DB_USER') ?: 'tomokmall',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];

