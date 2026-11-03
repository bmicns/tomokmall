#!/usr/bin/env bash
# 원격 관리 API 로컬 통합 테스트 (scripts/remote-ops/test-local.sh) — 운영 서버·운영 DB 접속 없음
#
# 준비(모두 이 컴퓨터 안, 끝나면 정리):
#   · Docker MySQL 8.0 컨테이너 tomok-ropsdb (127.0.0.1:3317 만 열림)
#   · 임시 테스트 DB tomokmall_rops_test — database/*.sql 구조 + [TEST] 표시 가상 데이터(운영 데이터 아님, 운영 복제 DB 와 별개)
#   · 사이트 사본(임시 폴더) + PHP 내장 서버 127.0.0.1:8099  ← 파일 배포·복구 테스트는 이 사본만 바꾼다(저장소 파일 변경 없음)
#   · CLI 비교 기준용 임시 git 저장소
#   · 테스트 키·DB 비밀번호·[TEST] 관리자 비밀번호는 실행마다 무작위로 만들어 임시 폴더에만 둔다(출력·커밋 안 함)
# 사용: bash scripts/remote-ops/test-local.sh        ROPS_KEEP=1 이면 임시 폴더·서버 로그 유지
#       ROPS_SERVER_IMAGE=<pdo_mysql 이 있는 PHP 이미지> 면 서버를 그 PHP 로 실행(운영 8.4 확인용)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CT="${ROPS_DB_CONTAINER:-tomok-ropsdb}"
DBPORT="${ROPS_DB_PORT:-3317}"
WEBPORT="${ROPS_WEB_PORT:-8099}"
DB="tomokmall_rops_test"
DBUSER="rops_test"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/rops-test.XXXXXX")"
chmod 700 "$WORK"
rnd() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "${1:-24}"; }
say() { printf '· %s\n' "$*"; }
die() { printf '중단: %s\n' "$*" >&2; exit 1; }

SERVER_PID=""
cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
  if [ "${ROPS_KEEP:-0}" = "1" ]; then say "임시 폴더 유지: $WORK"; else rm -rf "$WORK"; fi
}
trap cleanup EXIT

command -v docker >/dev/null || die "docker 가 필요합니다"
command -v php >/dev/null || die "php 가 필요합니다"

# ── 1) MySQL 컨테이너 ──
if ! docker inspect "$CT" >/dev/null 2>&1; then
  say "MySQL 8.0 컨테이너 시작($CT, 127.0.0.1:$DBPORT)"
  docker run -d --name "$CT" -p "127.0.0.1:${DBPORT}:3306" -e MYSQL_ROOT_PASSWORD="$(rnd 24)" mysql:8.0 \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --ngram-token-size=2 >/dev/null
elif [ "$(docker inspect -f '{{.State.Running}}' "$CT")" != "true" ]; then
  docker start "$CT" >/dev/null
