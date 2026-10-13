#!/bin/bash
# 로컬 스테이징 환경 종료

# PHP 내장 서버 종료
pkill -f "php -S localhost:8080" 2>/dev/null && echo "✅ PHP 서버 종료"

# MySQL은 그대로 유지 (필요하면 아래 주석 해제)
# brew services stop mysql && echo "✅ MySQL 종료"
echo "MySQL은 계속 실행 중 (brew services stop mysql 으로 종료)"
