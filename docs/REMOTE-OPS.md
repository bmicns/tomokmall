# Claude 운영 서버 원격 관리 — 설치·설정·사용 매뉴얼

> 설계·API 명세: [`docs/02-design/remote-ops.md`](02-design/remote-ops.md) · 운영 배포 기준: [`docs/DEPLOYMENT.md`](DEPLOYMENT.md)
> **상태(2026-11-03)**: 로컬 구현·테스트 완료. **운영 서버에는 아직 설치하지 않았다**(설치 = 운영 배포 + 운영 DB 구조 변경 → 사용자 승인 필요).
> 비밀번호·키 값은 이 문서·Git·채팅에 적지 않는다.

## 1. 무엇이 바뀌나

| 지금 | 설치 후 |
|---|---|
| phpMyAdmin 로그인 → SQL 붙여넣기 → 실행 → 결과 복사 | Claude 가 `scripts/tomok db …` 로 조회·수정하고 결과를 직접 확인 |
| FileZilla 로 파일 내려받기·올리기·지우기 | Claude 가 `scripts/tomok files …` 로 비교·백업·배포·복구 |
| 승인 = 채팅에서 "진행해" | 승인 = **관리자 화면(`/admin-remote-ops.php`)에서 작업 단위로 '승인' 클릭** (휴대폰 가능). 승인한 범위 안에서는 Claude 가 문장마다 묻지 않고 진행 |

조회(SELECT·구조·파일 목록·해시)는 승인 없이 가능(CLAUDE.md 6장의 읽기 전용 허용과 같음). 쓰기(INSERT·UPDATE·DELETE·DDL·파일 배포·삭제)는 승인 없이는 서버가 거부한다.

## 2. 현재 환경에서 가능한 것 / 불가능한 것

| 항목 | 결과 | 근거 |
|---|---|---|
| Claude Code 웹 → `https://tomokmall.dothome.co.kr` | ✅ 가능 | 2026-11-03 GET `/` 200, POST(JSON) 통과(미설치 경로라 닷홈 404 HTML) |
| MySQL 3306 직접 접속·SSH·FTP(21) | ❌ 불가 | 클라우드 환경이 HTTPS 외 차단, 닷홈 무료 SSH 없음(DEPLOYMENT.md 6장) |
| HTTPS API 로 DB 조회·수정 | ✅ 구현·로컬 검증 완료 / 운영은 설치 후 | §8 |
| HTTPS API 로 파일 배포·복구 | ✅ 구현·로컬 검증 완료 / **운영 PHP 쓰기 권한은 설치 후 `probe --write-test` 로만 확인 가능** | 확인 전에는 가능하다고 단정하지 않음 |
| 키 전달 | ✅ Claude Code 환경 설정의 **환경변수**(`TOMOK_OPS_KEY`) | 'Network secrets'(프록시가 헤더를 넣는 방식)는 요청 서명에 키가 필요해 쓰지 않음 |
| 관리 API 자체 파일 수정 | ❌ 의도적으로 막음 | 관리 API 갱신은 FileZilla 로 |

## 3. 최초 설치 (사용자 작업, 단계마다 승인)

> 설치 대상 파일은 모두 **새 파일**이다. 기존 서버 파일은 바꾸지 않는다(기존 파일 백업 불필요). 설치 전 `main` 병합 승인 → 병합 커밋 기준으로 올린다.

### 3-1. 키 만들기 (PC, Windows PowerShell)

PowerShell 을 열고 아래를 실행하면 바탕화면에 `.env.remote-ops` 가 생기고 키가 클립보드에 복사된다(화면에는 출력되지 않음).

```powershell
$b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); $k = ($b | ForEach-Object { $_.ToString('x2') }) -join ''
@('# Claude remote ops API key - upload to /app/.env.remote-ops (never commit, never paste in chat)', 'REMOTE_OPS_ENABLED=1', 'REMOTE_OPS_KEY_ID=k1', "REMOTE_OPS_KEY=$k") | Set-Content -Encoding ASCII "$env:USERPROFILE\Desktop\.env.remote-ops"
$k | Set-Clipboard; Remove-Variable k, b
```

