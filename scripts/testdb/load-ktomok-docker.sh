#!/usr/bin/env bash
# 수집 상품(data/ktomok/import.sql 1,452건)을 넣은 일회용 로컬 테스트 DB를 Docker로 만든다.
#
# 책임: AI 상품 검색 통합 테스트(tests/ai_search.test.php)용 'tomokmall_test' DB 준비
# 금지: 운영 DB 접속. 컨테이너 포트는 127.0.0.1 에만 공개. 비밀번호는 실행마다 새로 만들고 파일로 남기지 않는다.
#
# 사용: bash scripts/testdb/load-ktomok-docker.sh   → 마지막 줄의 export 를 복사해 테스트 실행
#       종료: docker rm -f tomokmall_ai_test
# 참고: 저장소 마이그레이션 일부는 MariaDB 전용 구문(ADD COLUMN IF NOT EXISTS)이라
#       MySQL 8.0 테스트 DB에는 'IF NOT EXISTS' 를 빼고 적용한다(원본 파일은 수정하지 않음).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
NAME=tomokmall_ai_test
PASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)"

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" -e MYSQL_ROOT_PASSWORD="$PASS" -e MYSQL_DATABASE=tomokmall_test \
  -p 127.0.0.1:3306:3306 mysql:8.0 --ngram-token-size=2 >/dev/null
# 초기화용 임시 서버가 아닌 최종 서버(3306 포트)가 뜰 때까지 대기
for _ in $(seq 1 90); do
  docker logs "$NAME" 2>&1 | grep -q 'ready for connections.*port: 3306' && break
  sleep 2
done

run() { docker exec -i -e MYSQL_PWD="$PASS" "$NAME" mysql -uroot --default-character-set=utf8mb4 tomokmall_test; }
strip() { sed -E 's/(ADD (COLUMN|UNIQUE KEY|KEY|INDEX)) IF NOT EXISTS/\1/g; /DROP INDEX IF EXISTS/d; /ADD FULLTEXT KEY ft_products_search/d' "$1"; }

run < "$ROOT_DIR/database/schema.sql"
for f in add_users_auth add_ai_sessions add_pricing add_vendors add_rag_documents add_category_consult_guide add_chat_logs; do
  strip "$ROOT_DIR/database/$f.sql" | run
done
run < "$ROOT_DIR/data/ktomok/import.sql"

COUNT="$(echo "SELECT COUNT(*) FROM products WHERE external_source='ktomok';" | run | tail -1)"
echo "적재 완료: ktomok 상품 ${COUNT}건"
echo "export DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS='${PASS}'"
