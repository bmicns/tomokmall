#!/bin/bash
# 로컬 스테이징 환경 시작 (PHP 내장 서버 + Homebrew MySQL)

cd "$(dirname "$0")/.."

# .env.local 로드
if [ -f .env.local ]; then
    export $(grep -v '^#' .env.local | xargs)
fi

# MySQL 서비스 시작
if ! brew services list | grep -q "mysql.*started"; then
    echo "🔌 MySQL 시작 중..."
    brew services start mysql
    sleep 3
fi

echo "✅ MySQL: 실행 중 (포트 3306)"

# PHP 내장 서버 시작
echo "🚀 PHP 서버 시작 (http://localhost:8080)..."
php -S localhost:8080 -t public/ &
PHP_PID=$!

echo ""
echo "─────────────────────────────────────────"
echo "  🌐 사이트:    http://localhost:8080"
echo "  🔑 어드민:    http://localhost:8080/admin.php"
echo "  👤 계정:      admin@admin.com / 1111"
echo "─────────────────────────────────────────"
echo "  DB: 127.0.0.1:3306 / tomokmall / tomok6385!"
echo "─────────────────────────────────────────"
echo "  종료: Ctrl+C"
echo ""

# Ctrl+C로 종료
trap "kill $PHP_PID 2>/dev/null; echo '서버 종료됨'" INT
wait $PHP_PID
