# 운영 반영 1단계 실행표 — AI 상담 코드 5개 + 제조사 자료 14행 + 법령 근거 20건

> 승인: 사용자 2026-11-02 "운영 반영 1단계 진행"(리허설 실제 14행 PASS 192 · FAIL 0 확인 후). 범위는 아래 1-①·1-②·1-③ 만. main 병합·2·3단계 배포는 별도 승인.
> 실행: 사용자 PC(Git Bash)·phpMyAdmin. 기준 커밋: 작업 브랜치 `claude/blissful-turing-8e4xrr` 최신(`git log -1` 해시를 기록 칸에 적음).
> **각 단계의 '기대'와 다르면 다음 단계로 가지 말고 멈춘 뒤 결과를 공유.** 되돌리기는 단계별로 독립.

## 1-① AI 상담 코드 5개 배포

```bash
git checkout claude/blissful-turing-8e4xrr
```

```bash
git pull origin claude/blissful-turing-8e4xrr
```

```bash
scripts/deploy-files.sh check 02a98c2 app/rag.php app/ai_tools.php app/external_products.php app/chat_renderer.php public/assets/chat-ai.js
```

- 기대: 문법·단위 테스트 통과 → 서버 파일 5개 백업 → **5개 모두 '서버 = 02a98c2'(또는 '줄바꿈만 다름')** → 마지막 줄에 업로드 명령(`backup/<폴더>`). 하나라도 '다름'이면 멈춤.

```bash
scripts/deploy-files.sh upload backup/<check 가 알려 준 폴더>
```

- `배포` 입력 → 업로드 → 다시 내려받아 5개 일치. 실패 안내가 나오면 그 `rollback` 명령을 바로 실행.

```bash
bash scripts/smoke.sh
```

- 운영 화면 확인(DB 반영 전이라 제조사 출처·법령 카드는 아직 없음이 정상):

| 질문(AI 상담) | 기대 |
|---|---|
| 집 앞에 보도블록을 깔려고 하는데 차가 지나가도 괜찮나요? → 집 앞 마당이고 승용차만 다녀요. 가로 4m 세로 5m → 그중에 제일 저렴한 걸로 견적에 담아 주세요 | 80mm 저희 권장 기준 → 4종 비교표(필요 수량·예상 자재비) → 담기 + '같은 예상 자재비의 …도 있습니다' |
| 벤치플룸관이 뭐예요? | 제조사 자료 없음 + 토목몰 등록 규격(200*150*2000*35T 등), 용도·구조 지어내지 않음 |

되돌리기: `scripts/deploy-files.sh rollback backup/<같은 폴더>`(`복구` 입력).

## 1-② 제조사 자료 14행 (phpMyAdmin → 운영 DB → SQL 탭)

0) 사전 점검 [읽기]

```sql
SELECT VERSION();
SELECT FIND_IN_SET('NO_BACKSLASH_ESCAPES', @@SESSION.sql_mode) AS nbe;
SHOW TABLES LIKE 'external_product%';
```

- 기대: 8.0.x · nbe 0 · 0행(테이블 없음). nbe 가 1 이면 멈춤.

1) `data/external/crawl/export/10_create_tables.sql` 파일 내용 실행 → 테이블 2개 생성
2) `data/external/crawl/export/20_insert_approved.sql` 실행 → 14행 추가
3) `data/external/crawl/export/30_post_check_readonly.sql` 실행 [읽기] → 기대:

| 항목 | 기대 |
|---|---|
| approved_rows | 14 |
| 출처별 | dyhumepipe 1 · gayasteel 5 · sunroad 8 |
| fingerprint | `ee65a7eee2c4b4b3cd23d0537cbc1c6f9f45e96ad321e6f046d61fe38c7fd64e` |
| matches · priced | 0 · 0 |

- 운영 화면 확인: '스틸 그레이팅이랑 중하중 그레이팅은 뭐가 달라요?' → (주)가야스틸 자료 + 출처 카드, '보도블록 중에 오염이 덜 타는 제품도 있어요?' → 선로드, '흄관은 무슨 KS 규격 제품을 쓰면 돼요?' → 동양콘크리트산업 KS F 4403 표기.
- 되돌리기: `90_rollback.sql`(먼저 SELECT 로 14 확인 → DELETE). 테이블까지 지울 때만 그 파일 끝의 DROP 2줄.

## 1-③ 법령 근거 (phpMyAdmin)

0) **백업**: phpMyAdmin → `rag_documents` → 내보내기(SQL) → 파일 보관(필수).

1) 사전 점검 [읽기]

```sql
SELECT COUNT(*) AS docs, SUM(is_active) AS active FROM rag_documents;
SELECT COUNT(*) AS cols FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rag_documents';
```

- 기대: docs·active 값을 적어 둠(저장소 기준 5 · 5) · cols 9.

2) `database/20261102_rag_documents_legal_columns.sql` 실행 → 칸 25개·색인 2개
3) `docs/03-analysis/ai-sourcing/prod-apply/10_register_inactive.sql` 실행 → 66행 추가, 전부 비활성
4) `docs/03-analysis/ai-sourcing/prod-apply/30_post_check_readonly.sql` 실행 [읽기] → 각 줄 주석의 기대값(아직 활성화 전이라 verified_active 0, 검토자 줄 0행). 이 시점 상담 변화 없음이 정상.
5) 활성화 SQL 만들기(사용자 PC — 검토자 이름·날짜를 실제 값으로, 저장소 밖 `backup/` 에 생성):

```bash
php scripts/legal-sources/build_prod_sql.php --reviewer='검토자 실명' --reviewed-on=2026-11-02 --out=backup/legal-stage1
```

- `backup/legal-stage1/20_activate_ready.sql` 을 열어 확인: 활성화 20건(V01–V03, V05–V19, V24, V27), **V21·V22·V25·V26 없음** → phpMyAdmin 에서 실행(20행 변경).

6) `30_post_check_readonly.sql` 다시 실행 [읽기] → 기대: verified_active 20 · v21_v22_active 0 · public_road_active 0 · 검토자 줄 1행 n=20 · internal = 1)에서 적은 값.

- 운영 화면 확인:

| 질문 | 기대 |
|---|---|
| 가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요? | 보도 설치 및 관리 지침 법령 카드 + 80mm |
| 집 앞 마당에 차 두 대 주차할 건데 블록 두께 몇 T? | 80T 저희 권장 기준, 공공도로 기준 없음 |
| 시청에서 발주한 도로 공사인데 차도에 블록 깔려면 두께 몇 mm 써야 해요? | 100mm·KDS 단정 없이 설계도서·시방서 확인 안내(운영 V21·V22 비활성) |
| 벤치플룸관이 뭐예요? | KS F 4010(철근 콘크리트 플룸 및 벤치 플룸) + 등록 규격 |

- 되돌리기(`docs/03-analysis/ai-sourcing/prod-apply/90_rollback.sql`): [A] 활성화 해제 → [B] 등록 행 삭제 → [C] 칸 삭제. 막히면 0)의 백업으로 복원.

## 기록 (사용자 작성 → 공유)

| 항목 | 값 |
|---|---|
| 기준 커밋(`git log -1 --format=%h`) | |
| 1-① check 결과·백업 폴더·upload 일시 | |
| 1-② 30 결과(행 수·출처별·지문 일치 여부) | |
| 1-③ 1) docs·active · 6) verified_active · 검토자 | |
| 운영 화면 확인 이상 유무 | |
