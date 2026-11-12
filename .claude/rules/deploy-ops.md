# 배포·FTP·원격 관리 운영 (P0 상세·P1)

> 원칙은 `CLAUDE.md` P0-2(배포)·P0-3(DB)·P0-5(승인 절차). 서버 구성·단계별 배포·롤백·체크리스트는 **[`docs/DEPLOYMENT.md`](../../docs/DEPLOYMENT.md)**, 원격 관리는 **[`docs/REMOTE-OPS.md`](../../docs/REMOTE-OPS.md)**·[`docs/02-design/remote-ops.md`](../../docs/02-design/remote-ops.md). 작업 전 해당 문서를 읽는다.
> 작업 브랜치 커밋·푸시는 **배포 승인이 아니다.**

## 1. FTP 배포 (`scripts/deploy.sh`, `scripts/deploy-files.sh`)
- 승인은 그 배포 1회에만 유효하다. 배포는 **Git 에 커밋된 상태** 기준으로 하고 배포 커밋 해시를 기록한다.
- `deploy.sh` 동작: ① 배포 전 게이트(전체 PHP 문법 + 단위 테스트, 실패 시 중단) ② `public/`→`/html`, `app/`→`/app`, `database/`→`/database` **전체 덮어쓰기** ③ **업로드 전용** — 로컬에서 지운 파일은 서버에 남는다(서버 파일 삭제는 별도 승인 후 수동) ④ 배포 후 운영 `smoke.sh` 자동 실행.
- `deploy.sh` 는 `app/` 의 모든 파일을 올린다 → 로컬 `app/.env` 가 있으면 운영 `/app/.env` 를 덮어쓴다. 배포 전 `app/`·`public/` 에 시크릿 파일이 없는지 확인한다.
- 절차: ① 로컬 테스트 통과·커밋 ② 배포 대상 파일 목록·위험 보고 → **승인** ③ 서버 기존 파일 **백업**(변경 대상만이라도) ④ DB 변경이 있으면 DB 먼저(별도 승인) ⑤ 배포 → 스모크 → 주요 화면 수동 확인 ⑥ 문제 시 백업으로 즉시 복구, 결과 보고. 가능하면 트래픽이 적은 시간에.
- 일부 파일만 배포할 때는 `deploy-files.sh` 권장(`check` 는 내려받기만, `upload`·`rollback` 은 승인 후) — `docs/DEPLOYMENT.md` 5-1장.
- FTP 비밀번호는 셸 환경변수 또는 `.env.local` 의 `FTP_PASS` 로만 전달한다. 명령어 기록·문서·채팅에 평문으로 남기지 않는다.
- `scripts/smoke.sh` 기본 대상은 운영이다. 운영 확인은 사용자 요청·배포 승인 때만.

## 2. 원격 관리 API (사용자 결정 2026-11-04, 승인 방식 유지 2026-11-10)
- 운영 설치 2026-11-04(관리 테이블 4개 + 파일 11개 + `/app/.env.remote-ops`). CLI `scripts/tomok`, 키는 Claude Code 환경변수 `TOMOK_OPS_KEY` 로만(출력·기록 금지).
- **DB 변경**: `scripts/tomok db request`·`db stage-sql` → 사용자가 관리자 화면에서 승인 → 실행. **파일 변경**: `scripts/tomok files stage` → 승인 → `files deploy`. 두 승인은 별개(서버가 강제).
- **승인된 범위 안에서는 반복 승인 없이 자동 진행**한다(조회 → 수정 → 재조회 → 결과 보고). 범위(테이블·컬럼·대상 행·최대 행 수·유효시간·횟수, 또는 SQL·파일 내용)를 벗어나거나 유효시간·횟수가 끝나면 새로 요청한다.
- 조회(구조·SELECT·검색·파일 목록·해시·비교)는 승인 없이 한다. 대량 추출은 하지 않는다.
- 원격 배포 시 `app/pricing.php` 는 승인 화면에서 추가 확인 대상이다.
- **관리 API 자체 파일**(`app/remote_ops/**`, `public/api/remote-ops.php`, `public/admin-remote-ops.php`)은 API 로 바꿀 수 없다: 작업 브랜치 → 테스트(`php tests/remote_ops.test.php`, `bash scripts/remote-ops/test-local.sh`) → 커밋·푸시 → main 병합 승인 → **사용자가 FileZilla 로 업로드** → `scripts/tomok files diff <파일…>` 로 서버 = 커밋 확인(CRLF/LF 차이는 같음으로 봄).
- **긴급 중지**: 관리자 화면 '전체 회수'(모든 원격 쓰기 중지) 또는 `/app/.env.remote-ops` 의 `REMOTE_OPS_ENABLED=0`.

## 3. 운영 장애·데이터 손실 예방
- 운영 서버에서 직접 파일을 편집하지 않는다. 항상 로컬 수정 → 테스트 → 승인 → 배포.
- 배포 전 백업(파일 + DB), 배포 후 스모크·수동 확인, 문제 시 즉시 롤백할 수 있게 준비한다.
- 오류 시 사용자에게 내부 정보(스택트레이스·SQL·경로)를 노출하지 않는다.
- 외부 API(OpenAI 등) 장애에도 핵심 기능(상품 조회·견적·주문)은 동작하도록 실패를 격리한다.
- 정기 DB 백업 여부를 확인하고, 없으면 사용자에게 권고한다.
- 운영 반영을 하면 `docs/03-analysis/ai-sourcing/prod-apply/DEPLOY-LOG.md` 에 일시·커밋·변경·결과를 남긴다.

## 4. 저장소의 로컬용 값 (새로 추가 금지)
- `docker-compose.yml` 에 **로컬 개발용** DB 비밀번호가 평문으로 있다. 운영과 같은 값을 쓰지 않고, 새 시크릿을 넣지 않는다(`${VAR}` 참조).
- `scripts/deploy.sh` 의 FTP 호스트·계정명은 이미 커밋되어 있다. 비밀번호는 절대 추가하지 않는다.
- `scripts/local-up.sh`, `database/local-init.sql` 에 로컬용 비밀번호·기본 관리자 계정이 있다. 운영에서 같은 값을 쓰지 않고, 새로 추가하지 않는다(`docs/DEPLOYMENT.md` 8장).
- `.gitignore` 의 `.env*` 제외 규칙을 유지하고 `.env.example` 에는 키 이름만 둔다(값 비움). 키: `OPENAI_API_KEY`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `FTP_PASS`(로컬만).