- 파일은 저장소(`app/` 폴더 포함) 안에 두지 않는다. `scripts/deploy.sh` 는 로컬 `app/` 의 모든 파일을 올리므로 `app/.env.remote-ops` 가 로컬에 있으면 운영 파일을 덮어쓴다.
- 선택 설정(필요할 때만 같은 파일에 추가):
  - `REMOTE_OPS_SCOPES=probe,db.read,files.read` → 조회만 허용(쓰기 범위 제거)
  - `REMOTE_OPS_ALLOWED_IPS=…` → IP 제한(Claude 출구 IP 는 바뀔 수 있어 권장하지 않음)
  - `REMOTE_OPS_MAX_FILE_BYTES=4194304`(파일 1개 최대), `REMOTE_OPS_CLOCK_SKEW=300`(요청 수명 초)

### 3-2. Claude Code 환경변수 (클립보드의 키)

Claude Code 웹 → 세션 제목 줄의 환경 메뉴 → **Edit** → 환경변수(Environment variables)에 추가:

```
TOMOK_OPS_KEY=<클립보드 값 붙여넣기>
TOMOK_OPS_KEY_ID=k1
```

새 세션부터 적용된다. 네트워크 허용 목록에 `tomokmall.dothome.co.kr` 이 있는지 확인(현재 있음). **채팅에는 붙여넣지 않는다.**

### 3-3. 관리 테이블 만들기 (phpMyAdmin, 운영 DB 구조 변경 — 승인 필요)

1. phpMyAdmin → `tomokmall` DB → (권장) 내보내기로 백업
2. SQL 탭에 `database/20261103_remote_ops.sql` 내용 실행 — **새 테이블 4개만 만든다**(`IF NOT EXISTS`, 기존 테이블 변경 없음)
3. 확인: `SHOW TABLES LIKE 'remote\_ops\_%';` → 4행

### 3-4. FileZilla 업로드 목록 (운영 배포 — 승인 필요)

| # | 로컬(저장소) | 서버 | 비고 |
|---|---|---|---|
| 1 | `app/remote_ops/protocol.php` | `/app/remote_ops/protocol.php` | `remote_ops` 폴더 새로 만듦 |
| 2 | `app/remote_ops/core.php` | `/app/remote_ops/core.php` | |
| 3 | `app/remote_ops/sql_guard.php` | `/app/remote_ops/sql_guard.php` | |
| 4 | `app/remote_ops/audit.php` | `/app/remote_ops/audit.php` | |
| 5 | `app/remote_ops/grants.php` | `/app/remote_ops/grants.php` | |
| 6 | `app/remote_ops/db_ops.php` | `/app/remote_ops/db_ops.php` | |
| 7 | `app/remote_ops/file_ops.php` | `/app/remote_ops/file_ops.php` | |
| 8 | `app/remote_ops/diff.php` | `/app/remote_ops/diff.php` | |
| 9 | `app/remote_ops/server.php` | `/app/remote_ops/server.php` | |
| 10 | `public/api/remote-ops.php` | `/html/api/remote-ops.php` | 진입점 |
| 11 | `public/admin-remote-ops.php` | `/html/admin-remote-ops.php` | 승인 화면 |
| 12 | 바탕화면 `.env.remote-ops`(3-1) | `/app/.env.remote-ops` | **키 파일 — 마지막에 올림**. 웹에서 접근 불가 위치 |
| (선택) | `database/20261103_remote_ops.sql` | `/database/20261103_remote_ops.sql` | 보관용(자동 실행 안 됨) |

- 올리는 순서: 1~11 → 12. 12번이 없으면 API 는 `disabled` 로만 답한다(안전한 기본값).
- 전송 모드는 이진(Binary) 권장. 올린 뒤 FileZilla 에서 크기가 로컬과 같은지 확인.
- 되돌리기(설치 취소): `/app/.env.remote-ops` 삭제(또는 `REMOTE_OPS_ENABLED=0`) → 즉시 전체 중지. 완전 제거는 1~11 삭제 + (선택) 테이블 DROP(마이그레이션 파일 상단 주석).

