# Claude 운영 서버 원격 관리 — 설계·API 명세

> 작성 2026-11-03 · 대상: 닷홈 무료호스팅(Apache 2.4 / PHP 8.4 / MySQL 8.0)
> 상태: **운영 설치 2026-11-04**(커밋 953e55e 기준 파일 11개 + 관리 테이블 4개, 연결 확인 완료). 가격 데이터 보호(12장)는 코드 완료 — **운영 반영은 FileZilla 재업로드 필요**
> 설치·사용 방법은 [`docs/REMOTE-OPS.md`](../REMOTE-OPS.md). 이 문서는 구조와 규약(명세)만 다룬다.

## 1. 목적과 원칙

phpMyAdmin(SQL 복사·실행)과 FileZilla(업로드·삭제)로 사람이 하던 운영 반영을, Claude Code 웹 세션이 **HTTPS API** 로 직접 하도록 한다.

| 원칙 | 구현 |
|---|---|
| 임의 SQL 을 받는 공개 엔드포인트 금지 | 모든 요청은 서명(HMAC) 인증. 조회 SQL 은 검사기 + `READ ONLY` 트랜잭션, 쓰기 SQL 은 **관리자가 본문을 보고 승인한 파일만** 실행 |
| 운영 DB 변경·서버 변경은 사용자 승인 | 쓰기 작업은 **작업 단위 승인(grant)** 이 있어야만 실행. 승인은 관리자 화면(관리자 세션 + CSRF)에서만 가능 — 키로는 승인 불가 |
| 가격 데이터는 바꾸지 않음 | 기존 DB 가격 정보는 **승인을 받아도** 서버가 변경을 막음(12장, `price_guard.php`) |
| DB 승인과 파일 승인 분리 | grant `kind` = `db` / `files`. 한쪽 승인으로 다른 쪽 작업 불가 |
| 승인 후 문장마다 다시 묻지 않음 | 승인 범위(테이블·컬럼·대상 행·최대 행 수·유효시간·횟수) 안에서는 자동 진행 |
| 되돌릴 수 있게 | 쓰기 전 자동 백업(행·파일·테이블), 되돌리기 명령, 실행 이력 |
| 사이트가 깨져도 복구 가능 | 관리 API 는 `app/bootstrap.php` 를 쓰지 않는 독립 코드. 관리 API 자체 파일은 원격으로 못 바꿈 |
| 기존 구조 유지 | 서버 디렉터리(`/html`·`/app`·`/database`), 경로 대응(`public/`→`/html`), DB 접속값(`/app/.env`) 그대로. 기존 파일 수정 없음 |

## 2. 1차 분석 결과(기존 구조)

| 항목 | 확인 내용 | 설계 반영 |
|---|---|---|
| 설정·DB 연결 | `app/bootstrap.php` 가 `/app/.env` → `.env.local` 을 읽고(셸 환경변수 우선) `db()` 가 PDO(`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`, utf8mb4) 생성. 기본값은 `app/config.php` | 같은 키·기본값·옵션을 `app/remote_ops/core.php` 에 그대로 사용(+ `MULTI_STATEMENTS=false`). bootstrap 은 세션 시작·여러 모듈 로드라 포함하지 않음(사이트 코드가 깨져도 복구 경로 유지) |
| DB 구조 | `database/schema.sql` + 추가 마이그레이션. 상품 `products`(price_b2c·price_b2b·cost_price·stock_qty), `categories`(discount_customer·discount_corporate), `product_vendors`(→products **ON DELETE CASCADE**), `users`(password_hash) | 구조화 쓰기는 기본키 기준. CASCADE 삭제는 별도 승인. 인증정보 컬럼은 조회·쓰기 금지 |
| 기존 SQL 실행 코드 | 관리자 API(`public/api/admin/*.php`)는 prepare+바인딩, 화면 세션 인증(`require_admin`) | 관리 API 도 값은 전부 바인딩, 식별자는 information_schema 로 존재 확인 후 백틱 |
| 세션·관리자 인증 | `app/auth.php` — 세션 `$_SESSION['user']`, 역할 customer/corporate/staff/admin, `require_admin()` 은 로그인 페이지로 이동. 쿠키 SameSite=Lax·HttpOnly·(HTTPS 면) Secure | 승인 화면은 `require_admin()` 재사용 + CSRF 토큰 추가. 관리 API 는 세션을 쓰지 않음 |
| 닷홈 응답 특성 | 4xx 응답 본문을 Apache 가 HTML 오류 페이지로 바꿈(기존 API 들이 200+ok:false 로 회피) | 관리 API 는 **항상 HTTP 200 + JSON** |
| 서버 디렉터리 | FTP 루트: `html/`(웹루트←`public/`), `app/`(웹 밖), `database/`(웹 밖) | 파일 API 는 이 세 곳만, 저장소 경로 이름(`public/…`)으로 주고받음 |
| 배포 절차 | `scripts/deploy.sh`(전체 덮어쓰기), `scripts/deploy-files.sh`(check→upload→rollback, 백업·바이트 비교, CRLF/LF 무시 비교) | 같은 흐름(비교→백업→업로드→재확인→복구)을 서버 안에서 원자적으로. 운영 파일은 Windows 에서 올려 CRLF 인 경우가 많아 `sha256_lf`(줄바꿈 무시 해시)도 제공 |
| Claude Code 웹 → 닷홈 | 2026-11-03 확인: `https://tomokmall.dothome.co.kr/` GET 200, 같은 도메인 POST(JSON) 통과(미설치 경로라 닷홈 404 HTML). 3306·21·22 는 차단(기존 기록) | HTTPS(443) 하나로 DB·파일 모두 처리. 외부 프록시가 TLS 를 다시 맺으므로 **본문을 AES-256-GCM 으로 한 번 더 암호화** |
| PHP 쓰기 가능 범위 | 로컬에서는 세 루트 모두 쓰기 가능. **운영은 설치 후 `tomok probe --write-test` 로만 확인 가능**(open_basedir·소유자·권한·disable_functions 포함) | 확인 전에는 파일 반영 가능하다고 단정하지 않음(§8) |

