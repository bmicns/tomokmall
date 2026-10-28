#!/usr/bin/env bash
# 법령 근거 상담 연동 테스트용: 로컬 테스트 DB(tomokmall_ai_test 컨테이너)에 SQL 초안 01·02·06·07 적용.
#
# 책임: rag_documents 출처 칸 추가(01) → 후보 39건 등록(02, 전부 비활성) → 검증 조문 등록(06) → 로컬 활성화(07)
# 금지: 운영 DB 접속. 이 스크립트는 Docker 컨테이너 tomokmall_ai_test 에만 적용한다(load-ktomok-docker.sh 로 먼저 생성).
# 초안 첫 줄의 안전장치(DRAFT_STOP_…)는 테스트 DB에 적용할 때만 제거한다(원본 파일은 수정하지 않음).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
NAME=tomokmall_ai_test
D="$ROOT_DIR/docs/03-analysis/ai-sourcing/sql-drafts"
PASS="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$NAME" | sed -n 's/^MYSQL_ROOT_PASSWORD=//p')"
[ -n "$PASS" ] || { echo "테스트 DB 컨테이너($NAME)가 없습니다. 먼저 bash scripts/testdb/load-ktomok-docker.sh" >&2; exit 1; }
run() { docker exec -i -e MYSQL_PWD="$PASS" "$NAME" mysql -uroot --default-character-set=utf8mb4 tomokmall_test; }

cols="$(echo "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='tomokmall_test' AND TABLE_NAME='rag_documents' AND COLUMN_NAME='source_type';" | run | tail -1)"
if [ "$cols" = "0" ]; then
  for f in 01_rag_documents_source_columns_DRAFT.sql 02_rag_documents_legal_candidates_DRAFT.sql 06_rag_documents_verified_clauses_DRAFT.sql 07_LOCAL_TEST_ONLY_activate_verified_clauses.sql; do
    sed '/^DRAFT_STOP_/d' "$D/$f" | run
  done
fi
echo "SELECT source_type, verify_level, is_active, COUNT(*) AS n FROM rag_documents GROUP BY source_type, verify_level, is_active;" | run