## 4. 운영 연동 테스트 절차 (설치 직후, Claude 가 실행)

각 단계가 통과해야 다음으로 간다. 5단계부터는 운영 변경이므로 **승인 화면에서 승인한 테스트 범위만** 쓴다.

| 단계 | 명령 | 기대 결과 | 실패 시 |
|---|---|---|---|
| 1. 연결·인증 | `scripts/tomok probe` | `ok:true`, `db.connected:true`, `db.ops_tables` 4개, `php.extensions.aes_256_gcm:true` | `disabled`→12번 파일 확인 / `bad_signature`→두 키 값이 다름 / `expired`→`clock_skew_sec` 확인 / `JSON 이 아닌 응답 404`→10번 파일 위치 |
| 2. 서버 환경 | 같은 결과의 `php`·`paths`·`db.privileges` | PHP 8.4, `open_basedir`, `disable_functions`, `post_max_size`, 각 루트 `writable`, DB 권한(CREATE·ALTER 여부) 기록 | 결과를 그대로 보고 |
| 3. 쓰기 가능 범위 | `scripts/tomok probe --write-test` | `paths[].write_test` 의 create·read·rename·delete 모두 `true`(세 루트) — 임시 파일만 만들었다 바로 지움 | `false` 인 루트는 파일 배포 불가 → §9 대안 |
| 4. 조회 | `scripts/tomok db tables` · `scripts/tomok db search products 투수블록 --limit 5` · `scripts/tomok files manifest --roots public,app` | 테이블 목록, 검색 결과, 서버 파일 ↔ Git 비교(same/eol_only/differs/missing/extra 개수) | manifest 의 `differs` 는 서버가 Git 과 다른 파일 → 보고만(바꾸지 않음) |
| 5. 승인 흐름(무해) | `scripts/tomok db request --title "[테스트] 승인 흐름 확인" --tables categories --actions update --columns categories.sort_order --ids categories:<한 개 id> --max-rows 1 --ttl 15` → 승인 화면에서 **승인** | 승인 화면에 요청·대상 행 표시, `scripts/tomok grant wait <코드>` → approved | 로그인·화면 오류 보고 |
| 6. DB 쓰기·되돌리기 | 같은 승인으로 `db update … --set sort_order=<현재값> --dry-run` → (원하면) 실제 값 바꿈 → `db verify` → `db revert` | 드라이런 diff, verify `matches:true`, revert 후 원래 값 | 자동 롤백됨, 결과 보고 |
| 7. 파일 배포·복구(무해) | 테스트용 새 파일(예: `public/ops-check.txt`)을 커밋 → `files stage` → 승인 → `files deploy --check /ops-check.txt` → `files restore --operation <id>` | 배포 200 → 복구 후 404, 서버에 흔적 없음 | 자동 원복 결과 보고 |
| 8. 이력 | `scripts/tomok log list --limit 20` + 승인 화면 '최근 실행 이력' | 1~7 단계가 모두 기록, 키·비밀번호 없음 | |

> 7단계의 테스트 파일은 테스트 뒤 Git 에서도 지운다(커밋 1개 추가·되돌림). 원하지 않으면 7단계는 실제 첫 배포 때 함께 확인한다.

## 5. 사용 예시

### 5-1. "운영 DB에서 대성산업 투수블록을 검색하고 기업회원 가격을 수정한 뒤 실제 변경 결과를 확인해 줘"

```bash
scripts/tomok db search products 대성산업 투수블록
```
→ 상품 id·이름·`price_b2c`(판매가)·`price_b2b`·`cost_price`(매입가)·카테고리 마진율·업체명.
**확인 필요**: 이 사이트의 '기업회원가'는 `app/pricing.php` 가 `cost_price × (100 + categories.discount_corporate)/100` 으로 계산해 보여 준다. `price_b2b` 컬럼을 바꿀지, 매입가·카테고리 마진율을 바꿀지, 새 값이 얼마인지 Claude 가 먼저 확인한다(추측해서 바꾸지 않음).

