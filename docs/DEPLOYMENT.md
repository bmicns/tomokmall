# 운영 서버 환경 및 배포 절차 (DEPLOYMENT.md)

> 이 문서는 건축자재몰(tomokmall) 운영 서버 환경과 FTP 배포 절차의 기준 문서입니다.
> **비밀번호·API 키 등 민감한 값은 이 문서에 절대 적지 않습니다.** 값은 환경변수 파일(`.env.local`, `/app/.env`)에서만 관리합니다.
>
> ⚠️ **운영 서버 배포(FTP 업로드·서버 파일 삭제)와 운영 DB 변경(데이터·구조 변경)은 반드시 사용자(사이트 운영자)의 명시적 승인 후에만 진행합니다.**
> 운영 DB 접속과 읽기 전용 조회(SELECT·구조 조회)는 승인 없이 가능합니다. 기준은 6장 및 `CLAUDE.md` 6장 "DB 접근 권한 기준"을 따릅니다.
> 승인은 해당 작업 1회에만 유효하며, 이전 승인이 다음 작업으로 이어지지 않습니다.
> 작업 브랜치에 대한 자동 커밋·푸시(`CLAUDE.md` 7장)는 **배포 승인이 아닙니다.** 커밋·푸시가 끝났어도 운영 배포·FTP 업로드·운영 DB 변경은 이 문서의 절차와 별도 승인을 따릅니다.

---

## 1. 운영 환경 요약

| 항목 | 값 | 출처 |
|---|---|---|
| 호스팅 | 닷홈(dothome) 웹호스팅 — Apache / PHP 8.4 / MySQL 8.0 | README |
| 라이브 URL | `http://tomokmall.dothome.co.kr` (HTTP, SSL 미적용) | 운영 정보, `app/config.php`, `scripts/smoke.sh` |
| FTP 호스트 | `112.175.185.138` | 운영 정보, `scripts/deploy.sh` |
| FTP 사용자 | `tomokmall` | 운영 정보, `scripts/deploy.sh` |
| FTP 비밀번호 | **문서에 기록하지 않음** → 환경변수 `FTP_PASS` | `.env.local` (Git 제외) |
| FTP 방식 | 평문 FTP, 패시브 모드(`curl --ftp-pasv`) | `scripts/deploy.sh` |
| 웹루트 | `/html` ← 로컬 `public/` | `scripts/deploy.sh` |
| 앱 로직(웹루트 밖) | `/app` ← 로컬 `app/` | `scripts/deploy.sh` |
| SQL 파일 보관 | `/database` ← 로컬 `database/` (웹루트 밖) | `scripts/deploy.sh` |
| 운영 시크릿 파일 | `/app/.env` (서버에만 존재, Git·배포 스크립트 대상 아님) | README, `app/bootstrap.php` |
| 운영 DB 접속 정보 | 서버 `/app/.env`의 `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` (값은 문서화하지 않음) | `app/config.php` |

### 서버 디렉터리 구조

```
FTP 루트
├── html/        ← public/   (웹에서 접근 가능)
│   ├── index.php, *.php, api/, assets/, staff/
│   └── .htaccess  (디렉터리 목록 차단, .env/.git 접근 차단)
├── app/         ← app/      (웹에서 접근 불가)
│   ├── bootstrap.php, config.php, ... 
│   └── .env     ← 운영 시크릿 (서버에서만 관리)
└── database/    ← database/ (웹에서 접근 불가, SQL 보관용)
```

---

## 2. 운영 정보(이미지)와 기존 프로젝트 설정 비교

운영 정보 이미지의 출처는 로컬 `.env.local` + `scripts/deploy.sh`입니다.

| 항목 | 운영 정보(이미지) | 프로젝트 설정 | 일치 여부 / 비고 |
|---|---|---|---|
| FTP 호스트 | 112.175.185.138 | `deploy.sh`: `FTP_HOST="112.175.185.138"` | ✅ 일치 |
| FTP 사용자 | tomokmall | `deploy.sh`: `FTP_USER="tomokmall"` | ✅ 일치 |
| FTP 비밀번호 | (가려짐) | `deploy.sh`: 셸 env `FTP_PASS` → 없으면 `.env.local`의 `FTP_PASS=` | ✅ 코드에 하드코딩 없음 |
| 라이브 URL | tomokmall.dothome.co.kr | `config.php` `base_url`, `smoke.sh` 기본 대상 | ✅ 일치 (HTTP) |
| DB_HOST | 127.0.0.1 | `.env.example`: `localhost` / `config.php` 기본값: `localhost` / Docker: `mysql` | ⚠️ 차이 있음 — 아래 참고 |
| DB_NAME | tomokmall | `.env.example`·`config.php` 기본값: `tomokmall` | ✅ 일치 |
| DB_USER | tomokmall | `.env.example`·`config.php` 기본값: `tomokmall` | ✅ 일치 |
| DB_PASS | (가려짐) | `.env.example` 빈 값, `config.php`는 `getenv('DB_PASS')` | ✅ 코드에 하드코딩 없음 |

