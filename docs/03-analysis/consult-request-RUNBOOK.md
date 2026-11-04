# 운영 반영 실행표 — 추천 시점 상담원 연결 유도(카카오톡 채널, 디자인만)

> 상태: **준비 완료, 승인 대기**(업로드는 사용자 승인 후 사용자 PC 에서). 기능 설명: [consult-request-2026-11-03.md](consult-request-2026-11-03.md)
> 경과: 11-03 상담원 문의·문자 답변 개발 → 11-04 문자 발송 제거, 카카오톡 채널 디자인으로 교체, **관리자 '상담 요청' 화면 제거(코드는 `_archive/consult-request/` 보관, 배포 안 함)**.
> DB: `database/20261103_consult_requests.sql` 은 11-03 운영 적용됨 — 지금은 쓰는 코드가 없는 빈 테이블(영향 없음, 이번 배포와 무관).
> 이 클라우드 환경은 FTP(21)·DB(3306) 접속이 막혀 있어 업로드는 사용자가 한다([DEPLOYMENT.md](../DEPLOYMENT.md) 6장).

## 1. 무엇이 바뀌나 (3개 = 새 파일 1 + 수정 2)

| 파일 | 내용 |
|---|---|
| `public/assets/consult-request.js` (새) | 추천표 아래 '어떤 제품이 맞는지 확신이 서지 않으세요?' + `카카오톡 채널 1:1 상담하기` 버튼 + '상담 가능 시간 평일 09:00 ~ 18:00' — **버튼 동작 없음** |
| `public/assets/chat-ai.js` | 상품 추천표(`product_cards`)를 그린 뒤 위 블록을 붙이는 4줄(스크립트가 없으면 아무것도 안 함) |
| `app/chat_renderer.php` | 블록 스타일 + **로그인한 개인·기업회원에게만** 위 스크립트를 넣음(비로그인·직원·관리자 없음) |

- 관리자 메뉴는 그대로(운영: 회원관리·주문관리·견적관리). `app/admin_layout.php` 는 바뀌지 않음.
- 서버의 `chat_renderer.php`·`chat-ai.js` = 1단계 `b5e9602` = `a1f0a6e` → 비교 기준 **`a1f0a6e`**. 새 파일을 먼저 올린다.
- `scripts/deploy-files.sh` 새 파일 지원(check: 서버에 없음 확인 · upload: 폴더 생성 · rollback: 새 파일 삭제) — 로컬 FTP 서버로 시험함.

## 2. 명령 (사용자 PC, Git Bash — 업로드는 승인 후)

```bash
git checkout main
```

```bash
git pull origin main
```

```bash
scripts/deploy-files.sh check a1f0a6e public/assets/consult-request.js public/assets/chat-ai.js app/chat_renderer.php
```

- 기대: 문법·단위 테스트 통과 → 새 파일 1개 **'서버에 없음 확인'**, 수정 2개 백업 + **'동일'** → `check 통과`. '다름'·'이미 있음'이면 멈추고 결과 공유.

```bash
scripts/deploy-files.sh upload backup/<check 가 알려 준 폴더>
```

```bash
bash scripts/smoke.sh
```

## 3. 배포 후 운영 확인

| 확인 | 기대 |
|---|---|
| 비로그인 AI 상담에서 상품 추천 | 추천표만, 연결 유도 **없음** |
| 개인·기업회원 로그인 후 상품 추천 | 추천표 아래 카카오톡 채널 블록 — 추천될 때마다 |
| `카카오톡 채널 1:1 상담하기` 클릭 | 아무 변화 없음(기능 전) |
| 휴대폰 | 가로 스크롤 없음 |
| 관리자 메뉴 | 운영: 회원관리·주문관리·견적관리 그대로 |

## 4. 되돌리기

`scripts/deploy-files.sh rollback backup/<폴더>` — 수정 2개는 백업으로 복구, 새 파일 1개는 서버에서 삭제.

## 5. 기록

배포 후 [DEPLOY-LOG.md](ai-sourcing/prod-apply/DEPLOY-LOG.md) 에 한 줄(배포 커밋, 확인 결과).
