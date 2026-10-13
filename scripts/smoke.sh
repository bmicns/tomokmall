#!/usr/bin/env bash
# 배포 스모크 체크 — 핵심 URL 상태·마커 확인. 배포 직후 실행.
# 사용: scripts/smoke.sh [BASE_URL]   (기본: 운영)
set -u
BASE="${1:-http://tomokmall.dothome.co.kr}"
fail=0
chk() { # $1=경로 $2=기대코드 $3=선택 grep마커
  local code
  code=$(curl -s -o /tmp/_smoke.out -w '%{http_code}' -m 20 "${BASE}$1")
  if [ "$code" != "$2" ]; then echo "  ✗ $1 → HTTP $code (기대 $2)"; fail=1; return; fi
  if [ -n "${3:-}" ] && ! grep -q "$3" /tmp/_smoke.out; then echo "  ✗ $1 → 마커 '$3' 없음"; fail=1; return; fi
  echo "  ✓ $1 → $code"
}
echo "스모크 대상: ${BASE}"
chk "/"                                                200 "건축자재"
chk "/products.php?q=%EA%B7%B8%EB%A0%88%EC%9D%B4%ED%8C%85" 200 "품목보기"   # '그레이팅'
chk "/login.php"                                       200
chk "/chat.php"                                        200
chk "/admin.php"                                       302              # 미로그인 → 로그인 리다이렉트(인증 정상)
chk "/api/suggest.php?q=%EC%B8%A1%EA%B5%AC"            200 "측구"       # 자동완성 JSON
chk "/api/submit-estimate.php"                         404              # dead code 제거 확인
rm -f /tmp/_smoke.out
if [ "$fail" = 0 ]; then echo "✅ 스모크 통과"; else echo "❌ 스모크 실패 — 배포 확인 필요"; exit 1; fi
