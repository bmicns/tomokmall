#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FTP_HOST="112.175.185.138"
FTP_USER="tomokmall"
FTP_PASS="${FTP_PASS:?FTP_PASS is required}"
REMOTE_ROOT="/html"
REMOTE_APP="/app"
REMOTE_DATABASE="/database"

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