```bash
scripts/tomok db request --title "대성산업 투수블록 3개 price_b2b 변경" --tables products --actions update --columns products.price_b2b --ids products:1001,1002,1003 --max-rows 3 --ttl 30
```
→ `approve_url` 을 사용자에게 안내 → 사용자가 관리자 화면에서 대상 행 표를 보고 **승인**.

```bash
scripts/tomok grant wait G261103-XXXXX
```
```bash
scripts/tomok db batch --grant G261103-XXXXX --file changes.json --dry-run
```
```bash
scripts/tomok db batch --grant G261103-XXXXX --file changes.json
```
`changes.json` 예: `{"table":"products","rows":[{"key":{"id":1001},"set":{"price_b2b":10500},"expect":{"price_b2b":10000}}]}` — `expect` 는 조회 때 값. 그 사이 누가 바꿨으면 전체 롤백.

```bash
scripts/tomok db verify --operation 123
```
```bash
scripts/tomok db query --sql "SELECT id, name, price_b2b FROM products WHERE id IN (1001,1002,1003)"
```
잘못 바꿨으면(승인 유효시간 + 24시간 안):
```bash
scripts/tomok db revert --operation 123
```

### 5-2. "수정한 PHP 파일을 운영 서버에 반영해 줘"

```bash
scripts/tomok files diff app/pricing.php public/product.php
```
→ 서버 파일 ↔ 커밋 비교(줄바꿈만 다르면 `eol_only`). 서버에 Git 에 없는 수정이 있으면(`differs` 인데 예상 밖) 멈추고 보고.

```bash
scripts/tomok files stage app/pricing.php public/product.php --title "상품 상세 가격 표시 수정" --detail "커밋 abc1234 — 무엇을 왜"
```
→ 승인 화면에 **서버가 계산한 diff** 가 보인다 → 사용자 승인.

```bash
scripts/tomok grant wait G261103-YYYYY
```
```bash
scripts/tomok files deploy --grant G261103-YYYYY --check / --check "/product.php?id=1001"
```
→ 백업 → 원자적 교체 → 해시·문법 재확인 → HTTP 확인. 실패 항목이 있으면 `rollback_hint` 로 바로:
```bash
scripts/tomok files restore --operation 456
```

### 5-3. SQL 이관 파일

```bash
scripts/tomok db stage-sql --file database/20261110_example.sql --title "예시 이관"
```
→ DML 은 롤백 트랜잭션으로 미리 실행한 영향 행 수가 승인 화면에 표시. DDL 이 있으면 '추가 확인'을 체크해야 승인되고, 실행 직전 대상 테이블이 자동 백업된다.
```bash
scripts/tomok db execute --grant G261103-ZZZZZ
```
파일 안에 `-- @verify SELECT …` 줄을 넣으면 실행 전후 같은 조회를 비교해 준다.

## 6. 명령어 모음

`scripts/tomok help` 에 전체가 있다. 모든 결과는 JSON(`ok`, `op`, `op_id`, `result` 또는 `error`). 종료 코드 0 성공 · 1 작업 오류 · 2 사용법 · 3 연결·인증.

| 분류 | 명령 |
|---|---|
| 진단 | `probe [--write-test]` |
| DB 조회 | `db tables [--exact]` · `db describe <t>` · `db query --sql/--file [--param v] [--limit N] [--reveal-pii]` · `db search products|categories <단어…>` |
| DB 승인 | `db request …` · `db stage-sql --file` · `db stage-restore --snapshot` |
| DB 쓰기 | `db update` · `db batch` · `db insert` · `db delete` · `db execute` (모두 `--grant`, `--dry-run`) |
| DB 확인·복구 | `db verify --operation` · `db revert --operation [--force]` · `db backup --tables` |
| 파일 조회 | `files list [경로] [--recursive] [--hashes]` · `files read <경로> [--out]` · `files hash` · `files diff` · `files manifest` · `files lint` · `files snapshot <id>` |
| 파일 배포 | `files stage <경로…> --title [--delete …]` · `files deploy --grant [--check /경로]` · `files restore --operation` |
| 승인·이력 | `grant status|wait|list|cancel` · `log list|get` · `http <경로…>` |