fi
root_sql() { docker exec -i "$CT" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 "$@"' -- "$@"; }
for _ in $(seq 1 60); do root_sql -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 2; done
root_sql -e 'SELECT 1' >/dev/null 2>&1 || die "MySQL 준비 실패"

# ── 2) 테스트 DB(가상 데이터) ──
DBPASS="$(rnd 24)"
say "테스트 DB 재생성: $DB"
root_sql <<SQL
DROP DATABASE IF EXISTS \`$DB\`;
CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DBUSER'@'%' IDENTIFIED BY '$DBPASS';
ALTER USER '$DBUSER'@'%' IDENTIFIED BY '$DBPASS';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DBUSER'@'%';
FLUSH PRIVILEGES;
SQL
load() { root_sql --force "$DB" < "$1" > /dev/null 2>"$WORK/load.err" || true; }
for f in schema.sql add_users_auth.sql add_vendors.sql add_pricing.sql 20261103_remote_ops.sql; do load "$ROOT/database/$f"; done
ADMIN_PW="$(rnd 20)"
ADMIN_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PW")"
CUST_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$(rnd 20)")"
root_sql "$DB" <<SQL
ALTER TABLE categories ADD COLUMN discount_corporate TINYINT UNSIGNED NOT NULL DEFAULT 0;
INSERT INTO vendors (id, name) VALUES (1, '[TEST]대성산업'), (2, '[TEST]다른업체');
INSERT INTO products (id, category_id, name, brand, spec, price_b2c, price_b2b, cost_price, stock_qty) VALUES
 (1001, 2, '[TEST] 투수블록 200x100', '[TEST]대성산업', '규격: 200*100*60 | 단위: ㎡', 12000, 10000, 8000, 50),
 (1002, 2, '[TEST] 투수블록 300x300', '[TEST]대성산업', '규격: 300*300*60 | 단위: ㎡', 15000, 13000, 9000, 20),
 (1003, 2, '[TEST] 투수블록 컬러', '[TEST]대성산업', '규격: 200*200*60 | 단위: ㎡', 16000, 14000, 9500, 0),
 (1004, 2, '[TEST] 보도블록 일반', '[TEST]다른업체', '규격: 200*100*60', 9000, 8000, 6000, 10),
 (1005, 3, '[TEST] 그레이팅 300', '[TEST]다른업체', '규격: 300*1000', 30000, 27000, 20000, 5);
INSERT INTO product_vendors (product_id, vendor_id, supply_price, is_primary) VALUES (1001,1,8000,1),(1002,1,9000,1),(1003,1,9500,1),(1004,2,6000,1);
INSERT INTO users (role, name, email, password_hash, phone) VALUES
 ('admin', '[TEST] 관리자', 'test-admin@tomokmall.test', '$ADMIN_HASH', '010-0000-0000'),
 ('customer', '[TEST] 고객', 'test-customer@tomokmall.test', '$CUST_HASH', '010-1111-2222');
SQL

# ── 3) 사이트 사본 + 설정 ──
SITE="$WORK/site"
mkdir -p "$SITE"
cp -a "$ROOT/public" "$ROOT/app" "$ROOT/database" "$SITE/"
rm -f "$SITE/app/.env" "$SITE/app/.env.remote-ops"
KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
umask 077
printf 'DB_HOST=127.0.0.1;port=%s\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n' "$DBPORT" "$DB" "$DBUSER" "$DBPASS" > "$SITE/app/.env"
printf 'REMOTE_OPS_ENABLED=1\nREMOTE_OPS_KEY_ID=t1\nREMOTE_OPS_KEY=%s\nREMOTE_OPS_BASE_URL=http://127.0.0.1:%s\n' "$KEY" "$WEBPORT" > "$SITE/app/.env.remote-ops"
umask 022

# ── 4) CLI 비교 기준 임시 git 저장소(사이트 사본과 같은 내용으로 시작) ──
LOCAL="$WORK/local"
mkdir -p "$LOCAL"
cp -a "$ROOT/public" "$ROOT/app" "$LOCAL/"
rm -f "$LOCAL/app/.env" "$LOCAL/app/.env.remote-ops"
git -C "$LOCAL" init -q
git -C "$LOCAL" -c user.email=test@tomokmall.test -c user.name=rops-test add -A
git -C "$LOCAL" -c user.email=test@tomokmall.test -c user.name=rops-test commit -qm "base"

# ── 5) PHP 내장 서버 (ROPS_SERVER_IMAGE=이미지 이면 그 Docker 이미지의 PHP 로 — 예: 운영과 같은 8.4 확인) ──
if [ -n "${ROPS_SERVER_IMAGE:-}" ]; then
  say "서버 PHP: Docker ${ROPS_SERVER_IMAGE}"
  docker run --rm --name rops-test-web --network host -v "$WORK:$WORK" "$ROPS_SERVER_IMAGE" \
    php -S "127.0.0.1:${WEBPORT}" -t "$SITE/public" > "$WORK/php-server.log" 2>&1 &
  SERVER_PID=$!
  trap 'docker rm -f rops-test-web >/dev/null 2>&1 || true; cleanup' EXIT
else
  php -S "127.0.0.1:${WEBPORT}" -t "$SITE/public" > "$WORK/php-server.log" 2>&1 &
  SERVER_PID=$!
fi
for _ in $(seq 1 60); do curl -s -o /dev/null "http://127.0.0.1:${WEBPORT}/login.php" && break; sleep 0.5; done

# ── 6) 통합 테스트 실행 ──
say "통합 테스트 실행"
set +e
env -u TOMOK_OPS_KEY_FILE \
  ROPS_T_URL="http://127.0.0.1:${WEBPORT}/api/remote-ops.php" ROPS_T_BASE="http://127.0.0.1:${WEBPORT}" \
  ROPS_T_KEY="$KEY" ROPS_T_KID="t1" ROPS_T_SITE="$SITE" ROPS_T_LOCAL="$LOCAL" ROPS_T_WORK="$WORK" \
  ROPS_T_DB_DSN="mysql:host=127.0.0.1;port=${DBPORT};dbname=${DB};charset=utf8mb4" ROPS_T_DB_USER="$DBUSER" ROPS_T_DB_PASS="$DBPASS" \
  ROPS_T_ADMIN_EMAIL="test-admin@tomokmall.test" ROPS_T_ADMIN_PW="$ADMIN_PW" \
  php "$ROOT/scripts/remote-ops/integration.test.php"
RC=$?
set -e
if [ "${ROPS_KEEP:-0}" = "1" ]; then
  # 화면 확인용: [TEST] 관리자 비밀번호·키를 임시 폴더에만 남긴다(권한 600, 출력하지 않음)
  ( umask 077; printf '%s\n' "$ADMIN_PW" > "$WORK/admin.pw"; printf '%s\n' "$KEY" > "$WORK/ops.key" )
fi
if grep -qiE "fatal|parse error" "$WORK/php-server.log"; then
  echo "PHP 서버 로그에 치명 오류:"; grep -iE "fatal|parse error" "$WORK/php-server.log" | head -20; RC=1
fi
exit $RC
