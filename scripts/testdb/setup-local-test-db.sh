#!/usr/bin/env bash
# 사용자 PC(XAMPP·Homebrew 등)에 실제 AI 상담 테스트용 로컬 DB 'tomokmall_test' 를 만든다.
#
# 책임: 스키마·마이그레이션·수집 상품(data/ktomok/import.sql)·법령 근거 SQL 초안 01·02·06·07 적용
#       → scripts/testdb/consult-scenarios.php 로 실제 OpenAI 상담 시나리오를 돌릴 수 있게 준비
# 금지: 운영 DB 접속. 호스트는 127.0.0.1/localhost 만, DB 이름은 *_test 만 허용. 비밀번호는 파일·화면에 남기지 않는다.
#
# 사용(Git Bash):
#   MYSQL_CMD="/c/xampp/mysql/bin/mysql.exe -h127.0.0.1 -uroot" bash scripts/testdb/setup-local-test-db.sh
#   (비밀번호가 있으면 실행 중 입력. 이미 있으면 --reset 으로 지우고 다시 만듦)
# 참고: MariaDB(XAMPP 기본)는 ngram 파서를 지원하지 않아 상품 FULLTEXT 를 기본 파서로 만든다 → 한글 2글자 검색 결과가
#       운영(MySQL 8.0 ngram)과 다를 수 있다. 법령 근거 검색·장소 판정은 DB 종류와 무관.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DB="${TEST_DB_NAME:-tomokmall_test}"
MYSQL_CMD="${MYSQL_CMD:-mysql -h127.0.0.1 -uroot}"
RESET=0; [ "${1:-}" = "--reset" ] && RESET=1

case "$DB" in *_test) ;; *) echo "DB 이름은 *_test 만 허용합니다: $DB" >&2; exit 2 ;; esac
case "$MYSQL_CMD" in *-h*) case "$MYSQL_CMD" in *-h127.0.0.1*|*-hlocalhost*|*"-h 127.0.0.1"*|*"-h localhost"*) ;; *) echo "로컬 호스트(127.0.0.1/localhost)만 허용합니다." >&2; exit 2 ;; esac ;; esac

if [ -z "${MYSQL_PWD:-}" ]; then
  read -rsp "로컬 DB 비밀번호(없으면 Enter): " MYSQL_PWD; echo
fi
export MYSQL_PWD

sql() { $MYSQL_CMD --default-character-set=utf8mb4 "$@"; }
VER="$(echo "SELECT VERSION();" | sql -N)"
MARIA=0; case "$VER" in *MariaDB*|*mariadb*) MARIA=1 ;; esac
echo "DB 서버: $VER"

EXISTS="$(echo "SHOW DATABASES LIKE '$DB';" | sql -N)"
if [ -n "$EXISTS" ]; then
  [ "$RESET" = 1 ] || { echo "'$DB' 이(가) 이미 있습니다. 다시 만들려면 --reset" >&2; exit 1; }
  echo "DROP DATABASE \`$DB\`;" | sql
fi
echo "CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | sql

run() { sql "$DB"; }
# MySQL 8.0: MariaDB 전용 'IF NOT EXISTS' 제거 / MariaDB: ngram 파서 제거(미지원)
fix() {
  if [ "$MARIA" = 1 ]; then sed -E 's/ WITH PARSER ngram//g' "$1"
  else sed -E 's/(ADD (COLUMN|UNIQUE KEY|KEY|INDEX)) IF NOT EXISTS/\1/g; /DROP INDEX IF EXISTS/d; /ADD FULLTEXT KEY ft_products_search/d' "$1"; fi
}
fix "$ROOT_DIR/database/schema.sql" | run
for f in add_users_auth add_ai_sessions add_pricing add_vendors add_rag_documents add_category_consult_guide add_chat_logs; do
  fix "$ROOT_DIR/database/$f.sql" | sed -E '/ADD FULLTEXT KEY ft_products_search/d' | run
done
run < "$ROOT_DIR/data/ktomok/import.sql"

D="$ROOT_DIR/docs/03-analysis/ai-sourcing/sql-drafts"
for f in 01_rag_documents_source_columns_DRAFT.sql 02_rag_documents_legal_candidates_DRAFT.sql 06_rag_documents_verified_clauses_DRAFT.sql 07_LOCAL_TEST_ONLY_activate_verified_clauses.sql; do
  sed '/^DRAFT_STOP_/d' "$D/$f" | run   # 초안 안전장치 줄은 테스트 DB 적용 때만 제거(원본 파일은 그대로)
done

# 상담 테스트가 쓰는 법령 근거 칸(SQL 초안 01) 확인 — 없으면 원인을 알려 주고 중단
NEED="source_type source_name source_ref source_url effective_date applies_to legal_status author review_status verify_level"
HAVE=" $(echo "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='rag_documents';" | sql -N | tr '\r\n' '  ') "
MISS=""; for c in $NEED; do case "$HAVE" in *" $c "*) ;; *) MISS="$MISS $c" ;; esac; done
if [ -n "$MISS" ]; then
  echo "[오류] $DB.rag_documents 에 법령 근거 칸이 없습니다:$MISS — SQL 초안 01 적용 실패. 위 오류 메시지를 확인하세요." >&2
  exit 1
fi
echo "$(echo "SELECT CONCAT('연결: ', VERSION(), ' · 포트 ', @@port, ' · DB ', '$DB');" | sql -N)"
echo "SELECT 'ktomok 상품', COUNT(*) FROM products WHERE external_source='ktomok'
      UNION ALL SELECT '검증 조문(활성)', COUNT(*) FROM rag_documents WHERE author='검증 조문(2-1단계)' AND is_active=1
      UNION ALL SELECT '검증 조문(전체)', COUNT(*) FROM rag_documents WHERE author='검증 조문(2-1단계)';" | run
echo
echo "준비 완료. 1) 사전 점검(OpenAI 호출 없음) → 2) 실제 상담 테스트(.env.local 의 OPENAI_API_KEY 사용):"
echo "  DB_HOST=127.0.0.1 DB_NAME=$DB php scripts/testdb/consult-scenarios.php --check-only"
echo "  DB_HOST=127.0.0.1 DB_NAME=$DB php scripts/testdb/consult-scenarios.php --with-public-road --md=consult-result.md"
echo "  (DB_USER·DB_PASS 가 .env.local 과 다르면 앞에 DB_USER=… 를 붙이고, 비밀번호는 셸에서 read -rs DB_PASS; export DB_PASS)"