### DB_HOST 차이에 대한 해석
- 이미지의 DB 정보는 **로컬 `.env.local`** 값입니다. `scripts/local-up.sh`가 Homebrew MySQL(`127.0.0.1:3306`)과 PHP 내장 서버를 쓰므로 `127.0.0.1`은 **로컬 개발용 값**으로 보입니다.
- **운영 DB 접속 정보는 서버의 `/app/.env`에 따로 있으며**, 이미지만으로는 운영 값을 확정할 수 없습니다. (닷홈은 보통 `localhost`)
- `localhost`는 MySQL 유닉스 소켓, `127.0.0.1`은 TCP로 연결됩니다. 운영 `/app/.env`의 `DB_HOST`를 바꿀 일이 있으면 승인 후 진행하고, 바꾼 뒤 DB 연결을 바로 확인합니다.

### 환경별 설정 정리

| 환경 | 실행 방법 | DB | 시크릿 파일 |
|---|---|---|---|
| 로컬(Homebrew) | `bash scripts/local-up.sh` → `http://localhost:8080` | Homebrew MySQL `127.0.0.1:3306` | 루트 `.env.local` |
| 로컬(Docker) | `docker compose up` → `:8080`(웹), `:8081`(phpMyAdmin) | 컨테이너 `mysql` (호스트 포트 3307) | `docker-compose.yml` 환경변수 |
| 운영(닷홈) | FTP 업로드 | 닷홈 MySQL | 서버 `/app/.env` |

### 환경변수 로드 순서 (`app/bootstrap.php`)
1. 이미 설정된 환경변수(셸·Docker)가 있으면 그 값을 그대로 씁니다(덮어쓰지 않음).
2. `app/.env` → 운영에서는 `/app/.env`를 읽습니다.
3. `app/../.env.local` → 로컬에서는 프로젝트 루트 `.env.local`을 읽습니다.

---

## 3. 환경변수(시크릿) 관리

| 키 | 용도 | 로컬 | 운영 |
|---|---|---|---|
| `OPENAI_API_KEY` | AI 상담 | `.env.local` | `/app/.env` |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL 접속 | `.env.local` | `/app/.env` |
| `FTP_PASS` | `scripts/deploy.sh` FTP 업로드 | `.env.local` 또는 셸 `export` | (서버에 두지 않음) |

규칙:
- 실제 값은 **`.env.local`(로컬)**과 **`/app/.env`(운영)**에만 둡니다. 둘 다 `.gitignore`로 Git에서 제외되어 있습니다.
- `.env.example`에는 **키 이름만** 두고 값은 비웁니다.
- 비밀번호를 문서, 커밋 메시지, 이슈·PR, 채팅, 스크린샷, 셸 히스토리에 남기지 않습니다.
  - 셸에서 일회성으로 넘길 때 `FTP_PASS='...' bash scripts/deploy.sh`처럼 쓰면 셸 히스토리에 남습니다. **`.env.local`에 넣는 방식을 권장**합니다.
- 비밀번호가 외부에 노출됐다고 판단되면 즉시 닷홈 관리 화면에서 **변경(rotate)**하고 `.env.local`·`/app/.env`를 갱신합니다.

`.env.local` 형식 예시 (값은 직접 입력, 절대 커밋하지 않음):

```dotenv
OPENAI_API_KEY=
DB_HOST=127.0.0.1
DB_NAME=tomokmall
DB_USER=tomokmall
DB_PASS=
FTP_PASS=
```

---

## 4. `scripts/deploy.sh` 동작 분석

```
FTP_PASS 확인 ─▶ 배포 전 게이트 ─▶ 전체 업로드 ─▶ 스모크 체크
```

