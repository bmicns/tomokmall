#!/usr/bin/env bash
# FileZilla 등으로 내려받은 운영 서버 파일 ↔ Git 버전 비교 (로컬 전용 · 서버·DB 접속 없음 · 읽기만)
#
# 용도: 운영 서버에 어느 커밋의 파일이 올라가 있는지 확인 → 배포 대상·롤백 기준을 정한다(docs/03-analysis/predeploy/predeploy-check-2026-10-29.md).
# 폴더 구조: FTP 루트 그대로 내려받은 폴더 — <폴더>/html/... (← public/), <폴더>/app/... (← app/)
# 사용:
#   scripts/compare-server-files.sh <내려받은폴더> <기준커밋> [저장소경로...]
#   예) scripts/compare-server-files.sh backup/20261030-filezilla 516950f app/rag.php public/chat.php
#   저장소경로를 생략하면 폴더 안의 모든 파일을 비교한다.
# 결과(파일마다):
#   같음            — 서버 파일 == 기준커밋 버전(줄바꿈 CRLF/LF 차이는 무시)
#   다름 → <커밋>   — 기준커밋과 다르지만 그 커밋 버전과 같음(운영이 그 커밋 기준)
#   다름(Git에 없음) — 어떤 커밋과도 같지 않음(서버에서 직접 고친 파일일 수 있음 → 덮어쓰기 전 확인)
#   서버에 없음     — 내려받은 폴더에 파일이 없음(신규 파일이면 정상)
# 종료 코드: 0 = 모두 '같음' 또는 '서버에 없음' / 1 = 확인할 파일 있음 / 2 = 사용법 오류
set -uo pipefail
cd "$(dirname "$0")/.."
DIR="${1:-}"; BASE="${2:-}"
[ -n "${DIR}" ] && [ -d "${DIR}" ] && [ -n "${BASE}" ] || { sed -n '2,16p' "$0"; exit 2; }
git rev-parse -q --verify "${BASE}^{commit}" >/dev/null || { echo "기준 커밋을 찾을 수 없음: ${BASE}"; exit 2; }
shift 2

to_server() { case "$1" in public/*) echo "html/${1#public/}" ;; app/*) echo "app/${1#app/}" ;; *) echo "" ;; esac; }
to_repo()   { case "$1" in html/*) echo "public/${1#html/}" ;; app/*) echo "app/${1#app/}" ;; *) echo "" ;; esac; }
same() { cmp -s <(tr -d '\r' < "$1") <(git show "$2:$3" 2>/dev/null | tr -d '\r'); }   # $1=서버파일 $2=커밋 $3=저장소경로

if [ $# -gt 0 ]; then files=("$@"); else
  mapfile -t files < <(cd "${DIR}" && find html app -type f 2>/dev/null | sort | while read -r f; do to_repo "$f"; done | sed '/^$/d')
fi
review=0
for f in "${files[@]}"; do
  s="$(to_server "$f")"; [ -n "$s" ] || { echo "건너뜀(public/·app/ 아님): $f"; continue; }
  if [ ! -f "${DIR}/${s}" ]; then echo "서버에 없음      $f"; continue; fi
  if same "${DIR}/${s}" "${BASE}" "$f"; then echo "같음             $f"; continue; fi
  hit=""
  for c in $(git log --format=%h --all -- "$f"); do
    if same "${DIR}/${s}" "$c" "$f"; then hit="$c"; break; fi
  done
  review=1
  if [ -n "$hit" ]; then echo "다름 → ${hit}  $f  ($(git log -1 --format='%ad %s' --date=short "$hit" | cut -c1-60))"
  else echo "다름(Git에 없음)  $f  — 서버에서 직접 고친 파일일 수 있음, 덮어쓰기 전 확인"; fi
done
exit "${review}"
