#!/usr/bin/env bash
# 운영 DB 반영 리허설 — 운영과 같은 구조의 로컬 *_test DB 에 두 가지 DB 변경을 문서 순서대로 적용·확인·되돌리기.
#
# 책임: ① 운영 유사 DB(스키마·기본 마이그레이션·ktomok 상품·사내 rag_documents 5건만 — 법령 초안 01~07·외부 자료 테이블 없음) 생성
#       ② 적용 전 스냅샷(테이블 목록·칸 수·행 수·CHECKSUM·사내 문서 지문)
#       ③ 제조사 자료: 고정 '[리허설]' 승인 행(별도 *_test 원본 DB) → export-approved.php 로 내보내기 → 10 → 20 → 30(지문 = 내보낼 때 값)
#       ④ 법령 근거: 칸 추가 마이그레이션 → 10 → 30 → 20(임시 폴더에 생성, 리허설 검토자) → 30
#          (적용 파일은 모두 두 번 실행해 재실행 안전 확인)
#       ⑤ 단계마다 상담 점검(rag_build_prompt·tool_material_info — OpenAI 호출 없음). 운영 배포 코드(DEPLOYED_COMMIT, 기본 02a98c2)를
#          git archive 로 임시 폴더에 풀어 같은 점검(DB 만 먼저 바꿔도 배포 코드가 안전한지). 커밋이 없으면 SKIP
#          적용 파일은 phpMyAdmin 처럼 파일 전체를 한 번에, 연결 정렬 utf8mb4_unicode_ci(phpMyAdmin 기본)로 실행
#       ⑥ 두 되돌리기(90) 적용 → 적용 전 스냅샷과 같음 확인 → PASS/FAIL 표, 하나라도 FAIL 이면 종료 코드 1
# 금지: 운영 DB 접속. 호스트는 127.0.0.1/localhost 만, DB 이름은 *_test 만(공용 tomokmall_test 는 쓰지 않음).
#       비밀번호는 파일·화면에 남기지 않는다. 생성한 20_activate_ready.sql·내보내기 파일은 임시 폴더에만(끝나면 삭제).
#
# 사용: bash scripts/testdb/rehearse-prod-apply.sh            (MYSQL_PWD 가 없으면 터미널에서 입력, 비어 있으면 Enter)
#       MYSQL_CMD="mysql -h127.0.0.1 -uroot" MYSQL_PWD= bash scripts/testdb/rehearse-prod-apply.sh [--keep]
#       --keep: 끝나도 리허설 DB·임시 폴더를 지우지 않음(조사용)
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
REH="${REHEARSAL_DB:-tomokmall_rehearsal_test}"
SRC="${REHEARSAL_SRC_DB:-tomokmall_rehearsal_src_test}"
MYSQL_CMD="${MYSQL_CMD:-mysql -h127.0.0.1 -uroot}"
KEEP=0; REAL=""
for a in "$@"; do case "$a" in --keep) KEEP=1 ;; --real-export=*) REAL="${a#--real-export=}" ;; esac; done
# --real-export=폴더: 사용자 PC 의 실제 이관 파일(export-approved.php 결과: 10·20·30·export-report.md)로 리허설 — 고정 자료 대신 실제 14행으로 운영 적용·상담 점검
if [ -n "$REAL" ]; then for f in 10_create_tables.sql 20_insert_approved.sql 30_post_check_readonly.sql 90_rollback.sql export-report.md; do [ -f "$REAL/$f" ] || { echo "실제 이관 폴더에 $f 가 없습니다: $REAL" >&2; exit 2; }; done; fi
REVIEWER="리허설 검토자"
REVIEWED_ON="$(date +%Y-%m-%d)"
PA="$ROOT_DIR/docs/03-analysis/ai-sourcing/prod-apply"
MIG="$ROOT_DIR/database/20261102_rag_documents_legal_columns.sql"

for d in "$REH" "$SRC"; do
  case "$d" in *_test) ;; *) echo "DB 이름은 *_test 만 허용합니다: $d" >&2; exit 2 ;; esac
  [ "$d" = "tomokmall_test" ] && { echo "공용 tomokmall_test 는 리허설에 쓰지 않습니다." >&2; exit 2; }