1. **FTP_PASS 로드**: 셸 환경변수 `FTP_PASS`가 있으면 사용하고, 없으면 `.env.local`의 `FTP_PASS=` 줄을 읽습니다. 둘 다 없으면 즉시 중단합니다.
2. **배포 전 게이트** (php가 설치돼 있을 때만):
   - `public/`, `app/`의 모든 `*.php`에 `php -l` 문법 검사 → 하나라도 실패하면 중단
   - `php tests/functions.test.php` 단위 테스트 → 실패하면 중단
   - ⚠️ php가 없으면 경고만 하고 **게이트 없이 업로드를 계속**합니다.
3. **업로드** (`curl --ftp-pasv --ftp-create-dirs --upload-file`, 파일 1개당 1회 접속):
   - `public/**` → `/html/**`
   - `app/**` → `/app/**`
   - `database/**` → `/database/**`
   - 변경 여부와 상관없이 **로컬의 모든 파일을 덮어씁니다.**
4. **스모크 체크**: `scripts/smoke.sh`를 운영 URL 대상으로 실행합니다. 실패해도 **배포는 되돌리지 않고 경고만** 출력합니다.

### 주의해야 할 동작 (현재 스크립트의 한계)

| # | 동작 | 위험 | 대응 |
|---|---|---|---|
| 1 | **업로드 전용**: 서버 파일을 삭제하지 않음 | 로컬에서 지운 파일(예: 제거한 API)이 서버에 남아 계속 접근 가능 | 삭제가 필요한 파일 목록을 따로 정리해 승인 후 FTP로 수동 삭제 |
| 2 | **전체 덮어쓰기**, 원자적이지 않음 | 업로드 도중 실패하면 신·구 파일이 섞인 상태가 됨. 롤백 기능 없음 | 배포 전 서버 파일 백업, 실패 시 백업으로 복구 |
| 3 | `app/` 안의 모든 파일 업로드 (`find -type f`) | 로컬에 `app/.env`가 있으면 **운영 `/app/.env`를 덮어써서** DB·API 키가 로컬 값으로 바뀜 | 배포 전 `ls -a app/`로 `.env` 등이 없는지 반드시 확인 |
| 4 | Git에 없는 로컬 파일도 업로드 | 커밋 안 된 수정, 임시 파일, `public/uploads/` 로컬 파일이 운영에 올라감 | 배포 전 `git status`가 깨끗한지 확인 |
| 5 | `database/` 전체 업로드 | `local-init.sql`(로컬용 초기 계정 포함) 등이 서버 `/database`에 올라감. 웹루트 밖이라 웹 접근은 불가 | 운영 SQL은 자동 실행되지 않음. 운영 DB 반영은 별도 승인 절차(6장) |
| 6 | 평문 FTP + `curl --user 사용자:비밀번호` | 비밀번호가 네트워크에서 암호화되지 않음. 실행 중 프로세스 목록(`ps`)에 노출 가능 | 공용 Wi-Fi에서 배포 금지. 개선안은 9장 참고 |
| 7 | php 미설치 시 게이트 생략 | 문법 오류 코드가 그대로 운영에 반영될 수 있음 | php가 설치된 환경에서만 배포 |
| 8 | 스모크 실패해도 자동 롤백 없음 | 장애 상태로 남을 수 있음 | 스모크 결과를 확인하고 실패 시 즉시 수동 롤백 |

---

## 5. 배포 절차 (승인 필수)

> Claude(AI)는 아래 **2단계의 사용자 승인 없이 `scripts/deploy.sh`를 실행하거나 FTP에 접속하지 않습니다.**

### 1단계: 배포 준비 (로컬)
```bash
git status                                  # 커밋 안 된 변경·임시 파일 없는지
ls -a app/ public/                          # app/.env 등 시크릿 파일이 섞여 있지 않은지
find public app -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors"
php tests/functions.test.php                # 단위 테스트
bash scripts/smoke.sh http://localhost:8080 # 로컬 서버 실행 중일 때
git log -1 --oneline                        # 배포할 커밋 해시 기록
```

### 2단계: 승인 요청 (사용자에게 보고)
다음 내용을 정리해 보고하고 **명시적 승인**을 받습니다.
- 배포할 커밋 해시와 변경 요약
- 변경된 파일 목록: `git diff --name-only <직전 배포 커밋>..HEAD`
- 서버에서 삭제가 필요한 파일 (있으면)
- DB 변경 필요 여부 (있으면 6장 절차로 **별도 승인**)
- 예상 위험과 롤백 방법