## 3. 구성

```
Claude Code 웹 컨테이너                         닷홈 서버
┌──────────────────────────┐   HTTPS POST   ┌──────────────────────────────────────────────┐
│ scripts/tomok (CLI, PHP) │ ─────────────▶ │ /html/api/remote-ops.php  (진입점, 3줄)        │
│  · 키: 환경변수            │  서명+암호화     │   └ /app/remote_ops/server.php (인증·라우팅)   │
│    TOMOK_OPS_KEY         │ ◀───────────── │       ├ db_ops.php   → MySQL (PDO)            │
│  · 응답 서명·nonce 확인    │  서명+암호화     │       ├ file_ops.php → /html /app /database   │
│  · 결과 JSON 출력         │                │       ├ grants.php   → remote_ops_grants      │
└──────────────────────────┘                │       └ audit.php    → remote_ops_log/snapshots│
          ▲                                  │ /html/admin-remote-ops.php (관리자 승인 화면)   │
          │ 승인 URL 안내                     └──────────────────────────────────────────────┘
          │                                                 ▲ 관리자 로그인(세션)·CSRF
     사용자(휴대폰·PC 브라우저) ──────────────────────────────┘ 승인/거절/회수
```

| 파일 | 역할 |
|---|---|
| `public/api/remote-ops.php` | 진입점. `app/remote_ops/server.php` 만 불러온다 |
| `app/remote_ops/protocol.php` | 서명·암호화 규약(서버·CLI 공용, 순수 함수) |
| `app/remote_ops/core.php` | 설정 파일 읽기, 전용 PDO, 오류 형식, 압축 |
| `app/remote_ops/server.php` | 인증 → 재전송 차단 → 범위 → 이력 → 작업 → 응답. `probe`, `grant.request` |
| `app/remote_ops/sql_guard.php` | SQL 토큰화·문장 분리·분류·조회 전용 검사·WHERE 조립(순수 함수) |
| `app/remote_ops/price_guard.php` | 가격 데이터 보호: 가격 컬럼 판정, SQL 쓰기 대상 분석, 구조화 쓰기·SQL 파일·복원·되돌리기 차단(12장) |
| `app/remote_ops/db_ops.php` | DB 조회·구조화 쓰기·SQL 파일·검증·되돌리기·백업 |
| `app/remote_ops/file_ops.php` | 경로 검사·파일 조회·배포·복구 |
| `app/remote_ops/grants.php` | 승인 생성·확인·사용·결정(관리자 화면 공용) |
| `app/remote_ops/audit.php` | 실행 이력·스냅샷 |
| `app/remote_ops/diff.php` | 줄 단위 비교(승인 화면) |
| `public/admin-remote-ops.php` | 관리자 승인 화면 |
| `database/20261103_remote_ops.sql` | 관리 테이블 4개(추가형) |
| `scripts/tomok`, `scripts/remote-ops/tomok.php` | CLI |
| `tests/remote_ops.test.php` | 단위 테스트(238) |
| `scripts/remote-ops/test-local.sh`, `integration.test.php` | 로컬 통합 테스트(189) |

## 4. 인증·보안