done
[ "$REH" = "$SRC" ] && { echo "리허설 DB 와 내보내기 원본 DB 는 달라야 합니다." >&2; exit 2; }
case "$MYSQL_CMD" in *-h127.0.0.1*|*-hlocalhost*|*"-h 127.0.0.1"*|*"-h localhost"*) ;; *) echo "로컬 호스트(-h127.0.0.1/-hlocalhost)만 허용합니다." >&2; exit 2 ;; esac
case "$MYSQL_CMD" in *-h127.0.0.1*|*"-h 127.0.0.1"*) DBH=127.0.0.1 ;; *) DBH=localhost ;; esac
DBU="$(printf '%s' "$MYSQL_CMD" | sed -nE 's/.*-u ?([^ ]+).*/\1/p')"; DBU="${DBU:-root}"
DBP="$(printf '%s' "$MYSQL_CMD" | sed -nE 's/.*-P ?([0-9]+).*/\1/p')"; [ -n "$DBP" ] && DBH="$DBH;port=$DBP"   # 사용자 PC MySQL 8.0(예: -P3308) — PHP 보조 스크립트도 같은 서버로

if [ -z "${MYSQL_PWD+x}" ]; then
  if [ -t 0 ]; then read -rsp "로컬 DB 비밀번호(없으면 Enter): " MYSQL_PWD; echo; else MYSQL_PWD=""; fi
fi
export MYSQL_PWD

TMP="$(mktemp -d "${TMPDIR:-/tmp}/rehearse-prod-apply.XXXXXX")"
sql() { $MYSQL_CMD --default-character-set=utf8mb4 "$@"; }
cleanup() {
  if [ "$KEEP" = 1 ]; then echo "(--keep) 남김: DB $REH · $SRC · 폴더 $TMP"; return; fi
  echo "DROP DATABASE IF EXISTS \`$REH\`; DROP DATABASE IF EXISTS \`$SRC\`;" | sql 2>/dev/null
  rm -rf "$TMP"
}
trap cleanup EXIT

RESULTS="$TMP/results.tsv"; : > "$RESULTS"
rec() { printf '%s\t%s\t%s\n' "$1" "$2" "${3:-}" >> "$RESULTS"; printf '  %s  %s  %s\n' "$1" "$2" "${3:-}"; }
check() { if [ "$2" = "$3" ]; then rec PASS "$1" "$2"; else rec FAIL "$1" "$2 (기대 $3)"; fi; }
# 줄바꿈(CRLF/LF)만 다른 것은 같은 파일로 본다 — Windows Git(core.autocrlf, .gitattributes '* text=auto')은 .sql 을 CRLF 로 꺼내고 생성기는 LF 로 씀(2026-11-02 사용자 PC FAIL 3 원인)
same_text() { cmp -s <(tr -d '\r' < "$1") <(tr -d '\r' < "$2"); }
q() { echo "$1" | sql -N "$REH" 2>&1; }
# phpMyAdmin 처럼 파일 전체를 한 번에 실행(오류가 나면 거기서 멈춤). 연결 정렬은 phpMyAdmin 기본(utf8mb4_unicode_ci)
apply() { local label="$1" file="$2" db="${3:-$REH}" out
  if out="$({ echo 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;'; cat "$file"; } | sql "$db" 2>&1)"; then rec PASS "$label" "$(basename "$file")"; else rec FAIL "$label" "$(basename "$file"): $(echo "$out" | tail -1)"; fi; }
helper() { DB_HOST="$DBH" DB_NAME="$1" DB_USER="$DBU" DB_PASS="$MYSQL_PWD" php "$ROOT_DIR/scripts/testdb/rehearse-prod-apply.php" "${@:2}"; }
probe() { helper "$REH" "$@" > "$TMP/h.out" 2> "$TMP/h.err"; local c=$?
  cat "$TMP/h.out" | while IFS=$'\t' read -r s n d; do [ -n "$s" ] && rec "$s" "$n" "$d"; done
  [ -s "$TMP/h.err" ] && rec FAIL "보조 스크립트 오류($1)" "$(tail -1 "$TMP/h.err")"
  [ "$c" -le 1 ] || rec FAIL "보조 스크립트 실행($1)" "종료 코드 $c"; }

