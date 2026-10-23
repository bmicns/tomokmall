#!/usr/bin/env bash
# 배포 전 DB 영향 점검(읽기 전용·DB 접속 없음) — 배포할 커밋 범위의 변경에서 운영 DB에 영향을 줄 수 있는 항목을 찾는다.
# 사용: scripts/predeploy-db-check.sh [기준커밋(운영에 배포된 커밋, 기본 origin/main)] [대상커밋(기본 HEAD)]
#   1) 배포 대상(public/·app/·database/) 변경 파일
#   2) 변경된 PHP의 추가 줄 중 데이터·구조 변경 SQL(INSERT/UPDATE/DELETE/REPLACE/CREATE/ALTER/DROP/TRUNCATE/RENAME)
#   3) database/*.sql 추가·변경(= 운영 DB 반영이 필요한 작업 → 별도 승인), MariaDB 전용 문법·위험 문장
#   4) 새로 추가된 PHP 줄이 참조하는 테이블 중 database/*.sql 어디에도 CREATE가 없는 테이블(운영에만 있거나 없는 테이블)
# 결과: 0 = DB 영향 없음 / 1 = 사람이 검토·승인할 항목 있음. 이 스크립트는 DB·서버에 접속하지 않는다.
set -u
cd "$(dirname "$0")/.."
BASE="${1:-origin/main}"
HEAD_REF="${2:-HEAD}"
G="git -c core.quotepath=off"
review=0

if ! $G rev-parse -q --verify "$BASE^{commit}" >/dev/null; then echo "기준 커밋 '$BASE'을 찾을 수 없습니다."; exit 2; fi
echo "DB 영향 점검: $BASE..$HEAD_REF"

files=$($G diff --name-only "$BASE" "$HEAD_REF" -- public app database)
echo "[1] 배포 대상 변경 파일: $(printf '%s\n' "$files" | grep -c . || true)개"
printf '%s\n' "$files" | sed '/^$/d; s/^/    /'

echo "[2] 변경된 PHP의 추가 줄 중 DB 변경 SQL"
sqlw=$($G diff -U0 "$BASE" "$HEAD_REF" -- 'public/*.php' 'app/*.php' \
  | grep -E '^\+[^+]' | grep -nE "(INSERT[[:space:]]+(IGNORE[[:space:]]+)?INTO|UPDATE[[:space:]]+\`?[a-z_]+\`?[[:space:]]+SET|DELETE[[:space:]]+FROM|REPLACE[[:space:]]+INTO|CREATE[[:space:]]+TABLE|ALTER[[:space:]]+TABLE|DROP[[:space:]]+(TABLE|COLUMN|INDEX)|TRUNCATE|RENAME[[:space:]]+TABLE)" || true)
if [ -n "$sqlw" ]; then echo "  ⚠ 검토 필요(쓰기 SQL 추가):"; printf '%s\n' "$sqlw" | sed 's/^/    /'; review=1; else echo "  ✓ 없음"; fi

echo "[3] database/*.sql 변경"
sqlf=$(printf '%s\n' "$files" | grep -E '^database/.*\.sql$' || true)
if [ -z "$sqlf" ]; then
  echo "  ✓ 없음 (운영 DB 반영 불필요)"
else
  review=1
  echo "  ⚠ 운영 DB 반영이 필요한 SQL 파일 — DEPLOYMENT.md 6장 절차·별도 승인 대상:"
  for f in $sqlf; do
    body=$($G show "$HEAD_REF:$f" 2>/dev/null) || { echo "    $f (삭제됨)"; continue; }
    echo "    $f"
    printf '%s\n' "$body" | grep -niE "ADD (COLUMN|INDEX|KEY) IF NOT EXISTS|DROP (COLUMN|INDEX) IF EXISTS" | sed 's/^/      MariaDB 전용 문법(MySQL 8.0 오류): /'
    printf '%s\n' "$body" | grep -niE "\bDROP (TABLE|DATABASE)\b|\bTRUNCATE\b|ALTER TABLE .* DROP " | sed 's/^/      위험(데이터 손실): /'
    printf '%s\n' "$body" | grep -niE "^\s*(DELETE FROM|UPDATE)\b" | grep -viE "\bWHERE\b" | sed 's/^/      WHERE 없는 변경 가능성(다음 줄 확인): /'
  done
fi

echo "[4] 새로 추가된 PHP 줄이 참조하는 테이블 중 저장소 SQL에 CREATE가 없는 테이블"
created=$(for f in $($G ls-tree --name-only "$HEAD_REF" database/ | grep -E '\.sql$'); do $G show "$HEAD_REF:$f"; done \
          | grep -oiE "CREATE TABLE (IF NOT EXISTS )?\`?[a-z_]+" | awk '{print tolower($NF)}' | tr -d '`' | sort -u)
missing=""
for t in $($G diff -U0 "$BASE" "$HEAD_REF" -- 'public/*.php' 'app/*.php' | grep -E '^\+[^+]' \
           | grep -oiE "(FROM|JOIN|INTO|UPDATE)[[:space:]]+\`?[a-z_]{3,}\`?([[:space:]]|$)" | awk '{print tolower($2)}' | tr -d '`' | sort -u); do
  case " current_timestamp dual " in *" $t "*) continue;; esac
  printf '%s\n' "$created" | grep -qx "$t" || missing="$missing $t"
done
if [ -n "$missing" ]; then
  echo "  ⚠ 운영에 테이블이 있는지 확인 필요(없으면 코드가 빈 결과로 처리되는지 확인):$missing"
  review=1
else
  echo "  ✓ 없음"
fi

echo
if [ "$review" -eq 0 ]; then echo "결과: DB 영향 없음 (코드 배포만)"; else echo "결과: 검토 항목 있음 — 위 ⚠ 항목을 사용자에게 보고하고 승인 후 진행"; fi
exit "$review"
