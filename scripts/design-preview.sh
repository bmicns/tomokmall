#!/bin/bash
# 디자인 검토용 미리보기 서버 (PHP 내장 서버, DB 불필요)
# 사내 다른 PC에서도 접속할 수 있도록 기본으로 모든 네트워크 인터페이스(0.0.0.0)에 바인딩한다.
#   bash scripts/design-preview.sh                 # 0.0.0.0:8090
#   PORT=9000 bash scripts/design-preview.sh       # 포트 변경
#   HOST=localhost bash scripts/design-preview.sh  # 이 PC에서만 접속

cd "$(dirname "$0")/.."

HOST="${HOST:-0.0.0.0}"
PORT="${PORT:-8090}"

# 사내 IP 확인 (macOS: en0/en1, Linux: hostname -I)
LAN_IP=""
if command -v ipconfig >/dev/null 2>&1; then
    LAN_IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null)"
fi
if [ -z "$LAN_IP" ] && command -v hostname >/dev/null 2>&1; then
    LAN_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
fi

echo ""
echo "─────────────────────────────────────────"
echo "  🎨 디자인 미리보기 (실제 서비스와 무관)"
echo "  이 PC:      http://localhost:${PORT}/design-preview/"
if [ "$HOST" = "0.0.0.0" ]; then
    echo "  사내 다른 PC: http://${LAN_IP:-<이 PC의 사내 IP>}:${PORT}/design-preview/"
else
    echo "  사내 접속:   꺼짐 (HOST=${HOST})"
fi
echo "─────────────────────────────────────────"
echo "  종료: Ctrl+C"
echo ""

exec php -S "${HOST}:${PORT}" design-preview/router.php
