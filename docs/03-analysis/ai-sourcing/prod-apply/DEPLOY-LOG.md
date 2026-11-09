# 운영 반영 기록

| 일시 | 단계 | 내용 | 기준 → 배포 커밋 | 확인 | 상태 |
|---|---|---|---|---|---|
| 2026-11-02 | 1-① 코드 | AI 상담 5개 업로드(`app/rag.php` `app/ai_tools.php` `app/external_products.php` `app/chat_renderer.php` `public/assets/chat-ai.js`) — `scripts/deploy-files.sh` check → upload | `02a98c2` → 작업 브랜치 `b5e9602`(사용자 PC `git log -1` 로 확인 필요) | 사용자 보고 "배포했어". 백업 폴더·운영 화면 확인 결과는 사용자 기록 대기 | **적용** |
| 2026-11-02 | 1-② DB | 제조사 승인 자료 14행 — `10_create_tables` → `20_insert_approved` → `30_post_check` | export-report 지문 `ee65a7ee…fd64e` | 사용자 보고: 지문 일치 · 매칭 0 · 참고가 0, 운영 AI 상담에서 가야스틸 제조사 출처 카드 표시 확인 | **적용** |
| 2026-11-02 | 1-③ DB | 법령 근거 칸 추가(마이그레이션) → 66행 비활성 등록 → 검증 20건 활성화(V01–V03·V05–V19·V24·V27, V21·V22·V25·V26 제외) | `database/20261102_rag_documents_legal_columns.sql`, `prod-apply/10`, `backup/legal-stage1/20_activate_ready.sql`(검토자 실명) | 사용자 보고: 칸 추가·66행 등록·20건 활성화 SQL 실행 완료. **법령 출처 카드·공공도로 V21·V22 비활성 운영 상담 확인 예정** | **적용**(확인 진행 중) |
| 2026-11-03 | Git | main 병합 `3d0bca1`(작업 브랜치 52커밋, 병합 전 테스트 23종·시크릿 검사 통과) — 운영 1단계 코드가 main 에 포함 | `b81f93a` → `3d0bca1` | — | **완료** |
| 2026-11-05 | Git | main 병합 `21c0f6b`(작업 브랜치 `claude/sharp-lamport-ykk35j` 20커밋 — 법령 근거 V25·V26·V28~V43·V08 별표 7, KS 적용 범위 19건, 공공조달 등록 현황, KCSC 조회 도구, 상품 설명 정비안). 병합 전 origin/main `d203d5a`(홈 작업 v5·배수관 주의 안내)를 작업 브랜치에 먼저 병합해 시험 30종 통과(`stock_filter` 는 빈 DB 전용이라 제외)·시크릿 검사 통과. **운영 배포·운영 DB 변경 없음**(사용자 지시) | `d203d5a` → `21c0f6b` | — | **완료** |
| 2026-11-03 | 2 코드 | 고객 화면 오류 문구 19 + 화면 3 — [STAGE2-RUNBOOK.md](STAGE2-RUNBOOK.md) | `02a98c2` → main `94bde9a` | 사용자 보고 "2단계 배포했어". 운영 확인(이 환경, 읽기·실패 입력만): 스모크 7/7, 정적 파일 3개 = main, 페이지 6곳 새 코드 표시, API 오류 문구 200+ok:false(로그인·회원가입·장바구니 1000·분류·견적·주문), 비로그인 401·405 는 설계대로, 360/768/1280px 7페이지 가로 스크롤·JS 오류 없음, 로그인 틀린 비밀번호 문구 화면 표시 | **적용** |
| 2026-11-03 | 상담원 문의 DB | 새 테이블 2개 `consult_requests`·`consult_request_events` — `database/20261103_consult_requests.sql` [1]→[2]→[3] ([consult-request-RUNBOOK.md](../../consult-request-RUNBOOK.md) 1장) | 작업 브랜치 `00324b9` 의 SQL | 사용자 보고 "신규 테이블 2개 생성". [3] 결과값(`consult_tables_exist = 2`)은 사용자 확인 기록 대기 | **적용** |
| — | 상담원 연결 유도(카카오톡, 디자인만) 코드 | 3개(새 1 + 수정 2, 기준 `a1f0a6e`) — [consult-request-RUNBOOK.md](../../consult-request-RUNBOOK.md). 11-04 문자 발송 제거, 관리자 '상담 요청' 화면 제거(코드 `_archive/consult-request/` 보관). 11-03 테이블 2개는 빈 채로 남음 | — | — | 승인 대기 |
| — | 3 코드 | 관리자·직원 14 | — | — | 미승인 |

- **1단계(AI 상담 코드 5개 + 제조사 자료 14행 + 법령 근거 20건) 운영 반영 완료 — 2026-11-02.** 운영 AI 상담이 승인 제조사 자료·검증 법령 근거·국가표준 번호(기본 정보)를 사용한다.
- 운영 확인 남은 것: 아래 '1-③ 운영 확인' 질문·읽기 전용 SQL.
- 2026-11-03 main 병합으로 운영 1단계 코드가 main 에 포함됨. 이후 배포는 main 에서.
- 되돌리기: `scripts/deploy-files.sh rollback backup/<1-① 백업 폴더>`.
- **2단계(고객 화면 22개) 운영 반영 완료 — 2026-11-03.** 남은 운영 확인(로그인 필요, 사용자): 테스트 계정 정상 로그인·관리자 /admin.php 이동, 기업회원 마이페이지 즐겨찾기·현장·재주문. 되돌리기: `scripts/deploy-files.sh rollback backup/<2단계 백업 폴더>`.
- 참고(2단계와 무관, 기존): `/assets/fonts/Paperlogy-*.woff2` 404 → `fonts.css` 가 jsdelivr CDN 으로 대체해 서체는 표시됨. 글꼴 7개를 서버 `/html/assets/fonts/` 에 올리면 404 가 사라짐(선택, `public/assets/fonts/README.md`).

## 1-③ 운영 확인 (사용자 — 읽기·화면)

```sql
SELECT COUNT(*) AS verified_active FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND is_active = 1;                          -- 20
SELECT COUNT(*) AS public_road_active FROM rag_documents WHERE tags LIKE '@scope:public_road%' AND is_active = 1;                       -- 0 (V21·V22 비활성)
SELECT reviewer, reviewed_at, COUNT(*) AS n FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND is_active = 1 GROUP BY reviewer, reviewed_at;   -- 1행 n=20
```

| 운영 AI 상담 질문 | 기대 |
|---|---|
| 가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요? | '공식 법령 근거' 카드 — 보도 설치 및 관리 지침, 80mm |
| 아파트 단지 주차장 블록 80T면 되나요? | 저희 권장 80T, 주차장법 카드가 붙을 수 있음, 공공도로 기준 없음 |
| 시청에서 발주한 도로 공사인데 차도에 블록 깔려면 두께 몇 mm 써야 해요? | KDS/KCS 44 50 25·100mm 단정 없이 설계도서·시방서 두께 확인 안내(운영 V21·V22 비활성) |
| 벤치플룸관이 뭐예요? | 제조사 자료 없음 + 'KS F 4010(철근 콘크리트 플룸 및 벤치 플룸)' + 토목몰 등록 규격, '확인된 기본 정보' 카드 |