## 7. 끄기·키 교체·회수

| 하고 싶은 것 | 방법 |
|---|---|
| 모든 원격 쓰기 즉시 중지 | 승인 화면 **'전체 회수'** |
| API 전체 중지(조회 포함) | FileZilla 로 `/app/.env.remote-ops` 의 `REMOTE_OPS_ENABLED=0` 으로 바꾸거나 파일 삭제 |
| 키 교체 | 3-1 다시 실행(새 `REMOTE_OPS_KEY_ID=k2` 권장) → `/app/.env.remote-ops` 교체 → Claude Code 환경변수 `TOMOK_OPS_KEY`·`TOMOK_OPS_KEY_ID` 교체 → 새 세션 |
| 조회만 허용 | `REMOTE_OPS_SCOPES=probe,db.read,files.read` 추가 |
| 키 유출 의심 | 즉시 `REMOTE_OPS_ENABLED=0` → 승인 화면 '최근 실행 이력' 확인 → 키 교체 |

## 8. 로컬 검증 결과 (2026-11-03, 운영 접속 없음)

| 검사 | 명령 | 결과 |
|---|---|---|
| PHP 문법 | `find public app scripts/remote-ops -name "*.php" -print0 \| xargs -0 -n1 php -l` | 오류 0 (PHP 8.3.6, 새 파일은 PHP 8.4.27 컨테이너에서도) |
| 기존 단위 테스트 | `php tests/functions.test.php` | 210 통과 / 0 실패 |
| 새 단위 테스트 | `php tests/remote_ops.test.php` | 130 통과 / 0 실패 (8.3.6·8.4.27, 8.4 에서 Deprecated 경고 없음) |
| 통합 테스트 | `bash scripts/remote-ops/test-local.sh` (Docker MySQL 8.0 + 사이트 사본 + PHP 내장 서버, `[TEST]` 가상 데이터) | 132 통과 / 0 실패 — 서버 PHP 8.3.6, 그리고 `ROPS_SERVER_IMAGE`(php:8.4-cli + pdo_mysql)로 8.4.27 에서도 132/132 |
| 승인 화면 | Playwright 1280·820·375px | 가로 스크롤 없음, 버튼·입력 44px. 375px 에서는 이 화면만 메뉴를 위쪽 가로 메뉴로 바꿈 |

통합 테스트 주요 항목: 인증 실패(다른 키·키 ID·시각 1시간 전·암호문 변조·GET·평문 요청), **같은 요청 재전송 거부**, 범위 제한 키, 사용 중지 설정 / probe 쓰기 시험 / 조회 SQL 의 UPDATE·인증정보 컬럼·잘못된 SQL 거부, 개인정보 가림 / 승인 전 쓰기 거부, CSRF 틀린 승인 거부, 범위 밖 컬럼·행·테이블 거부, 드라이런, 전후 diff, verify, **일괄 수정 중 예상값 불일치 시 전체 롤백**, 누적 행 수 초과, 되돌리기 충돌·성공·중복 방지, CASCADE 삭제 거부, 관리 테이블 쓰기 거부, 회수·만료 / **SQL 파일 2번 문장 실패 시 1번까지 롤백**, 승인 때보다 영향 행이 많으면 롤백, DDL 추가 확인·자동 백업·백업 복원 / 경로 차단(`.env`·`..`·절대경로·`.git`·업로드 폴더), 심볼릭 링크 차단 / 배포(교체·새 파일·삭제)·HTTP 확인·복구, **요청 뒤 서버 파일이 바뀌면 승인·배포 중단**, **배포 중간 실패 시 앞 파일 자동 원복**, 서버 측 PHP 문법 검사, 이력에 키·비밀번호·본문 없음.

실행 방법(Docker 필요, 운영 접속 없음):
```bash
bash scripts/remote-ops/test-local.sh
```

## 9. 남은 호스팅 제약과 대안