### 4-1. 키
- 32바이트 무작위 키(16진수 64자). 서버 `/app/.env.remote-ops` 의 `REMOTE_OPS_KEY`, Claude Code 환경변수 `TOMOK_OPS_KEY`. **Git·채팅·문서·로그에 두지 않는다**(`.gitignore` 의 `.env.*` 로 제외).
- HKDF-SHA256 으로 서명키(`mac`)·암호키(`enc`)를 분리.
- `REMOTE_OPS_KEY_ID`(kid)로 교체 구분. 키 교체 = 두 곳 값 바꾸기(§ 매뉴얼 7장).

### 4-2. 요청·응답 봉투
```json
{"v":1,"kid":"k1","ts":1730600000,"nonce":"<32 hex>","op":"db.query",
 "enc":"aes-256-gcm","iv":"<b64 12B>","ct":"<b64 암호문+태그>","sig":"<b64 HMAC>"}
```
- 서명 대상: `TOMOK-OPS-1 \n 방향(req|res) \n kid \n ts \n nonce \n op \n enc \n iv \n ct`
- 암호화: AES-256-GCM, AAD = `TOMOK-OPS-1|방향|kid|ts|nonce|op`. 평문 요청 `{"params":{…}}`, 평문 응답 `{"ok":bool,"result"|"error","op_id"}`
- 응답도 같은 키로 서명·암호화하고 **요청 nonce 를 그대로 돌려줌** → CLI 가 가짜·재사용 응답을 거부
- `enc:"none"`(평문)은 `probe` 만 허용(서버에 openssl 이 없을 때 진단용). `REMOTE_OPS_REQUIRE_ENC=0` 일 때만 다른 작업도 허용

### 4-3. 재전송 방지·수명
- `ts` 는 서버 시각 ±300초(`REMOTE_OPS_CLOCK_SKEW`) 안이어야 함 → 요청 수명 5분
- `nonce` 는 `remote_ops_nonces` 에 1회 등록(PK 중복 = `replay`), 10분 뒤 정리
- 세션·쿠키·Bearer 토큰 없음. 키 자체는 네트워크로 보내지 않음

### 4-4. 처리 순서와 거부 응답
POST 확인 → `REMOTE_OPS_ENABLED=1` → (선택) IP 허용 목록 → 봉투 모양 → kid → 시각 → 서명 → 복호화 → 작업·범위(`REMOTE_OPS_SCOPES`) → DB 연결·nonce → 이력 시작 → 작업 → 이력 종료 → 응답.
승인 상태·목록·이력 보기(`grant.status`·`grant.list`·`log.list`·`log.get`)는 이력에 남기지 않는다(반복 확인으로 이력이 쌓이지 않게, 2026-11-04). 인증 전 실패는 서명 없는 `{"ok":false,"error":"auth_failed","reason":…}` (reason: `method` `disabled` `ip_denied` `bad_request` `unknown_key` `expired` `bad_signature` `decrypt_failed` `too_large`). 이력에 남기지 않음(무작위 접근으로 이력이 쌓이지 않게).

### 4-5. 작업별 권한(범위)
| 범위 | 작업 |
|---|---|
| `probe` | 진단 |
| `db.read` | `db.tables` `db.describe` `db.query` `db.search` `db.verify` |
| `db.write` | `grant.request` `db.stage_sql` `db.stage_restore` `db.update` `db.batch` `db.insert` `db.delete` `db.execute` `db.revert` `db.backup` |
| `files.read` | `files.list` `files.read` `files.hash` `files.manifest` `files.lint` `files.snapshot` |
| `files.write` | `files.stage` `files.deploy` `files.restore`, `probe` 의 `write_test` |
| (항상) | `grant.status` `grant.list` `grant.cancel` `log.list` `log.get` |

`REMOTE_OPS_SCOPES` 로 키가 쓸 수 있는 범위를 줄일 수 있다(예: 조회만 `probe,db.read,files.read`). **쓰기 범위가 있어도 승인 없이는 쓰기 불가.**

