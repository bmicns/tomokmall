#!/usr/bin/env bash
# 선택 파일 FTP 배포 (백업 → Git 이전 버전과 비교 → 업로드 → 업로드 검증 → 복구)
#
# ⚠️ 운영 서버 대상. upload / rollback 은 사용자 승인 후에만 실행한다(CLAUDE.md 8장, docs/DEPLOYMENT.md 5장).
#    check 는 서버에서 파일을 내려받기만 한다(서버 변경 없음).
#
# 사용:
#   scripts/deploy-files.sh check  <기준커밋> <파일...>   # 백업 + 서버 파일 == 기준커밋 버전 확인
#   scripts/deploy-files.sh upload <백업폴더>             # check 통과한 백업 기준으로 업로드 + 검증
#   scripts/deploy-files.sh rollback <백업폴더>           # 백업 파일로 서버 복구 + 검증
#   예) scripts/deploy-files.sh check 41b36ed app/bootstrap.php public/products.php
#
# 경로 규칙: public/X → /html/X, app/X → /app/X (그 외 경로는 거부)
# 환경변수: FTP_PASS(없으면 .env.local → 그래도 없으면 입력 프롬프트), FTP_TLS=1(FTPS 강제),
#           FTP_HOST·FTP_PORT·FTP_USER(기본: 운영 값, 로컬 테스트용 override)
# 실패 원칙: 어느 단계든 오류·불일치가 있으면 즉시 중단(set -e + 각 curl 종료코드 검사).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"
FTP_HOST="${FTP_HOST:-112.175.185.138}"
FTP_PORT="${FTP_PORT:-21}"
FTP_USER="${FTP_USER:-tomokmall}"
BACKUP_ROOT="${ROOT_DIR}/backup"

die() { echo "❌ 중단: $*" >&2; exit 1; }
ok()  { echo "  ✓ $*"; }

# ── 인증정보: curl 설정 파일(권한 600, 종료 시 삭제)로 전달 → 명령줄·ps에 비밀번호 노출 안 됨 ──
load_credentials() {
  if [ -z "${FTP_PASS:-}" ] && [ -f "${ROOT_DIR}/.env.local" ]; then
    FTP_PASS="$(grep -E '^FTP_PASS=' "${ROOT_DIR}/.env.local" | head -1 | cut -d= -f2- | sed -e 's/^["'\'']//' -e 's/["'\'']$//')" || true
  fi
  if [ -z "${FTP_PASS:-}" ]; then
    read -rsp "FTP 비밀번호 입력(화면에 표시되지 않음): " FTP_PASS; echo
  fi
  [ -n "${FTP_PASS:-}" ] || die "FTP 비밀번호가 없습니다."
  CURL_CFG="$(umask 077; mktemp)"
  trap 'rm -f "${CURL_CFG:-}"' EXIT
  local esc
  esc="$(printf '%s' "${FTP_USER}:${FTP_PASS}" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
  printf 'user = "%s"\n' "${esc}" > "${CURL_CFG}"
  unset FTP_PASS
}

# 공통 curl: -sS(진행 표시 숨김·오류는 표시), 시간 제한, 패시브 모드, FTPS 선택
ftp_curl() {
  local tls=()
  if [ "${FTP_TLS:-0}" = "1" ]; then tls=(--ssl-reqd); fi
  curl -sS --config "${CURL_CFG}" --ftp-pasv --connect-timeout 15 --max-time 120 ${tls[@]+"${tls[@]}"} "$@"
}

ftp_url() { echo "ftp://${FTP_HOST}:${FTP_PORT}$1"; }

# 서버 파일 내려받기: 실패하거나 빈 파일이면 중단
ftp_get() { # $1=원격경로 $2=로컬저장경로
  local tmp="$2.part"
  rm -f "${tmp}"
  ftp_curl -o "${tmp}" "$(ftp_url "$1")" || die "다운로드 실패: $1 (위 curl 오류 확인)"
  [ -s "${tmp}" ] || die "다운로드 결과가 비어 있음: $1"
  mv "${tmp}" "$2"
}

ftp_put() { # $1=로컬파일 $2=원격경로
  ftp_curl -T "$1" "$(ftp_url "$2")" || die "업로드 실패: $2 (위 curl 오류 확인)"
}