### 3단계: 백업
- **파일**: 변경 대상 파일을 FTP 클라이언트(예: FileZilla)로 서버에서 내려받아 날짜 폴더에 보관합니다(예: `backup/20261013/`, Git 제외 위치).
- **DB**: DB 변경이 있으면 닷홈 phpMyAdmin에서 **내보내기(전체 또는 대상 테이블)**를 합니다.

### 4단계: DB 반영 (있을 때만, 별도 승인 후)
코드가 새 컬럼·테이블을 쓰면 **DB 먼저 반영 → 코드 배포** 순서로 진행합니다.

### 5단계: 배포 실행 (승인 후)
```bash
bash scripts/deploy.sh          # FTP_PASS는 .env.local에서 자동 로드
```

### 6단계: 확인
- 스모크 결과 확인 (`✅ 스모크 통과`)
- 브라우저로 주요 화면 직접 확인: 메인, 상품 검색, 상품 상세, 장바구니, 견적, 로그인, 마이페이지, AI 상담, 관리자(`/admin.php`), 직원(`/staff/`)
- 변경한 기능을 권한별로 확인

### 7단계: 문제 발생 시 롤백
1. 3단계에서 백업한 파일을 FTP로 다시 업로드합니다.
2. 또는 직전 배포 커밋으로 체크아웃한 뒤 해당 파일만 재업로드합니다(**재배포도 승인 필요**).
3. DB를 바꿨다면 준비한 롤백 SQL 또는 백업으로 복구합니다(**승인 필요**).
4. 원인과 조치 내용을 보고합니다.

### 8단계: 기록
배포 일시, 커밋 해시, 변경 요약, 결과, 이슈를 남깁니다. (예: Git 태그 `deploy-YYYYMMDD` — 태그 생성·푸시도 승인 후)

---

## 5-1. 일부 파일만 배포 (`scripts/deploy-files.sh`, 권장)

변경된 파일 몇 개만 올릴 때 사용합니다. `deploy.sh`(전체 덮어쓰기)보다 안전하며, **모든 단계에서 실패하면 즉시 멈춥니다.**
`check`는 서버에서 파일을 **내려받기만** 하고, `upload`·`rollback`만 서버를 바꿉니다(**승인 후에만 실행**).

| 단계 | 명령 | 하는 일 | 서버 변경 |
|---|---|---|---|
| ① 준비 | `git checkout main && git pull` | 최신 코드 받기 | 없음 |
| ② 백업·비교 | `scripts/deploy-files.sh check <기준커밋> <파일...>` | 문법·단위 테스트 → 서버 파일 백업 → Git 기준 버전과 비교 | 없음 |
| ③ 업로드 | `scripts/deploy-files.sh upload backup/<폴더>` | 서버 파일이 백업 이후 그대로인지 재확인 → `배포` 입력 → 업로드 → 다시 내려받아 일치 확인 | **있음(승인 필요)** |
| ④ 확인 | 브라우저 + `bash scripts/smoke.sh` | 기능 확인 | 없음 |
| ⑤ 복구 | `scripts/deploy-files.sh rollback backup/<폴더>` | `복구` 입력 → 백업 파일을 다시 올리고 일치 확인 | **있음(문제 시)** |

- **경로 규칙**: `public/X` → 서버 `/html/X`, `app/X` → 서버 `/app/X`. 그 외 경로는 거부합니다.
- **멈추는 경우(업로드 안 함)**: 비밀번호 오류, 서버 접속 불가, 서버에 파일 없음, 빈 파일, 서버 파일이 Git 기준 버전과 다름, 백업 이후 서버 파일이 바뀜, 로컬 파일에 커밋 안 된 변경, 문법 오류·테스트 실패, `배포`를 정확히 입력하지 않음.
- **업로드 중 실패 시**: 실패한 파일 이후로는 올리지 않고 복구 명령을 안내합니다. 여러 파일 중 일부만 올라갔을 수 있으므로 **안내된 `rollback`을 바로 실행**합니다.
- **비밀번호**: `FTP_PASS` 환경변수 → `.env.local`의 `FTP_PASS=` → 둘 다 없으면 입력창(화면에 안 보임) 순서. 명령줄에 넣지 않으며, 권한 600 임시 설정 파일로만 curl에 전달하고 종료 시 삭제합니다.
- **FTPS(암호화)**: `FTP_TLS=1`을 앞에 붙이면 암호화 접속만 허용합니다(실패하면 평문으로 바꾸지 않고 중단). 지원 여부 확인 방법은 8장 표 아래 참고.
- **백업 폴더**: 프로젝트 `backup/`(`.gitignore`로 Git 제외). 서버 원본 사본(`server/`), 비교용 Git 버전(`base/`), 대상 목록(`manifest.txt`), 커밋·시각 기록(`info.txt`)이 남습니다. 지우지 말고 배포 기록으로 보관합니다.

