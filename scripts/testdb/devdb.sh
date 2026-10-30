#!/usr/bin/env bash
# 운영 복제 개발 DB 준비·재복제·스냅샷·복원 (scripts/testdb/devdb.sh) — 로컬 Docker MySQL 8.0 전용
#
# 책임: 세션이 바뀌어도 같은 개발 DB(tomokmall_test)를 몇 분 안에 다시 만들기
#   up                              dockerd·MySQL 8.0 컨테이너(127.0.0.1만)·DB/계정·.env.local 준비 (데이터 없음)
#   refresh <10.csv> <02.csv> [03.csv] [--mask-contacts]
#                                   운영 phpMyAdmin 내보내기로 재구축: 운영 구조 DDL → 비식별 검사·적재 → 운영 지문 대조
#                                   → [TEST] 계정 → 스냅샷 저장. 대조가 하나라도 다르면 스냅샷을 만들지 않고 멈춘다
#   restore <스냅샷.sql.gz>         다음 세션: 스냅샷으로 복원 → [TEST] 계정 비밀번호 재설정
#   snapshot                        현재 개발 DB를 .devdb/ 에 스냅샷(.sql.gz)으로 저장
#   status                          컨테이너·DB·복제 기준(스냅샷 정보)·행 수
#   verify [http://127.0.0.1:8080]  실제 데이터 기능 검증(작업별 목록·검색 자동완성·목록 상단 배너). 서버가 없으면 잠시 띄운다
#
# 금지: 운영 DB 접속(접속 경로 없음 — 입력은 사용자가 phpMyAdmin에서 내보낸 CSV/스냅샷 파일뿐),
#       운영 데이터·비밀번호를 저장소에 남기기(.devdb/ 와 .env.local 은 Git 제외), 비밀번호 출력.
# 안전장치: DB 이름 *_test + 호스트 127.0.0.1 만. PHP 도구는 common.php 가드를 한 번 더 거친다.
# 절차·SQL: docs/03-analysis/testdb/README.md 11장
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
CT="${DEVDB_CONTAINER:-tomok-devdb}"
IMG="${DEVDB_IMAGE:-mysql:8.0}"
PORT="${DEVDB_PORT:-3306}"
DB="tomokmall_test"
DBUSER="tomokmall_test"
DATA="$ROOT/.devdb"                 # 스냅샷·작업 파일(Git 제외)
ENVF="$ROOT/.env.local"
TESTDB_SQL="$ROOT/docs/03-analysis/testdb"
# 테스트 계정 이메일(비밀 아님 — .test 도메인). 비밀번호는 up 때 무작위로 만들어 .env.local 에만 둔다
T_CUST="test-customer@tomokmall.test"
T_CORP="test-corporate@tomokmall.test"

