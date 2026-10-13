#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FTP_HOST="112.175.185.138"
FTP_USER="tomokmall"

# FTP_PASS: 셸 env → 없으면 .env.local에서 자동 로드(gitignore, 커밋 안 됨).
#   한 번 .env.local에 넣어두면 이후 배포 때 다시 입력할 필요 없음.
if [ -z "${FTP_PASS:-}" ] && [ -f "${ROOT_DIR}/.env.local" ]; then
  FTP_PASS="$(grep -E '^FTP_PASS=' "${ROOT_DIR}/.env.local" | head -1 | cut -d= -f2- | sed -e 's/^["'\'']//' -e 's/["'\'']$//')"
fi
FTP_PASS="${FTP_PASS:?FTP_PASS is required — .env.local에 FTP_PASS=... 추가 또는 export}"
REMOTE_ROOT="/html"
REMOTE_APP="/app"
REMOTE_DATABASE="/database"

# ── 배포 전 게이트: 문법 검사 + 단위 테스트 ──────────────────────
if command -v php >/dev/null 2>&1; then
  echo "== PHP 문법 검사 =="
  while IFS= read -r f; do php -l "$f" >/dev/null || { echo "문법 오류: $f — 배포 중단"; exit 1; }; done \
    < <(find "${ROOT_DIR}/public" "${ROOT_DIR}/app" -name "*.php")
  echo "== 단위 테스트 =="
  php "${ROOT_DIR}/tests/functions.test.php" || { echo "테스트 실패 — 배포 중단"; exit 1; }
else
  echo "⚠️ php 미설치 — 문법·테스트 게이트 건너뜀"
fi

upload_file() {
  local source="$1"
  local target="$2"
  curl --silent --show-error --ftp-pasv --ftp-create-dirs --user "${FTP_USER}:${FTP_PASS}" \
    --upload-file "${source}" "ftp://${FTP_HOST}${target}"
}

while IFS= read -r file; do
  rel="${file#${ROOT_DIR}/public/}"
  upload_file "${file}" "${REMOTE_ROOT}/${rel}"
done < <(find "${ROOT_DIR}/public" -type f)

while IFS= read -r file; do
  rel="${file#${ROOT_DIR}/app/}"
  upload_file "${file}" "${REMOTE_APP}/${rel}"
done < <(find "${ROOT_DIR}/app" -type f)

while IFS= read -r file; do
  rel="${file#${ROOT_DIR}/database/}"
  upload_file "${file}" "${REMOTE_DATABASE}/${rel}"
done < <(find "${ROOT_DIR}/database" -type f)

echo "Deploy complete"

# ── 배포 후 스모크 체크 ──────────────────────────────────────────
echo "== 스모크 체크 =="
bash "${ROOT_DIR}/scripts/smoke.sh" || echo "⚠️ 스모크 실패 — 운영 확인 필요"