---

## 6. 운영 DB 변경 절차 (승인 필수)

> Claude(AI)는 운영 DB의 데이터·구조를 승인 없이 변경하지 않습니다. 변경 SQL은 파일로 작성해 제시하고, **승인 후 사용자가 직접 실행**하는 것이 기본입니다.
>
> **읽기 전용 조회는 승인 없이 가능**: DB 접속·연결 확인, 테이블·컬럼 구조 조회, `SELECT` 조회·집계, 재고 등 기능 개발에 필요한 데이터 확인.
> **사전 승인 필요**: `INSERT`/`UPDATE`/`DELETE` 등 데이터 변경, `CREATE`/`ALTER`/`DROP`/`TRUNCATE` 등 구조 변경, 마이그레이션·초기화, 운영 데이터 대량 추출·외부 전송, 계정 권한·서버 설정 변경, 부작용 가능성이 있는 프로시저 실행.
> 조회는 집계 위주로 하고, 개인정보·접속 인증정보는 출력·기록하지 않습니다. 접속이 안 되면 설정을 바꿔 우회하지 않고 사용자에게 phpMyAdmin용 조회 SQL을 안내합니다.

### 운영 DB 접속 경로 (2026-10-13 점검 결과)
- 운영 DB는 `DB_HOST=127.0.0.1`(서버 내부 전용)로, 외부에서 3306 포트로 직접 접속하는 구조가 아닙니다.
- Claude Code 클라우드 실행 환경은 **HTTP/HTTPS(프록시 경유) 외 통신(22·2022·21·3306 등)이 모두 차단**되어 MySQL 직접 접속·SSH 터널을 쓸 수 없습니다. 운영 DB 인증정보도 환경에 없습니다.
- 닷홈 SSH는 유료 웹호스팅에서만 2022 포트로 지원됩니다(무료 호스팅 미지원). SSH 터널은 사용자 PC의 Claude Code CLI에서만 검토 가능합니다.
- 클라우드 환경 네트워크 허용 목록에 `tomokmall.dothome.co.kr`가 추가되었습니다(다음 세션부터 적용). 이후 웹 API(HTTP/HTTPS) 경유 조회만 가능합니다.
  - 기존 공개 API: `GET /api/products/by-category.php?category_id={id}` (로그인 불필요, `stock_qty` 포함, 카테고리 단위)
  - 전체 집계·구조 조회가 필요하면 읽기 전용 조회 API를 별도로 설계·승인·배포해야 합니다(임의 SQL 실행 금지, 사전 정의 조회만, 토큰 인증).
- 그 전까지 집계 조회는 **사용자가 닷홈 phpMyAdmin에서 조회 SQL을 실행**하고 결과(집계값)를 공유하는 방식을 기본으로 합니다.

### 변경 절차

1. 변경 SQL을 `database/YYYYMMDD_설명.sql` 새 파일로 작성합니다(기존 파일은 수정하지 않음).
2. 로컬 DB에서 먼저 실행해 검증합니다.
3. 사용자에게 보고합니다: 변경 내용, 영향 받는 테이블·기능, 영향 받는 행 수(`SELECT COUNT(*) ...`), 롤백 SQL
4. **사용자 승인**
5. 닷홈 phpMyAdmin에서 대상 테이블 **백업(내보내기)**
6. phpMyAdmin에서 SQL 실행 (사용자가 직접)
7. 결과 확인 후 필요하면 코드 배포 (5장)

`DROP`, `TRUNCATE`, `ALTER ... DROP`, `WHERE` 없는 `UPDATE`/`DELETE`는 데이터 손실 위험을 별도로 설명하고 승인받습니다.
`scripts/db-tools/*.php`는 웹루트에 올리지 않으며, 운영 DB 대상 실행은 승인 후에만 합니다.

---

## 7. 배포 전 체크리스트