### 4-6. 보호 대상(서버에서 강제)
- DB: `remote_ops_*` 관리 테이블 쓰기 금지(자기 승인 조작 차단), 인증정보 컬럼(`password_hash`, `*token*`, `*secret*`, `api_key` …) 조회 SQL 거부·결과 `[REDACTED]`·쓰기 거부, 개인정보 컬럼(이메일·전화·주소·고객명·users.name …) 결과 기본 `[PII]`(`--reveal-pii` 시에만 표시, 이력에 표시 여부 기록), 다른 DB·`mysql.`·`sys.`·`performance_schema.` 금지
- 연결 세션: `sql_mode` 에서 `ANSI_QUOTES`·`NO_BACKSLASH_ESCAPES` 를 빼고 `autocommit=1` 로 고정(검사기가 가정하는 문법과 MySQL 해석을 일치시킴). 실패하면 작업하지 않음
- 조회 SQL: 한 문장, SELECT/WITH…SELECT/SHOW(허용 목록: TABLES·COLUMNS·INDEX·CREATE TABLE·TABLE STATUS·VARIABLES·STATUS 등)/DESCRIBE/EXPLAIN 만, `INTO`·`FOR UPDATE`·`LOCK`·`SLEEP()`·`LOAD_FILE`·`EXPLAIN/DESC ANALYZE` 거부, **인증정보 컬럼이 있는 테이블(users 등)은 `*` 금지(컬럼 이름 지정)** — UNION·CTE 열 이름으로 바꿔 가림을 피하는 것 차단, `remote_ops_snapshots`(백업 본문) 조회 금지, `START TRANSACTION READ ONLY` 안에서 실행(쓰기는 MySQL 이 한 번 더 막음), 15초 제한, 최대 2,000행
- SQL 파일: **승인 전에는 실행하지 않는다**(대상 행 수는 같은 조건의 `COUNT` 를 READ ONLY 트랜잭션에서). 허용은 한 테이블 INSERT·REPLACE·UPDATE·DELETE 또는 TABLE·INDEX DDL 이고 **DDL 과 DML 은 한 파일에 섞지 않음**. SET(모든 형태)·SELECT 문장(확인은 `-- @verify`)·여러 테이블 UPDATE/DELETE·GRANT/USER/DATABASE/LOAD/CALL/LOCK/트랜잭션 제어/PREPARE/프로시저·트리거·뷰/DELIMITER/INTO OUTFILE 금지, `remote_ops_*`(따옴표 안 포함)·인증정보 컬럼 언급 금지. 실행 주석 `/*! … */` 안도 코드로 검사. 파일은 1MB·2,000줄 이하(승인 화면에 전부 표시)
- 파일: `public/`·`app/`·`database/` 밖, 절대경로, `..`, 빈 경로 조각, 제어문자·역슬래시, 숨김 파일(`.env*`·`.git` 등, `.htaccess` 만 예외), 경로 중간·끝 **심볼릭 링크**, 루트 밖으로 풀리는 실제 경로 거부. 쓰기는 허용 확장자만(php·js·css·html·json·sql·txt·md·svg·이미지·글꼴 등), 경로 255바이트 이하, 크기 4MB(`REMOTE_OPS_MAX_FILE_BYTES`), 텍스트 파일은 NUL 없는 UTF-8 만(승인 화면에서 '이진 파일'로 숨겨지지 않게). `app/bootstrap.php`·`app/auth.php`·`app/admin_layout.php`·`app/config.php`·모든 `.htaccess` 는 승인 시 **추가 확인 필수**(승인 화면·인증을 바꿀 수 있음). 보이지 않는 문자(양방향 제어·폭 없는 문자)가 있으면 경고 + 추가 확인. **관리 API 자체 파일**(`app/remote_ops/**`, `public/api/remote-ops.php`, `public/admin-remote-ops.php`) 쓰기 금지, `public/uploads/**` 읽기·쓰기 금지
- **가격 데이터(12장)**: 가격 컬럼 쓰기·가격 테이블 삭제·가격 계산 입력값 변경·가격 테이블 비가산 DDL·가격 테이블 복원을 승인과 무관하게 거부(`price_protected`)
- 저장 용량: 백업 총량 상한 `REMOTE_OPS_STORAGE_MB`(기본 200), 승인 대기 요청 최대 10건, 한 번에 맡기는 파일 합계 8MB
- 백업·행 스냅샷에는 인증정보 컬럼을 저장하지 않음(인증정보 컬럼이 있는 테이블은 원격 삭제 불가 — 비활성화로)
- 출력: 응답·이력에 키·서명·DB 비밀번호 없음(오류문구에서도 비밀번호 문자열 제거), 이력에는 파일 본문·행 데이터 대신 요약만

## 5. 작업 단위 승인(grant)

```
CLI: 승인 요청(pending) ──▶ 관리자 화면: 승인(approved, 유효시간 시작) / 거절 / (언제든) 회수
                                      │
CLI: 범위 안 쓰기(여러 번) ─▶ 사용량 누적 ─▶ max_ops 도달·1회용 사용 → used / 유효시간 지남 → expired
CLI: 되돌리기 ─▶ 원래 승인의 유효시간 + 24시간 안이면 같은 승인으로 허용(회수·거절된 승인은 불가)
```