echo "== 0. 운영 유사 DB 만들기: $REH ($(echo 'SELECT VERSION();' | sql -N))"
echo "DROP DATABASE IF EXISTS \`$REH\`; DROP DATABASE IF EXISTS \`$SRC\`;
      CREATE DATABASE \`$REH\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE DATABASE \`$SRC\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | sql || exit 1
# setup-local-test-db.sh 와 같은 MySQL 8.0 보정(MariaDB 전용 IF NOT EXISTS 제거) — 법령 초안 01~07·외부 자료 테이블은 넣지 않음(운영에 없음)
fix() { sed -E 's/(ADD (COLUMN|UNIQUE KEY|KEY|INDEX)) IF NOT EXISTS/\1/g; /DROP INDEX IF EXISTS/d; /ADD FULLTEXT KEY ft_products_search/d' "$1"; }
{
  fix "$ROOT_DIR/database/schema.sql"
  for f in add_users_auth add_ai_sessions add_pricing add_vendors add_rag_documents add_category_consult_guide add_chat_logs; do fix "$ROOT_DIR/database/$f.sql"; done
  cat "$ROOT_DIR/data/ktomok/import.sql"
  for f in add_ai_orders add_sites add_user_address add_reorder 20261023_add_favorite_products 20261030_estimates_missing_columns; do fix "$ROOT_DIR/database/$f.sql"; done
} | sql "$REH" >/dev/null 2>"$TMP/build.err" || { echo "운영 유사 DB 생성 실패: $(tail -3 "$TMP/build.err")" >&2; exit 1; }
check "운영 유사 DB: rag_documents 칸 수" "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rag_documents';")" 9
check "운영 유사 DB: 사내 문서(활성)" "$(q "SELECT CONCAT(COUNT(*), '/', SUM(is_active)) FROM rag_documents;")" 5/5
check "운영 유사 DB: 외부 자료 테이블 없음" "$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'external_product%';")" 0
check "운영 유사 DB: ktomok 상품 있음" "$(q "SELECT COUNT(*) > 0 FROM products WHERE external_source='ktomok' AND is_active=1;")" 1

echo "== 1. 적용 전 스냅샷 · 사전 점검 SELECT · 상담 점검(운영 현재 구조)"
helper "$REH" snapshot > "$TMP/before.json" || { echo "스냅샷 실패" >&2; exit 1; }
rec PASS "적용 전 스냅샷" "테이블 $(grep -c '"cols_sha"' "$TMP/before.json")개"
check "사전 점검: 버전 8.0.x" "$(q "SELECT VERSION() LIKE '8.0.%';")" 1
check "사전 점검: NO_BACKSLASH_ESCAPES 아님(문자열 이스케이프 전제)" "$(q "SELECT FIND_IN_SET('NO_BACKSLASH_ESCAPES', @@SESSION.sql_mode) = 0;")" 1
check "사전 점검: 새 칸 0개" "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rag_documents' AND COLUMN_NAME IN ('source_type','author','verify_level');")" 0
check "사전 점검: 색인 idx_source_type·idx_review 없음" "$(q "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rag_documents' AND INDEX_NAME IN ('idx_source_type','idx_review');")" 0
probe probes --phase=before
# 운영에 배포된 코드(기록: docs/03-analysis/remaining-work-2026-10-30.md — main 02a98c2)로도 같은 DB 를 점검 — DB 변경만 먼저 해도 안전한지
DEPLOYED_COMMIT="${DEPLOYED_COMMIT:-02a98c2}"; DEP=""
mkdir -p "$TMP/deployed"
if git -C "$ROOT_DIR" archive "$DEPLOYED_COMMIT" app 2>/dev/null | tar -x -C "$TMP/deployed" 2>/dev/null && [ -f "$TMP/deployed/app/rag.php" ]; then
  DEP="$TMP/deployed"; probe probes --phase=before --code-root="$DEP" --code-label="$DEPLOYED_COMMIT"
else rec SKIP "배포 코드($DEPLOYED_COMMIT) 점검" "이 저장소에 커밋이 없음(얕은 복제 등)"; fi
depprobe() { [ -n "$DEP" ] && probe probes --phase="$1" --code-root="$DEP" --code-label="$DEPLOYED_COMMIT" ${REAL:+--real=1}; }

if [ -n "$REAL" ]; then
  echo "== 2. 제조사 자료 — 실제 이관 파일($REAL) → 10 → 20 → 30"
  mkdir -p "$TMP/ext" && cp "$REAL"/10_create_tables.sql "$REAL"/20_insert_approved.sql "$REAL"/30_post_check_readonly.sql "$REAL"/90_rollback.sql "$REAL"/export-report.md "$TMP/ext/"
  EXPN="$(sed -nE 's/.*이관 가능 ([0-9]+).*/\1/p' "$TMP/ext/export-report.md" | head -1)"
  rec PASS "제조사 실제 이관 파일 사용" "$(grep -m1 '승인 행' "$TMP/ext/export-report.md" | sed 's/^- //')"
else
echo "== 2. 제조사 자료 — 고정 승인 행 내보내기(원본 $SRC) → 10 → 20 → 30"
  sql "$SRC" < "$ROOT_DIR/database/20261029_external_products.sql" || exit 1
  helper "$SRC" ext-fixture > "$TMP/h.out" 2>&1; while IFS=$'\t' read -r s n d; do [ -n "$s" ] && rec "$s" "$n" "$d"; done < "$TMP/h.out"
  printf '%s' '{"sources":[{"key":"rehearsal_fix","domain":"rehearsal.example","permission":"facts_if_clear"}]}' > "$TMP/sources.json"
  if EXT_SOURCES_FILE="$TMP/sources.json" DB_HOST="$DBH" DB_NAME="$SRC" DB_USER="$DBU" DB_PASS="$MYSQL_PWD" \
     php "$ROOT_DIR/scripts/external/export-approved.php" --out="$TMP/ext" --expect=3 > "$TMP/export.log" 2>&1; then
    rec PASS "제조사 내보내기(export-approved.php --expect=3)" "$(grep -m1 '승인 행' "$TMP/ext/export-report.md" | sed 's/^- //')"
  else
    rec FAIL "제조사 내보내기(export-approved.php --expect=3)" "$(tail -1 "$TMP/export.log")"
  fi
  EXPN=3
fi
FP="$(sed -nE 's/.*지문\(fingerprint\): `([0-9a-f]{64})`.*/\1/p' "$TMP/ext/export-report.md" 2>/dev/null)"
check "제조사 내보내기: 보고서 지문 있음" "$([ ${#FP} = 64 ] && echo 1 || echo 0)" 1
check "제조사 20 에 미승인·매칭·참고가 없음" "$(grep -cF -e '[리허설] 미승인' -e 'INSERT INTO external_product_matches' -e "'pending'" "$TMP/ext/20_insert_approved.sql")" 0
same_text "$TMP/ext/10_create_tables.sql" "$ROOT_DIR/database/20261029_external_products.sql"; check "제조사 10 = database/20261029_external_products.sql" "$?" 0
for i in 1 2; do apply "제조사 10 적용 ($i회째)" "$TMP/ext/10_create_tables.sql"; done
for i in 1 2; do apply "제조사 20 적용 ($i회째)" "$TMP/ext/20_insert_approved.sql"; done
check "제조사 20 두 번 실행 후 행 수(중복 없음)" "$(q "SELECT COUNT(*) FROM external_products;")" "$EXPN"
apply "제조사 30 실행(읽기 전용)" "$TMP/ext/30_post_check_readonly.sql"
probe ext-check --file="$TMP/ext/30_post_check_readonly.sql" --fp="$FP" --expect="$EXPN"
probe probes --phase=ext ${REAL:+--real=1}
depprobe ext

echo "== 3. 법령 근거 — 칸 추가 → 10 → 30 → 20 → 30"
for i in 1 2; do apply "법령 칸 추가 마이그레이션 ($i회째)" "$MIG"; done
check "법령 칸 추가 후 rag_documents 칸 수" "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rag_documents';")" 25
check "법령 칸 추가 후 색인 2개" "$(q "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rag_documents' AND INDEX_NAME IN ('idx_source_type','idx_review');")" 2
check "법령 칸 추가 후 기존 행 internal·unreviewed·unknown·NULL" "$(q "SELECT COUNT(*) FROM rag_documents WHERE source_type='internal' AND review_status='unreviewed' AND legal_status='unknown' AND verify_level IS NULL;")" 5
mkdir -p "$TMP/legal"
if php "$ROOT_DIR/scripts/legal-sources/build_prod_sql.php" --reviewer="$REVIEWER" --reviewed-on="$REVIEWED_ON" --out="$TMP/legal" > "$TMP/legal.log" 2>&1; then
  rec PASS "법령 20 생성(임시 폴더, 검토자 '$REVIEWER' · $REVIEWED_ON)" "$(tail -1 "$TMP/legal.log")"
else rec FAIL "법령 20 생성" "$(tail -1 "$TMP/legal.log")"; fi
for f in 10_register_inactive.sql 30_post_check_readonly.sql 90_rollback.sql; do
  same_text "$TMP/legal/$f" "$PA/$f"; check "저장소 $f = 생성기 출력(최신)" "$?" 0
done
check "법령 20: V21·V22 없음" "$(grep -cE '/\* V2[12] \*/|44 50 25' "$TMP/legal/20_activate_ready.sql")" 0
check "법령 20: 활성화 대상 20건" "$(grep -cE '^  /\* V[0-9]+ \*/' "$TMP/legal/20_activate_ready.sql")" 20
for i in 1 2; do apply "법령 10 적용 ($i회째)" "$PA/10_register_inactive.sql"; done
check "법령 10: V21·V22 등록·비활성" "$(q "SELECT CONCAT(COUNT(*), '/', SUM(is_active)) FROM rag_documents WHERE author='검증 조문(2-1단계)' AND (title LIKE '%KDS 44 50 25%' OR title LIKE '%KCS 44 50 25%');")" 2/0
check "법령 10 두 번 실행 후 등록 행(중복 없음)·활성" "$(q "SELECT CONCAT(COUNT(*), '/', SUM(is_active)) FROM rag_documents WHERE author IN ('검증 조문(2-1단계)','AI 조사 초안(검색 기반)');")" 66/0
apply "법령 30 실행(읽기 전용, 10 후)" "$PA/30_post_check_readonly.sql"
probe legal-check --file="$PA/30_post_check_readonly.sql" --phase=registered --before="$TMP/before.json"
probe probes --phase=registered ${REAL:+--real=1}
depprobe registered
for i in 1 2; do apply "법령 20 적용 ($i회째)" "$TMP/legal/20_activate_ready.sql"; done
apply "법령 30 실행(읽기 전용, 20 후)" "$PA/30_post_check_readonly.sql"
probe legal-check --file="$PA/30_post_check_readonly.sql" --phase=activated --before="$TMP/before.json" --reviewer="$REVIEWER" --reviewed-on="$REVIEWED_ON"
probe probes --phase=activated ${REAL:+--real=1}
depprobe activated

echo "== 4. 되돌리기 — 법령 90 [A]·[B]·[C], 제조사 90 + 테이블 삭제"
sed -n '1,/^-- \[B\]/p' "$PA/90_rollback.sql" | sed '$d' > "$TMP/90A.sql"
sed -n '/^-- \[B\]/,/^-- \[C\]/p' "$PA/90_rollback.sql" | sed '$d' > "$TMP/90B.sql"
sed -n '/^-- \[되돌리기/,/updated_by;$/p' "$MIG" | sed '1d; s/^--  *//' > "$TMP/90C.sql"
apply "법령 90 [A] 활성화 해제" "$TMP/90A.sql"
check "법령 90 [A] 후 활성 0 · 등록 66 유지" "$(q "SELECT CONCAT(SUM(is_active), '/', COUNT(*)) FROM rag_documents WHERE author IN ('검증 조문(2-1단계)','AI 조사 초안(검색 기반)');")" 0/66
probe legal-check --file="$PA/30_post_check_readonly.sql" --phase=registered --before="$TMP/before.json"
apply "법령 90 [B] 등록 삭제" "$TMP/90B.sql"
check "법령 90 [B] 후 등록 행 0 · 사내 문서 5" "$(q "SELECT CONCAT(SUM(author IS NOT NULL), '/', COUNT(*)) FROM rag_documents;")" 0/5
check "법령 90 [C] 추출 SQL(머리말 되돌리기): 칸 16·색인 2" "$(grep -o 'DROP COLUMN' "$TMP/90C.sql" | wc -l | tr -d ' ')/$(grep -o 'DROP INDEX' "$TMP/90C.sql" | wc -l | tr -d ' ')" 16/2
apply "법령 90 [C] 칸·색인 삭제(마이그레이션 머리말)" "$TMP/90C.sql"
apply "제조사 90 이관 행 삭제" "$TMP/ext/90_rollback.sql"
check "제조사 90 후 이관 행 0" "$(q "SELECT COUNT(*) FROM external_products;")" 0
sed -nE 's/^-- 테이블까지 지우려면[^:]*: (DROP TABLE .*)$/\1/p' "$TMP/ext/90_rollback.sql" > "$TMP/90ext_drop.sql"
check "제조사 90 테이블 삭제 문구 추출" "$(grep -o 'DROP TABLE' "$TMP/90ext_drop.sql" | wc -l | tr -d ' ')" 2
apply "제조사 테이블 삭제(운영에 원래 없음)" "$TMP/90ext_drop.sql"
probe snap-compare --before="$TMP/before.json"
probe probes --phase=rolledback

echo
echo "== 결과"
printf '| 결과 | 항목 | 내용 |\n|---|---|---|\n'
while IFS=$'\t' read -r s n d; do printf '| %s | %s | %s |\n' "$s" "$n" "$d"; done < "$RESULTS"
NP="$(grep -c '^PASS' "$RESULTS")"; NF="$(grep -c '^FAIL' "$RESULTS")"
echo
echo "합계: PASS $NP · FAIL $NF"
[ "$NF" = 0 ]
