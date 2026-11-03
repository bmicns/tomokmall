# 운영 반영 실행표 — 상담원 문의 요청 · 관리자 상담 요청 · 문자 답변

> 상태: **준비 완료, 실행 전**(각 단계 사용자 승인 후 사용자 PC 에서 실행). 기능 설명: [consult-request-2026-11-03.md](consult-request-2026-11-03.md)
> 이 클라우드 환경은 FTP(21)·DB(3306) 접속이 막혀 있어 업로드·DB 반영은 사용자가 한다([DEPLOYMENT.md](../DEPLOYMENT.md) 6장).

## 0. 시작 전 (사용자)

| 확인 | 내용 |
|---|---|
| main 병합 | 작업 브랜치 `claude/peaceful-feynman-wnhr78` → main (승인 후) — 배포는 main 에서 |
| 문자 업체 | 솔라피 또는 알리고 가입, **발신번호 사전 등록 완료**(등록 전엔 발송 실패). 알리고는 발송 서버 IP(닷홈) 등록 |
| 안내 문구 | 문의 모달의 '개인정보 수집 업체'(현재 `(주)비엠아이씨앤애스`)가 정확한 법인명인지 |

## 1. DB (phpMyAdmin, 코드보다 먼저)

`database/20261103_consult_requests.sql` 를 열어 순서대로:

1. **[1] 사전 확인** — `consult_tables_exist` = `0` 이어야 함(2 면 이미 있음 → [2] 생략)
2. **[2] 생성** — `consult_requests`, `consult_request_events` 새 테이블 2개. 기존 테이블·데이터는 바뀌지 않으므로 별도 백업 대상 없음(평소 정기 백업만)
3. **[3] 확인** — `consult_tables_exist` = `2`

로컬 MySQL 8.0 에서 [1]~[3] 실행·재실행(멱등) 확인함. 되돌리기는 파일의 [4](쌓인 문의가 지워지므로 백업 후, 별도 승인).

## 2. 문자 설정 (`/app/.env`)

FileZilla 로 서버 `/app/.env` 를 내려받아 **기존 줄은 그대로 두고** 아래를 추가한 뒤 다시 올린다(값은 채팅·커밋 금지). 배포 스크립트는 `.env` 를 건드리지 않는다.

```dotenv
SMS_PROVIDER=solapi
SMS_API_KEY=
SMS_API_SECRET=
SMS_SENDER=
SMS_SENDER_NAME=비엠아이토목건축자재
```

알리고면 `SMS_PROVIDER=aligo`, `SMS_API_SECRET` 대신 `SMS_USER_ID=`(알리고 아이디). 첫 시험만 실제 발송 없이 하려면 `SMS_TEST_MODE=Y`(알리고 전용) — 시험 뒤 지운다.
설정 전에도 화면은 동작하고, 관리자 상세에 '문자 발송 설정이 아직 없습니다' 안내가 나온다.

## 3. 코드 (사용자 PC, Git Bash)

10개 파일 = 새 파일 7 + 수정 3. 운영 서버의 수정 3개(`chat_renderer.php`·`chat-ai.js` = 1단계 `b5e9602`, `admin_layout.php` = `02a98c2`)는 모두 `a1f0a6e` 와 같으므로 비교 기준은 **`a1f0a6e`**.
새 파일을 먼저, 그것을 부르는 화면(AI 상담·관리자 메뉴)을 나중에 올리도록 순서를 맞췄다.
`scripts/deploy-files.sh` 는 이번에 **새 파일 지원**을 추가했다(check: 서버에 없음 확인 · upload: 폴더 생성 · rollback: 새 파일 삭제) — 로컬 FTP 서버로 check·upload·rollback·비밀번호 오류·'이미 있음' 중단을 시험함.

```bash
git checkout main
```

```bash
git pull origin main
```

```bash
scripts/deploy-files.sh check a1f0a6e app/consult.php app/sms.php public/api/consult/request.php public/api/admin/consults.php public/admin-consults.php public/admin-consult.php public/assets/consult-request.js public/assets/chat-ai.js app/chat_renderer.php app/admin_layout.php
```

- 기대: 문법·단위 테스트 통과 → 수정 3개 백업 + **'동일'**, 새 파일 7개 **'서버에 없음 확인'** → `check 통과`.
- '다름' 또는 '새 파일인데 서버에 이미 있음' 이 나오면 멈추고 결과 공유.

```bash
scripts/deploy-files.sh upload backup/<check 가 알려 준 폴더>
```

```bash
bash scripts/smoke.sh
```

## 4. 배포 후 운영 확인

| 확인 | 기대 |
|---|---|
| 비로그인 AI 상담에서 상품 추천 | 추천표만, 상담원 연결 유도 **없음** |
| 개인·기업회원 로그인 후 상품 추천 | 추천표 아래 '어떤 제품이 맞는지 확신이 서지 않으세요?' — 추천될 때마다 |
| '상담원 문의 요청' | 모달, 개인정보 안내 **접힘** → '자세히 보기'로 펼침, 이름 자동 입력 |
| 본인 번호로 접수 | '문의가 접수됐어요' |
| 관리자 › 운영 › 상담 요청 | 목록에 접수 1건(접수됨), 검색·탭 |
| 상세 | AI 상담 내역 전체·추천표·'상담원 문의 요청' 시점 |
| 문자 보내기(본인 번호) | 휴대폰 수신, 상태 '발송됨', 처리 이력에 문자 내용 |
| 실패 시 | 처리 이력 '문자 발송 실패' + 사유 → 사유 공유(발신번호 미등록·IP 미등록·키 오류 등) |
| 완료 처리 | 확인 → 이름·연락처 '파기됨', 회원 정보는 그대로 |

## 5. 되돌리기

- 코드: `scripts/deploy-files.sh rollback backup/<폴더>` — 수정 3개는 백업으로 복구, 새 파일 7개는 서버에서 삭제(빈 `/html/api/consult/` 폴더만 남음).
- DB: 코드만 되돌리면 새 테이블은 쓰이지 않을 뿐 영향 없음. 삭제가 꼭 필요할 때만 [4].
- `/app/.env` 의 SMS 줄은 남아 있어도 영향 없음.

## 6. 기록

배포 후 [DEPLOY-LOG.md](ai-sourcing/prod-apply/DEPLOY-LOG.md) 에 한 줄(DB·설정·코드 각각, 배포 커밋, 확인 결과).