| kind/mode | 요청 명령 | 승인 화면에 보이는 것 | 실행 명령 | 고정되는 것 |
|---|---|---|---|---|
| `db/scope` | `tomok db request` | 테이블·작업(수정/추가/삭제)·바꿀 컬럼·대상 PK(현재값 표)·1회/전체 최대 행 수 | `db update/batch/insert/delete` (여러 번, `--max-ops`) | 범위(테이블·컬럼·PK·행 수) |
| `db/sql` | `tomok db stage-sql` | SQL 본문 전체, SHA-256, DDL 여부, 경고(WHERE 없음 등), **실행하지 않고 COUNT 로 센 문장별 대상 행 수**, @verify 현재값 | `db execute` (1회) | SQL 본문(해시), 최대 행 수 |
| `files/staged` | `tomok files stage` | 파일별 동작(교체·새 파일·삭제), **서버가 직접 계산한 현재 서버 파일과의 diff**, 요청 이후 서버 변동 여부, 서버 PHP 문법 검사 결과 | `files deploy` (1회) | 파일 내용(해시), 요청 시점 서버 파일 해시 |

- **추가 확인(별도 승인 절차)**: DDL 포함, WHERE 없는 UPDATE/DELETE, 20행 초과 삭제, 대량 삭제·CASCADE 삭제 허용 요청, 인증·승인 화면 관련 파일·`.htaccess` 배포, 보이지 않는 문자 포함이면 승인 화면에 확인란이 생기고 체크해야 승인된다.
- 유효시간 5~1,440분(기본 60), 승인 대기는 24시간 뒤 만료. 관리자 화면 '전체 회수'로 모든 원격 쓰기를 즉시 중지.
- 파일 승인은 요청 이후 서버 파일이 바뀌면(FileZilla 로 누가 수정) 승인 버튼이 거부되고, 배포 시점에도 다시 확인한다.

## 6. DB 작업 명세

모든 응답 `result` 는 JSON. 오류는 `error.code`(영문)·`error.message`(한국어)·`error.data`.

| 작업 | 주요 인자 | 결과·동작 |
|---|---|---|
| `db.tables` | `exact_counts?` | 테이블 목록(엔진·추정 행 수·크기·수정 시각), DB 버전 |
| `db.describe` | `table` | 컬럼·기본키·인덱스·외래키·CASCADE 자식·CREATE TABLE |
| `db.query` | `sql`, `params?`(? 바인딩), `limit?`(≤2000), `reveal_pii?` | `columns`, `rows`, `truncated` |
| `db.search` | `kind`(products/categories), `q`(띄어쓰기 단어 모두 포함), `limit?`, `include_inactive?` | 상품: 이름·브랜드·규격·카테고리·업체명 검색, 가격·재고·카테고리 마진율 |
| `grant.request` | `title`, `tables`, `actions`, `columns?`, `ids?`, `max_rows_total`, `max_rows_per_op?`, `ttl_minutes?`, `max_ops?`, `allow_bulk_delete?`, `allow_cascade_delete?` | pending grant + 대상 행 미리보기 + 승인 URL |
| `db.update` | `grant`, `table`, `set{}`, `where`, `expect_rows?`, `dry_run?` | ① 트랜잭션 ② WHERE 로 **대상 PK 먼저 확정**(FOR UPDATE) ③ 행 수·범위 확인 ④ 변경 전 행 저장 ⑤ PK 로 UPDATE ⑥ 재조회 ⑦ 전후 diff 저장 ⑧ 커밋(드라이런이면 롤백) |
| `db.batch` | `grant`, `table`, `rows[{key, set, expect?}]` | 행마다 다른 값. `expect` 와 현재값이 다르면 전체 롤백(`expect_mismatch`) |
| `db.insert` | `grant`, `table`, `rows[{…}]` | 새 PK 반환, 되돌리기 = 삭제 |
| `db.delete` | `grant`, `table`, `where`, `expect_rows?` | 20행 초과는 대량 삭제 승인, CASCADE 자식이 있으면 별도 승인 |
| `db.verify` | `operation` | 현재값이 작업 직후 값과 같은지(SQL 실행은 @verify 재조회 비교) |
| `db.revert` | `operation`, `dry_run?`, `force?` | 작업 뒤 다시 바뀐 행이 있으면 `revert_conflict`(force 로만 덮어씀) |
| `db.stage_sql` | `sql`, `title`, `max_rows?`, `ttl_minutes?` | 검사 → (실행 없이) 문장별 대상 행 수 COUNT + @verify 현재값(READ ONLY) → pending grant. 없는 테이블 등 오류가 있으면 승인 요청이 만들어지지 않음 |
| `db.execute` | `grant`, `dry_run?` | DML: 한 트랜잭션, 문장마다 실행 직전 대상 행 수를 다시 세어 승인 최대를 넘으면 전체 롤백, 문장 뒤 트랜잭션이 끝나 있으면 즉시 중단. DDL: 승인 소진 확정 → ALTER/DROP/TRUNCATE/RENAME 대상 테이블 자동 백업 → 문장 순서대로(MySQL 이 문장마다 커밋) |
| `db.backup` | `tables[]`(≤10) | 테이블 덤프(복원용 upsert SQL)를 관리 테이블에 저장(20MB 이하). 외부로 내려받지 않음(대량 추출 금지) |
| `db.stage_restore` | `snapshot` | 백업 → 복원 SQL 승인 요청(행 값을 백업 시점으로, 이후 추가된 행은 남음) |