remote_path() { # 로컬 상대경로 → 서버 경로
  case "$1" in
    public/*) echo "/html/${1#public/}" ;;
    app/*)    echo "/app/${1#app/}" ;;
    *)        die "허용되지 않은 경로: $1 (public/ 또는 app/ 아래 파일만)" ;;
  esac
}

# 백업폴더의 manifest(로컬경로 원격경로 줄 단위) 읽기 → FILES / REMOTES
read_manifest() { # $1=백업폴더
  [ -f "$1/manifest.txt" ] || die "manifest.txt 없음: $1 (check 단계를 먼저 실행)"
  [ -f "$1/CHECK_PASSED" ] || die "check 통과 기록 없음: $1 (서버 파일이 기준 버전과 달랐거나 check 미완료)"
  FILES=(); REMOTES=()
  while read -r l r; do
    [ -n "${l}" ] || continue
    FILES+=("${l}"); REMOTES+=("${r}")
  done < "$1/manifest.txt"
  [ "${#FILES[@]}" -gt 0 ] || die "manifest가 비어 있음"
}

# 서버 파일을 다시 내려받아 기대 파일과 바이트 단위 비교
verify_remote() { # $1=원격경로 $2=기대 로컬파일 $3=임시폴더 [$4=불일치 시 메시지]
  local got="$3/verify_$(basename "$2")"
  ftp_get "$1" "${got}"
  cmp -s "${got}" "$2" || die "${4:-검증 실패: 서버 $1 내용이 기대한 파일과 다릅니다.}"
  rm -f "${got}"
}

confirm() { # $1=입력해야 할 단어
  local ans
  read -rp "계속하려면 '$1' 을(를) 입력하세요: " ans
  [ "${ans}" = "$1" ] || die "사용자가 취소했습니다."
}

cmd_check() {
  [ $# -ge 2 ] || die "사용법: check <기준커밋> <파일...>"
  local base="$1"; shift
  git rev-parse --verify --quiet "${base}^{commit}" >/dev/null || die "기준커밋을 찾을 수 없음: ${base}"

  echo "== 1) 로컬 사전 점검 =="
  local f
  for f in "$@"; do
    [ -f "${f}" ] || die "로컬 파일 없음: ${f}"
    remote_path "${f}" >/dev/null
    git diff --quiet HEAD -- "${f}" || die "커밋되지 않은 변경이 있음: ${f}"
    git cat-file -e "${base}:${f}" 2>/dev/null || die "기준커밋에 파일이 없음: ${base}:${f}"
  done
  ok "파일 존재·커밋 상태 정상 (배포 커밋 $(git rev-parse --short HEAD))"
  if command -v php >/dev/null 2>&1; then
    for f in "$@"; do php -l "${f}" >/dev/null || die "PHP 문법 오류: ${f}"; done
    php tests/functions.test.php >/dev/null || die "단위 테스트 실패 (php tests/functions.test.php 로 확인)"
    ok "PHP 문법 검사·단위 테스트 통과"
  else
    die "php가 없어 문법 검사·단위 테스트를 할 수 없습니다(검사 없이 배포하지 않음)."
  fi

  load_credentials
  local dir="${BACKUP_ROOT}/$(date +%Y%m%d-%H%M%S)"
  mkdir -p "${BACKUP_ROOT}"
  mkdir "${dir}" 2>/dev/null || die "백업 폴더가 이미 있음: ${dir} (기존 백업 보호 — 잠시 후 다시 실행)"
  mkdir -p "${dir}/server" "${dir}/base"
  : > "${dir}/manifest.txt"

  echo "== 2) 서버 파일 백업 (${dir}/server) =="
  for f in "$@"; do
    local r; r="$(remote_path "${f}")"
    mkdir -p "${dir}/server/$(dirname "${f}")" "${dir}/base/$(dirname "${f}")"
    ftp_get "${r}" "${dir}/server/${f}"
    git show "${base}:${f}" > "${dir}/base/${f}"
    echo "${f} ${r}" >> "${dir}/manifest.txt"
    ok "백업 완료: ${r} ($(wc -c < "${dir}/server/${f}" | tr -d ' ') bytes)"
  done

  echo "== 3) 서버 파일 == Git 기준커밋(${base}) 비교 =="
  local diff_found=0
  for f in "$@"; do
    if cmp -s "${dir}/server/${f}" "${dir}/base/${f}"; then
      ok "동일: ${f}"
    else
      echo "  ✗ 다름: ${f}  — 서버에 Git에 없는 변경이 있을 수 있음"
      diff_found=1
    fi
  done
  {
    echo "base=${base}"
    echo "deploy_commit=$(git rev-parse HEAD)"
    echo "checked_at=$(date '+%Y-%m-%d %H:%M:%S')"
  } > "${dir}/info.txt"
  [ "${diff_found}" = 0 ] || die "서버 파일이 기준 버전과 다릅니다. 업로드하지 마세요. 백업은 ${dir}/server 에 있습니다."
  touch "${dir}/CHECK_PASSED"
  echo "✅ check 통과. 다음 단계(승인 후): scripts/deploy-files.sh upload ${dir}"
}

cmd_upload() {
  [ $# -eq 1 ] || die "사용법: upload <백업폴더>"
  local dir="${1%/}"
  read_manifest "${dir}"
  local deploy_commit; deploy_commit="$(sed -n 's/^deploy_commit=//p' "${dir}/info.txt")"
  [ "${deploy_commit}" = "$(git rev-parse HEAD)" ] || die "check 이후 로컬 커밋이 바뀌었습니다. check를 다시 실행하세요."
  local i
  for i in "${!FILES[@]}"; do
    git diff --quiet HEAD -- "${FILES[$i]}" || die "커밋되지 않은 변경이 있음: ${FILES[$i]}"
  done

  load_credentials
  local tmp; tmp="$(mktemp -d)"
  echo "== 1) 업로드 직전 재확인: 서버 파일이 백업 시점과 같은지 =="
  for i in "${!FILES[@]}"; do
    verify_remote "${REMOTES[$i]}" "${dir}/server/${FILES[$i]}" "${tmp}" \
      "백업(check) 이후 서버 ${REMOTES[$i]} 파일이 바뀌었습니다. 누가 수정했는지 확인 후 check부터 다시 하세요."
    ok "변동 없음: ${REMOTES[$i]}"
  done

  echo "업로드 대상 (커밋 $(git rev-parse --short HEAD)):"
  for i in "${!FILES[@]}"; do echo "  ${FILES[$i]} → ${REMOTES[$i]}"; done
  confirm "배포"

  echo "== 2) 업로드 + 업로드 검증 (나열 순서대로) =="
  for i in "${!FILES[@]}"; do
    if ! ( ftp_put "${FILES[$i]}" "${REMOTES[$i]}" && verify_remote "${REMOTES[$i]}" "${FILES[$i]}" "${tmp}" ); then
      echo "⚠️  ${REMOTES[$i]} 업로드/검증 실패. 즉시 복구: scripts/deploy-files.sh rollback ${dir}" >&2
      exit 1
    fi
    ok "업로드·검증 완료: ${REMOTES[$i]}"
  done
  rm -rf "${tmp}"
  echo "deployed_at=$(date '+%Y-%m-%d %H:%M:%S')" >> "${dir}/info.txt"
  echo "✅ 업로드 완료. 이제 브라우저 확인 + bash scripts/smoke.sh 를 실행하세요."
  echo "   문제 시 복구: scripts/deploy-files.sh rollback ${dir}"
}

cmd_rollback() {
  [ $# -eq 1 ] || die "사용법: rollback <백업폴더>"
  local dir="${1%/}"
  read_manifest "${dir}"
  local i
  for i in "${!FILES[@]}"; do
    [ -s "${dir}/server/${FILES[$i]}" ] || die "백업 파일 없음: ${dir}/server/${FILES[$i]}"
  done
  load_credentials
  echo "복구 대상 (백업 → 서버):"
  for i in "${!FILES[@]}"; do echo "  ${dir}/server/${FILES[$i]} → ${REMOTES[$i]}"; done
  confirm "복구"
  local tmp; tmp="$(mktemp -d)" fail=0
  # 하나가 실패해도 나머지는 계속 복구 시도(복구는 최대한 진행), 마지막에 결과 판정
  for i in "${!FILES[@]}"; do
    if ( ftp_put "${dir}/server/${FILES[$i]}" "${REMOTES[$i]}" && verify_remote "${REMOTES[$i]}" "${dir}/server/${FILES[$i]}" "${tmp}" ); then
      ok "복구·검증 완료: ${REMOTES[$i]}"
    else
      echo "  ✗ 복구 실패: ${REMOTES[$i]}" >&2; fail=1
    fi
  done
  rm -rf "${tmp}"
  [ "${fail}" = 0 ] || die "일부 파일 복구 실패. FileZilla로 ${dir}/server 의 파일을 직접 올리세요."
  echo "rolled_back_at=$(date '+%Y-%m-%d %H:%M:%S')" >> "${dir}/info.txt"
  echo "✅ 복구 완료. 브라우저로 사이트가 이전처럼 동작하는지 확인하세요."
}

command -v curl >/dev/null 2>&1 || die "curl이 필요합니다."
case "${1:-}" in
  check)    shift; cmd_check "$@" ;;
  upload)   shift; cmd_upload "$@" ;;
  rollback) shift; cmd_rollback "$@" ;;
  *) sed -n '2,15p' "$0"; exit 1 ;;
esac