say()  { printf '%s\n' "$*"; }
die()  { printf '중단: %s\n' "$*" >&2; exit 1; }
rnd()  { head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24; }
root_sql() { docker exec -i "$CT" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 "$@"' -- "$@"; }
env_get() { [ -f "$ENVF" ] && grep -E "^$1=" "$ENVF" | tail -1 | cut -d= -f2- || true; }

# .env.local 의 KEY=값 설정(있으면 교체). 다른 키는 건드리지 않는다
env_set() {
  local k="$1" v="$2" tmp
  tmp="$(mktemp)"
  if [ -f "$ENVF" ]; then grep -vE "^$k=" "$ENVF" > "$tmp" || true; fi
  printf '%s=%s\n' "$k" "$v" >> "$tmp"
  cat "$tmp" > "$ENVF"; rm -f "$tmp"
  chmod 600 "$ENVF"
}

ensure_docker() {
  command -v docker >/dev/null || die "docker 가 없습니다(PC는 Docker Desktop 또는 README 9장 XAMPP 절차)."
  if ! docker info >/dev/null 2>&1; then
    command -v dockerd >/dev/null && [ "$(id -u)" = 0 ] || die "Docker 데몬이 꺼져 있습니다. Docker를 실행한 뒤 다시 시도하세요."
    say "· dockerd 시작"
    mkdir -p "$DATA"
    nohup dockerd > "$DATA/dockerd.log" 2>&1 &
    for _ in $(seq 1 30); do docker info >/dev/null 2>&1 && break; sleep 1; done
    docker info >/dev/null 2>&1 || die "dockerd 시작 실패(.devdb/dockerd.log)"
  fi
}

ensure_container() {
  if ! docker ps -a --format '{{.Names}}' | grep -qx "$CT"; then
    docker image inspect "$IMG" >/dev/null 2>&1 || { say "· $IMG 내려받기"; docker pull -q "$IMG" >/dev/null; }
    say "· 컨테이너 $CT 생성(127.0.0.1:$PORT)"
    docker run -d --name "$CT" -e MYSQL_ROOT_PASSWORD="$(rnd)" -p "127.0.0.1:$PORT:3306" "$IMG" \
      --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
  elif [ "$(docker inspect -f '{{.State.Running}}' "$CT")" != true ]; then
    docker start "$CT" >/dev/null
  fi
  say "· MySQL 준비 대기"
  # 첫 실행 때 진입점이 띄우는 초기화용 임시 서버(네트워크 없음)가 아니라 최종 서버(포트 3306)가 뜰 때까지 기다린다
  for _ in $(seq 1 90); do
    docker logs "$CT" 2>&1 | grep -q 'ready for connections.*port: 3306' && root_sql -N -e 'SELECT 1' >/dev/null 2>&1 && break
    sleep 2
  done
  root_sql -N -e 'SELECT 1' >/dev/null 2>&1 || die "MySQL 응답 없음(docker logs $CT 확인)"
}

# .env.local: 다른 DB(운영 등)를 가리키면 덮어쓰지 않고 멈춘다
ensure_env() {
  local cur; cur="$(env_get DB_NAME)"
  if [ -n "$cur" ] && [[ "$cur" != *_test ]]; then
    die ".env.local 의 DB_NAME 이 테스트 DB(*_test)가 아닙니다. 직접 확인 후 정리하세요(이 스크립트는 덮어쓰지 않음)."
  fi
  local pw; pw="$(env_get DB_PASS)"
  [ -n "$pw" ] && [ "$(env_get DB_USER)" = "$DBUSER" ] || pw="$(rnd)"
  env_set DB_HOST 127.0.0.1
  env_set DB_NAME "$DB"
  env_set DB_USER "$DBUSER"
  env_set DB_PASS "$pw"
  [ -n "$(env_get LOCAL_TEST_CUSTOMER_EMAIL)" ]     || env_set LOCAL_TEST_CUSTOMER_EMAIL "$T_CUST"
  [ -n "$(env_get LOCAL_TEST_CUSTOMER_PASSWORD)" ]  || env_set LOCAL_TEST_CUSTOMER_PASSWORD "$(rnd)"
  [ -n "$(env_get LOCAL_TEST_CORPORATE_EMAIL)" ]    || env_set LOCAL_TEST_CORPORATE_EMAIL "$T_CORP"
  [ -n "$(env_get LOCAL_TEST_CORPORATE_PASSWORD)" ] || env_set LOCAL_TEST_CORPORATE_PASSWORD "$(rnd)"
  # 계정은 이 DB에만 권한(컨테이너 안 계정 — 운영 권한과 무관)
  root_sql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DBUSER'@'%' IDENTIFIED BY '$pw';
ALTER USER '$DBUSER'@'%' IDENTIFIED BY '$pw';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DBUSER'@'%';
FLUSH PRIVILEGES;
SQL
}

recreate_db() {
  root_sql -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
}

meta_write() {   # .devdb/meta.txt — 복제 기준 기록(값·비밀번호 없음)
  {
    printf 'source=%s\n' "$1"
    printf 'loaded_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'git=%s\n' "$(git rev-parse --short HEAD 2>/dev/null || echo '?')"
    root_sql -N "$DB" -e "SELECT CONCAT('categories=', COUNT(*)) FROM categories; SELECT CONCAT('products=', COUNT(*), ' max_created=', IFNULL(MAX(created_at),'-')) FROM products; SELECT CONCAT('cost_price_set=', SUM(cost_price > 0)) FROM products;" 2>/dev/null
  } > "$DATA/meta.txt"
}

do_snapshot() {
  mkdir -p "$DATA"
  local f="$DATA/tomokmall_devdb_$(date -u +%Y%m%d-%H%M).sql.gz"
  # 스냅샷 앞에 기준 정보를 주석으로 남긴다(restore·status 가 읽음)
  { sed 's/^/-- devdb: /' "$DATA/meta.txt" 2>/dev/null || true
    docker exec "$CT" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --routines --no-tablespaces --default-character-set=utf8mb4 "$1"' -- "$DB"
  } | gzip -9 > "$f"
  chmod 600 "$f"
  say "· 스냅샷: ${f#$ROOT/} ($(du -h "$f" | cut -f1)) — 다음 세션 복원용. 저장소·운영 서버에 올리지 마세요."
}

test_accounts() {
  php scripts/local-test-accounts.php --apply | sed 's/^/  /'
}

cmd_up() {
  ensure_docker; ensure_container; ensure_env
  mkdir -p "$DATA"; chmod 700 "$DATA"
  say "✓ 개발 DB 준비: $DB @ 127.0.0.1:$PORT (.env.local 설정됨)"
}

cmd_refresh() {
  local opts=() files=()
  for a in "$@"; do case "$a" in --mask-contacts) opts+=("$a") ;; *) files+=("$a") ;; esac; done
  set -- "${files[@]}"
  [ $# -ge 2 ] || die "사용법: devdb.sh refresh <10_refresh_checks.csv> <02_prod_export.csv> [03_pricing.csv] [--mask-contacts]"
  local chk="$1"; shift
  for f in "$chk" "$@"; do [ -f "$f" ] || die "파일 없음: $f"; done
  cmd_up
  say "[1/5] 적재 전 검사(DB 변경 없음)"
  php scripts/testdb/load-export.php "$@" ${opts[@]+"${opts[@]}"} | sed 's/^/  /' || die "내보내기 파일 검사 실패 — 적재하지 않았습니다."
  say "[2/5] 운영 구조 DDL 생성·적용(빈 DB로 다시 만듦)"
  php scripts/testdb/build-schema.php "$chk" > "$DATA/prod_schema.sql"
  recreate_db
  root_sql "$DB" < "$DATA/prod_schema.sql"
  say "[3/5] 데이터 적재"
  php scripts/testdb/load-export.php "$@" ${opts[@]+"${opts[@]}"} --apply | sed 's/^/  /'
  say "[4/5] 운영 지문 대조"
  # 연락처 치환 적재면 상품 spec 이 바뀌므로 상품 지문(C2)만 비교에서 뺀다(나머지는 모두 일치해야 함)
  local ign=(); [ ${#opts[@]} -gt 0 ] && ign=(--ignore=C2)
  if ! php scripts/testdb/compare.php --sql="$TESTDB_SQL/10_refresh_checks.sql" ${ign[@]+"${ign[@]}"} "$chk" | sed 's/^/  /'; then
    die "운영 결과와 다른 블록이 있습니다. 스냅샷을 만들지 않았습니다(위 ✗ 확인)."
  fi
  say "[5/5] [TEST] 계정·스냅샷"
  test_accounts
  meta_write "phpMyAdmin 내보내기 $(basename "$chk") $(for f in "$@"; do printf '%s ' "$(basename "$f")"; done)"
  do_snapshot
  rm -f "$DATA/prod_schema.sql"
  say "✓ 재복제 완료. 내보낸 CSV 원본은 삭제해도 됩니다(스냅샷으로 복원 가능)."
}

cmd_restore() {
  [ $# -eq 1 ] && [ -f "$1" ] || die "사용법: devdb.sh restore <스냅샷.sql.gz>"
  cmd_up
  say "· 복원: $(basename "$1")"
  recreate_db
  gzip -dc "$1" | root_sql "$DB"
  gzip -dc "$1" | sed -n 's/^-- devdb: //p' > "$DATA/meta.txt" || true
  say "· [TEST] 계정 비밀번호를 이번 .env.local 값으로 맞춤"
  test_accounts
  cmd_status
}

cmd_status() {
  ensure_docker
  if ! docker ps --format '{{.Names}}' | grep -qx "$CT"; then say "컨테이너 $CT: 꺼짐 → devdb.sh up 또는 restore"; return 0; fi
  say "컨테이너 $CT: 실행 중 (127.0.0.1:$PORT)"
  say "복제 기준:"; sed 's/^/  /' "$DATA/meta.txt" 2>/dev/null || say "  (기록 없음 — 아직 refresh/restore 하지 않음)"
  say "현재 행 수:"
  root_sql -N "$DB" -e "SELECT CONCAT('  categories ', COUNT(*)) FROM categories; SELECT CONCAT('  products ', COUNT(*), ' (판매중 ', SUM(is_active), ', 재고>0 ', SUM(stock_qty>0), ')') FROM products; SELECT CONCAT('  category_relations ', COUNT(*)) FROM category_relations; SELECT CONCAT('  users ', COUNT(*), ' ([TEST] 계정만)') FROM users;" 2>/dev/null \
    || say "  (테이블 없음 — refresh 또는 restore 필요)"
}

cmd_verify() {
  local base="${1:-http://127.0.0.1:8080}" pid=""
  if ! curl -s -o /dev/null --max-time 3 "$base/"; then
    local hp="${base#http://}"
    php -S "$hp" -t public > "$DATA/php-server.log" 2>&1 & pid=$!
    sleep 1
  fi
  local rc=0
  php scripts/testdb/verify-work-list.php "$base" || rc=1
  php scripts/testdb/verify-suggest.php "$base" || rc=1
  php scripts/testdb/verify-list-banner.php "$base" || rc=1
  # 검증 중 검색 페이지 조회가 search_logs 에 쌓이므로 비운다(운영 복제 대상 아님)
  root_sql "$DB" -e "DELETE FROM search_logs" 2>/dev/null || true
  [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
  return $rc
}

case "${1:-}" in
  up)       cmd_up ;;
  refresh)  shift; cmd_refresh "$@" ;;
  restore)  shift; cmd_restore "$@" ;;
  snapshot) ensure_docker; meta_write "$(sed -n 's/^source=//p' "$DATA/meta.txt" 2>/dev/null || echo '현재 DB')"; do_snapshot ;;
  status)   cmd_status ;;
  verify)   shift; cmd_verify "$@" ;;
  *) sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