- [ ] 사용자 승인 받음 (배포 / DB 변경 각각)
- [ ] `git status` 깨끗함, 배포할 커밋 해시 기록
- [ ] `app/`, `public/`에 `.env` 등 시크릿·임시 파일 없음
- [ ] PHP 문법 검사, 단위 테스트 통과
- [ ] 로컬에서 기능 확인 완료
- [ ] 서버 파일 백업 (+ DB 변경 시 DB 백업)
- [ ] DB 변경이 있으면 DB 먼저 반영
- [ ] 서버에서 삭제할 파일 목록 정리 (있으면)
- [ ] 배포 후 스모크 + 수동 확인 계획
- [ ] 롤백 방법 준비

---

## 8. 현재 확인된 보안 관련 사항

(아직 수정하지 않았습니다. 수정하려면 별도로 요청·승인해 주세요.)

| 위치 | 내용 | 권장 조치 |
|---|---|---|
| `scripts/local-up.sh` | 로컬 DB 비밀번호와 관리자 계정(admin@admin.com)·비밀번호가 출력 문구로 커밋되어 있음 | 운영 DB·운영 관리자 계정이 같은 비밀번호를 쓰고 있다면 **즉시 변경**. 출력 문구에서 비밀번호 제거 검토 |
| `docker-compose.yml` | 로컬 DB 비밀번호 평문 | 운영과 다른 값인지 확인. `${DB_PASS}` 같은 환경변수 참조로 전환 검토 |
| `database/local-init.sql` | 로컬용 기본 관리자 계정 생성 SQL. 배포 시 서버 `/database`에도 업로드됨 | 운영 DB에서 실행하지 않음. 운영에 같은 계정이 있으면 비밀번호 변경 |
| 평문 FTP / HTTP | FTP 비밀번호와 사이트 로그인 정보가 암호화되지 않고 전송됨 | 닷홈 SFTP/FTPS·무료 SSL(HTTPS) 지원 여부 확인 후 적용 검토 |

**FTPS 지원 여부 확인 (서버 변경 없음)**: 닷홈 공식 안내에는 FTPS·SFTP 지원이 명시돼 있지 않습니다(2026-10 확인, SSH는 유료 상품만).
`FTP_TLS=1 scripts/deploy-files.sh check <기준커밋> <파일...>` 로 시험합니다. 이 명령은 내려받기만 합니다.
- 성공 → 이후 `upload`·`rollback`에도 항상 `FTP_TLS=1`을 붙입니다.
- `curl: (64) Requested SSL level failed` → FTPS 미지원. 평문 FTP로 진행하되 공용 Wi-Fi에서는 배포하지 않습니다.
- `curl: (60)`·`(51)` 인증서 오류 → 인증서 이름이 IP와 맞지 않는 경우가 많습니다. `FTP_HOST=<닷홈 안내 FTP 호스트명>`으로 다시 시험합니다. 인증서 검증을 끄는 옵션(`-k`)은 쓰지 않습니다.
- 확실한 답은 닷홈 고객센터(FTPS/SFTP 지원 여부) 문의를 권장합니다.

---

## 9. 배포 방식 개선 제안 (선택, 승인 후 진행)

현재 스크립트는 그대로 두었습니다. 필요하면 아래 개선을 별도 작업으로 진행할 수 있습니다.

1. **변경 파일만 업로드**: `git diff --name-only <배포 기준 커밋>..HEAD`로 대상 제한 → 실수로 올라가는 파일·배포 시간 감소
2. **드라이런(dry-run) 모드**: 실제 업로드 없이 업로드 예정 목록만 출력
3. **업로드 제외 목록**: `.env*`, `.DS_Store`, `*.log`, `public/uploads/*` 등 제외
4. **배포 전 확인 프롬프트**: 대상 서버·파일 수를 보여주고 `yes` 입력 시에만 진행
5. **자동 백업**: 업로드 전 대상 파일을 서버에서 내려받아 `backup/날짜/`에 보관
6. **비밀번호 노출 감소**: `curl --user` 대신 `--netrc-file`(권한 600 임시 파일) 사용, FTPS 지원 시 `--ssl-reqd`
7. **FTP_HOST/FTP_USER 환경변수화**: 스크립트 기본값은 유지하되 `.env.local`로 덮어쓸 수 있게
8. **php 미설치 시 중단**: 게이트 생략 대신 배포 중단