SQL 파일 안 `-- @verify SELECT …` 줄은 실행 전후 같은 조회를 돌려 비교한다(조회 전용 검사 적용).

## 7. 파일 작업 명세

| 작업 | 주요 인자 | 결과·동작 |
|---|---|---|
| `files.list` | `path`, `recursive?`, `hashes?` | 이름·크기·수정 시각·권한·쓰기 가능·쓰기 보호 표시. 링크는 따라가지 않음 |
| `files.read` | `path` | 내용(base64)·sha256·sha256_lf·줄바꿈 종류 |
| `files.hash` | `paths[]` | 파일별 존재·해시 |
| `files.manifest` | `roots[]` | 세 루트 전체 파일 해시 → CLI 가 Git 커밋과 비교(same/eol_only/differs/missing_on_server/extra_on_server) |
| `files.lint` | `paths[]` | 서버 PHP 파서(`token_get_all(TOKEN_PARSE)`)로 문법 검사 |
| `files.stage` | `title`, `files[{path, content_b64, sha256}]`, `delete[]` | 경로·확장자·크기·해시·서버 PHP 문법 확인 → 내용을 관리 테이블에 맡기고 pending grant |
| `files.deploy` | `grant`, `dry_run?` | 승인 소진 확정 → 서버 파일이 요청 때 그대로인지 → 전부 백업 → 같은 폴더 임시 파일에 쓰고 `rename`(원자적 교체, 권한 유지) → 다시 읽어 해시 확인 → 문법 재검사 → opcache 무효화. **중간 실패 시 이미 바꾼 파일을 자동 원복** |
| `files.restore` | `operation`, `dry_run?`, `force?` | 배포 직전 상태로(새로 만든 파일은 삭제, 지운 파일은 복원). 복구도 다시 백업되어 되돌릴 수 있음 |
| `files.snapshot` | `id` | 파일 백업 내용 보기(테이블 덤프는 제외) |

CLI 쪽 추가 기능: `files diff`(서버 ↔ 로컬 커밋 diff), `files stage` 는 **커밋된 내용만**(작업 폴더가 커밋과 다르면 거부) + 로컬 `php -l`, `files deploy --check /경로` 는 배포 후 HTTP 상태·PHP 오류 문구·표시 문구를 확인하고 실패하면 복구 명령을 안내.

## 8. 관리 테이블(`database/20261103_remote_ops.sql`)

| 테이블 | 내용 |
|---|---|
| `remote_ops_nonces` | nonce(PK), 만료 시각 |
| `remote_ops_grants` | 코드·종류·방식·제목·설명·범위 JSON·SQL 본문·미리보기·상태·유효시간·횟수·사용량·결정자(users.id)·메모 |
| `remote_ops_log` | 작업 1건 = 1행: 시각·작업·결과(ok/error/denied/dry_run/running)·승인·대상·영향 행 수·소요 ms·IP·요청 요약·결과 요약·오류·되돌림 연결 |
| `remote_ops_snapshots` | 행 전후, @verify 전후, 파일 전후, 승인 대기 파일, 테이블 덤프(gzip). 파일 백업을 DB 에 두는 이유: 운영 서버의 쓰기 가능 폴더가 확인되지 않아도 백업·복구가 되도록 |

## 9. 오류 코드(주요)

| 코드 | 뜻 |
|---|---|
| `grant_pending` `grant_inactive` `grant_exhausted` `grant_mismatch` `grant_not_found` | 승인 없음·대기·만료·회수·소진·종류 다름 |
| `out_of_scope` `too_many_rows` `bulk_delete_not_approved` `cascade_not_approved` `forbidden_table` `forbidden_column` `path_denied` `scope_denied` | 승인·권한 범위 밖 |
| `sql_not_allowed` `sql_error` `expect_mismatch` `row_count_mismatch` `revert_conflict` `already_reverted` | SQL 검사·실행·비교 |
| `bad_path` `symlink` `outside_root` `server_changed` `php_syntax` `hash_mismatch` `apply_failed` `too_large` | 파일 |
| `price_protected` | 가격 관련 기존 데이터를 바꾸는 요청(승인과 무관하게 거부, 아무것도 실행 안 함 → 사용자에게 보고) |
| `replay` `encryption_required` `not_ready` `db_unavailable` `internal` | 통신·설치·내부 |