| 제약 | 영향 | 대안 |
|---|---|---|
| 운영 PHP 의 실제 쓰기 권한·`open_basedir`·`disable_functions` 미확인 | 파일 배포 가능 여부 미정 | 설치 후 4장 3단계. 쓰기 불가 루트가 있으면 그 경로만 기존 FileZilla/`deploy-files.sh` 유지(DB 기능은 영향 없음 — 백업도 DB 에 저장) |
| `post_max_size`·`max_execution_time`·`memory_limit` 미확인 | 큰 파일·큰 테이블 백업 실패 가능 | probe 값 확인. 파일은 4MB 기본 제한, 큰 이미지·대량 데이터는 FileZilla·phpMyAdmin |
| 닷홈 웹 방화벽(요청 본문 검사) 가능성 | SQL·PHP 코드가 든 요청이 막힐 수 있음 | 본문이 암호화(base64)되어 키워드가 보이지 않음. 설치 후 4장에서 실제 확인 |
| 무료 호스팅 DB 용량 | 백업·이력이 쌓이면 용량 사용 | 정기 정리(아래 SQL, 승인 후), `scripts/tomok db tables` 로 `remote_ops_snapshots` 크기 확인 |
| opcache 설정 | 교체 직후 옛 코드가 잠깐 보일 수 있음 | 교체 시 `opcache_invalidate` 호출, probe 의 `opcache_validate_timestamps` 확인 |
| Claude 출구 프록시가 TLS 를 다시 맺음 | 프록시 구간에서 HTTPS 만으로는 내용 보호 부족 | 본문 AES-256-GCM 이중 암호화 + 응답 서명 검증. 브라우저로 `https://` 인증서 경고 없는지 사용자 확인 권장 |
| 사이트 기본 주소가 HTTP(`config.php` base_url) | 관리자 로그인 정보가 평문 전송될 수 있음 | 승인 화면은 **`https://`** 주소로 접속(승인 URL 은 https 로 안내) |
| 관리 API 와 사이트가 같은 DB 계정 사용 | 계정 권한 이상은 막을 수 없음 | 무료 호스팅은 계정 추가가 어려움. 서버 코드에서 범위·승인·관리 테이블 보호로 통제 |
| 승인에 관리자 로그인이 필요 | 채팅 승인보다 한 단계 많음 | 의도된 설계(승인이 '약속'이 아니라 서버에서 강제됨). 채팅만으로 승인하는 방식이 필요하면 별도 검토(보안 약화) |
| 클라우드 세션은 매번 새 컨테이너 | 로컬에 남는 것 없음 | 이력·백업은 모두 서버 관리 테이블에. 키는 환경변수로 매 세션 자동 주입 |

백업·이력 정리 예(운영 DB 데이터 삭제 — 승인 후 phpMyAdmin 에서, 먼저 `SELECT COUNT(*)` 로 확인):
```sql
DELETE FROM remote_ops_snapshots WHERE created_at < NOW() - INTERVAL 90 DAY;
DELETE FROM remote_ops_nonces WHERE expires_at < NOW();
```

## 10. 규칙 문서 반영 제안 (아직 바꾸지 않음)

설치·연동 테스트가 끝나고 사용자가 원하면 다음을 별도 작업으로 반영한다.
- `CLAUDE.md` 6·7·8장, `docs/DEPLOYMENT.md` 5·6장: "관리자 화면에서 승인한 원격 관리 API 승인(grant)도 해당 작업 1회의 명시적 승인으로 본다", "운영 DB 변경은 phpMyAdmin 직접 실행 또는 원격 관리 API(승인 범위 안)", "운영 파일 반영은 FileZilla/`deploy-files.sh` 또는 `tomok files deploy`"
- 관리자 메뉴(`app/admin_layout.php`)에 '원격 작업 승인' 링크 추가(지금은 URL 로 접속)
- 다른 관리자 화면도 휴대폰에서 메뉴가 화면을 가리는 문제(220px 고정)가 있음 — 공통 레이아웃 반응형 개선 별도 제안
