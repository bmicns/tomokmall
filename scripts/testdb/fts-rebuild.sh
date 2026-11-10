#!/usr/bin/env bash
# 로컬 테스트 컨테이너(tomokmall_ai_test)의 FULLTEXT 색인 점검·재생성 — 테스트 DB 전용(운영 DB 아님).
#
# 왜: 컨테이너가 비정상 종료되면(세션 시간 제한으로 Docker 데몬이 꺼지는 등) InnoDB FULLTEXT 색인이 표와 어긋나,
#     그 뒤에 넣은 행이 FULLTEXT 검색에 안 나오거나 일부만 나온다. search_products() 는 FULLTEXT 가 0건일 때만 LIKE 로
#     넘어가므로 '일부만 나온 경우'엔 상품이 빠진다 — tests/member_price_display.test.php 일시 실패 원인(2026-11-12 재현).
# 무엇: products·rag_documents 를 ALTER TABLE … ENGINE=InnoDB 로 다시 만들어 FULLTEXT 색인을 새로 만든다(데이터·구조 그대로).
# 금지: 운영 DB·다른 컨테이너. 대상 DB 는 *_test 만. 비밀번호는 컨테이너 설정에서 읽고 출력하지 않는다.
#
# 사용: bash scripts/testdb/fts-rebuild.sh          (점검 후 어긋나면 재생성)
#       bash scripts/testdb/fts-rebuild.sh --force  (점검 없이 재생성)
set -euo pipefail

NAME=tomokmall_ai_test
PASS="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$NAME" 2>/dev/null | sed -n 's/^MYSQL_ROOT_PASSWORD=//p')"
[ -n "$PASS" ] || { echo "테스트 컨테이너($NAME)가 없습니다." >&2; exit 1; }
run() { docker exec -i -e MYSQL_PWD="$PASS" "$NAME" mysql -uroot -N --default-character-set=utf8mb4 "$1"; }

DBS="$(echo "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE '%\\_test';" | run mysql)"
for db in $DBS; do
  case "$db" in *_test) ;; *) continue ;; esac
  need=0
  if [ "${1:-}" = "--force" ]; then
    need=1
  else
    # 점검: 상품명에 흔한 말로 FULLTEXT 건수와 LIKE 건수를 비교(색인이 어긋나면 FULLTEXT 가 적다)
    for kw in 그레이팅 수로관 경계석 흄관; do
      cnt="$(echo "SELECT (SELECT COUNT(*) FROM products WHERE MATCH(name, spec, description) AGAINST('+${kw}' IN BOOLEAN MODE)) - (SELECT COUNT(*) FROM products WHERE name LIKE '%${kw}%' OR spec LIKE '%${kw}%' OR description LIKE '%${kw}%');" | run "$db" 2>/dev/null || echo err)"
      if [ "$cnt" != "0" ]; then need=1; echo "$db: '$kw' FULLTEXT·LIKE 건수 차이 $cnt"; fi
    done
  fi
  if [ "$need" = "1" ]; then
    for t in products rag_documents; do
      if [ "$(echo "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db}' AND table_name='${t}';" | run mysql)" = "1" ]; then
        echo "ALTER TABLE \`${t}\` ENGINE=InnoDB;" | run "$db"
        echo "$db.$t: FULLTEXT 색인 다시 만듦"
      fi
    done
  else
    echo "$db: FULLTEXT 색인 정상"
  fi
done