## 10. 알려진 한계

- 조회 SQL 검사기는 완전한 SQL 파서가 아니다(애매하면 거부). 최종 방어는 MySQL `READ ONLY` 트랜잭션.
- 개인정보 가림은 결과 컬럼 이름 기준(별칭·UNION 으로 이름을 바꾼 개인정보 컬럼은 못 가린다 — 편의 기능). 인증정보 컬럼은 이름이 나오거나 해당 테이블에 `*` 를 쓰면 거부(강제).
- 승인 전 대상 행 수는 현재 데이터 기준 COUNT 라 앞 문장의 영향이 반영되지 않는다(실행 때 문장마다 다시 셈).
- 인증정보 컬럼이 있는 테이블의 백업에는 그 컬럼이 없어, 삭제된 행은 백업으로 되살릴 수 없다.
- 서버 PHP 문법 검사는 구문만 본다(함수 중복 정의 등 실행 시 오류는 HTTP 확인으로).
- DDL 은 MySQL 특성상 롤백되지 않는다 → 자동 백업 + 복원 승인으로 대응. 구조 자체(ALTER) 되돌리기는 백업의 CREATE TABLE 을 보고 수동.
- 테이블 덤프·파일 백업은 운영 DB 용량을 쓴다(정리 SQL 은 매뉴얼).
- 키를 가진 사람은 조회를 승인 없이 할 수 있다(CLAUDE.md 6장의 읽기 전용 허용 범위와 같음). 쓰기는 관리자 승인 필수.

## 11. 보안 검토 반영 (2026-11-03, 독립 검토)

| # | 심각도 | 발견 | 조치 |
|---|---|---|---|
| 1 | 치명 | 승인 전 `db.stage_sql` 의 '롤백 예정' 미리 실행이 `SET @a=1, autocommit=0` + `"remote_ops_grants"`(ANSI_QUOTES) 로 실제 커밋되어 자기 승인을 승인 상태로 바꿀 수 있었음(MySQL 8.0 에서 재현) | 승인 전 SQL 실행 폐지(COUNT 를 READ ONLY 트랜잭션에서), SET 전면 금지, 따옴표 안 `remote_ops_` 금지, 여러 테이블 DML 금지, 세션 sql_mode·autocommit 고정, 실행 중 트랜잭션 종료 감지 |
| 2 | 높음 | `UNION`·CTE 열 이름으로 `password_hash` 를 가림 없이 조회, 테이블 덤프·행 스냅샷·@verify 로도 유출 가능 | 인증정보 테이블 `*` 금지, `remote_ops_snapshots` 조회 금지, 덤프·스냅샷에서 인증정보 컬럼 제외, SQL 파일의 인증정보 컬럼 언급 금지 |
| 3 | 중간 | `SHOW CREATE USER`(DB 계정 해시)·`SHOW FULL PROCESSLIST`·`DESC ANALYZE` 통과 | SHOW 허용 목록 방식, DESC/DESCRIBE ANALYZE 금지 |
| 4 | 중간 | 승인 요청의 columns/ids 테이블 오타가 '제한 없음'으로 적용 | columns/ids 는 `{tables 안의 테이블: 목록}` 만 허용 |
| 5 | 중간 | NUL 바이트로 PHP 를 '이진 파일'로 보이게 해 승인 화면에서 숨김, SQL 400줄 자름 | 텍스트 파일 NUL·비UTF-8 거부, SQL 파일 전체 표시(2,000줄 제한), 보이지 않는 문자 경고 |
| 6 | 중간 | DDL 파일 안 DML 은 행 수 확인·백업 없음 | DDL·DML 분리 강제 |
| 7 | 낮음~중간 | 추가(insert)에 승인 컬럼 미적용, WHERE 에 인증정보 컬럼 사용 가능(드라이런 반복 추측) | 추가도 승인 컬럼만, WHERE 에서 인증정보 컬럼 제외 |
| 8 | 낮음~중간 | 승인 화면이 의존하는 auth.php·bootstrap.php·admin_layout.php·.htaccess 배포 | 추가 확인 필수 + 경고 |
| 9 | 낮음 | 255바이트 넘는 경로가 스냅샷 ref 에서 잘림 | 경로 255바이트 제한 |
| 10 | 낮음 | 승인 없이 쌓을 수 있는 백업·요청으로 DB 용량 소모, 중단된 배포(running) 복구 불가 | 백업 총량 상한·대기 요청 10건 상한·요청 파일 합계 8MB, running 작업 복구 허용(--force), 되돌리기 선점(동시 실행 방지), `ignore_user_abort` |

각 항목은 단위 테스트·통합 테스트(공격 SQL 을 실제 MySQL 8.0 에 보내 아무것도 커밋되지 않음을 확인)에 회귀 테스트로 들어 있다.

## 12. 운영 정책 (사용자 결정 2026-11-04)

### 12-1. 관리자 승인 방식 유지
`/admin-remote-ops.php` 승인 방식을 그대로 쓴다: 운영 DB 변경·운영 서버 파일 변경 모두 관리자 화면 승인 후 실행, DB 승인과 파일 승인은 별도(grant `kind`), 승인 범위 안에서는 반복 승인 없이 자동 실행(5장). 채팅 승인으로 대체하는 방식은 만들지 않는다. (2026-11-10 키 API 승인 `grant.approve` 를 구현했다가 보안 문제로 되돌림 — 키만으로 요청·승인·실행이 모두 가능해지기 때문. 관리자 화면 승인 유지.)

### 12-2. 가격 관련 기존 DB 데이터 변경 금지
원격 관리 API 로는 기존 가격 정보를 **어떤 경로로도** 바꾸지 않는다. 관리자 승인을 받아도 유지된다. 조회·분석은 허용. 가격 계산 로직(`app/pricing.php`)은 바꾸지 않는다(이 기능은 차단만 추가).

**가격 컬럼 판정**(`rops_is_price_column`, 이름 기준·대소문자 무시): 이름에 `price`·`cost` 포함, 또는 밑줄로 나눈 조각이 `price(s) cost(s) discount(s) margin(s) markup fee(s) amount(s) amt tax vat surcharge` 중 하나.
운영 구조(2026-11-04 information_schema)에서 해당하는 컬럼: `products.price_b2c·price_b2b·cost_price`, `categories.discount_customer·discount_corporate`, `product_vendors.supply_price`, `estimates.shipping_fee·discount_amt`, `estimate_items.unit_price·adjusted_price`, `order_items.unit_price`, `orders.total_amount`, `payments.amount`, `external_products.ref_price·price_basis`. 앞으로 생기는 같은 이름 규칙의 컬럼도 자동 포함.
**가격 계산 입력값**(기존 행에서 바꾸면 표시 가격이 달라짐): `products.category_id`(적용 마진율), `categories.parent_id`(마진율 상속).

| 경로 | 차단 규칙 |
|---|---|
| `grant.request` | 바꿀 컬럼에 가격 컬럼·입력값 포함, 가격 테이블 삭제 요청, 삭제가 가격 테이블로 번지는(CASCADE/SET NULL) 요청, 트리거가 있는 테이블 → 요청 단계에서 거부. 승인 화면에 '가격 보호(변경 불가)' 컬럼 표시 |
| `db.update` · `db.batch` | SET 에 가격 컬럼·입력값이 하나라도 있으면 요청 전체 거부(드라이런 포함, 일괄 수정은 한 행이라도 있으면 전체) |
| `db.insert` | 새 행에도 가격 컬럼 값 넣기 거부(가격 없이 추가하면 DB 기본값 — 가격은 사람이 입력) |
| `db.delete` | 가격 테이블 행 삭제, 가격 테이블로 번지는 삭제 거부 |
| `db.stage_sql` · `db.execute` | 문장마다 쓰기 대상 분석(`rops_sql_write_targets`): UPDATE SET·ON DUPLICATE KEY UPDATE 의 가격 컬럼·입력값, INSERT 컬럼 목록의 가격 컬럼, 가격 테이블에 컬럼 목록 없는 INSERT·REPLACE·DELETE, 가격 테이블로 번지는 DELETE, 가격 테이블 DROP/TRUNCATE/RENAME, 가격 테이블 ALTER 중 ADD COLUMN·ADD/DROP INDEX 외, 트리거 테이블, 저장 함수 호출 → 승인 요청 때와 실행 직전 두 번 검사 |
| `db.stage_restore` | 가격 테이블 백업 복원 거부(가격이 백업 시점으로 바뀜). 백업 자체(조회)는 허용 |
| `db.revert` | 가격 컬럼을 되돌리게 되는 경우, 가격 테이블에 지운 행 다시 넣기, 가격 테이블에서 충돌 무시(force) 거부. 방금 추가한 행 되돌리기(삭제)는 허용 |

거부 응답: `error.code = price_protected`, 메시지 끝에 "실행하지 않았으니 사용자에게 보고하세요". 이력(remote_ops_log)에는 `denied` 로 남는다.
검증: 단위 테스트(가격 컬럼 판정·쓰기 대상 분석·ALTER 판정·SQL 계획 차단/통과 사례)와 통합 테스트(승인받은 범위에서도 가격 쓰기 거부, SQL 파일 12종 거부, 시도 전후 가격 데이터 전체를 독립 SQL 로 비교해 변동 없음).
