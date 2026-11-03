# 운영 DB 반영 순서 — 제조사 자료 + 법령 근거 (2026-11-02)

두 변경은 **서로 독립**이다(다른 테이블). **제조사 자료를 먼저** 권장 — 새 테이블 2개 추가뿐이라 기존 테이블·데이터를 바꾸지 않는다.
코드와의 순서: **AI 상담 코드 5개(1-①)를 먼저 배포한 뒤** 이 문서의 DB 단계를 권장 — 옛 코드도 새 DB 를 읽지만 제품별 묶음·조건 문장·근거 구분 개선이 없다. 단계별 배포 범위·순서: [CODE-DEPLOY-PLAN.md](CODE-DEPLOY-PLAN.md).
제조사 자료 지문(2026-11-02 03:51 사용자 PC 재생성): `ee65a7eee2c4b4b3cd23d0537cbc1c6f9f45e96ad321e6f046d61fe38c7fd64e` — 승인 행이 바뀌면 다시 만든 export-report.md 의 값을 쓴다.
모든 실행은 phpMyAdmin(SQL 탭)에서 사용자가 한다. **[승인]** 표시 단계는 매번 따로 승인(이전 승인은 이어지지 않음). [읽기] 는 승인 없이 가능.

로컬 리허설(운영과 같은 구조의 `*_test` DB, 운영 접속 없음): `bash scripts/testdb/rehearse-prod-apply.sh` — 아래 순서 그대로 적용(파일마다 2회 실행)·확인·상담 점검·되돌리기 후 적용 전과 같음까지 확인. 운영 배포 코드(`02a98c2`)로도 같은 점검. 2026-11-02 결과 **PASS 181 · FAIL 0**.

## 0-1. 운영 적용 전 실제 14행 리허설 (사용자 PC, 운영 접속 없음)

실제 이관 파일(`data/external/crawl/export/`)과 법령 SQL 을 사용자 PC 의 MySQL 8.0 테스트 서버(예: 3308)에 만든 운영 유사 DB 에 문서 순서대로 적용 → 사후 확인 → 상담 점검(실제 가야스틸·선로드·동양 자료가 출처와 함께 나오는지, 이름에 가격·'PRODUCT' 없음) → 되돌리기까지 한 번에. 끝나면 리허설 DB 는 지워진다.

```bash
MYSQL_CMD="/c/mysql8/bin/mysql.exe -h127.0.0.1 -P3308 -uroot" MYSQL_PWD= bash scripts/testdb/rehearse-prod-apply.sh --real-export=data/external/crawl/export
```

- 마지막 줄 `합계: PASS … · FAIL 0` 이면 운영 적용 준비 완료. FAIL 이 있으면 출력 그대로 공유.
- 이 환경(클라우드)에서 같은 모양의 모의 이관 파일로 확인: PASS 192 · FAIL 0. 고정 자료 모드: PASS 181 · FAIL 0.

## 0. 사전 점검 [읽기]

```sql
SELECT VERSION();                                                        -- 8.0.x
SELECT FIND_IN_SET('NO_BACKSLASH_ESCAPES', @@SESSION.sql_mode) AS nbe;   -- 0 (1 이면 중단 — 이관 SQL 의 \' 이스케이프 전제)
SHOW TABLES LIKE 'external_product%';                                    -- 0행
SELECT COUNT(*) AS docs, SUM(is_active) AS active FROM rag_documents;    -- 값을 적어 둠(저장소 기준 5 / 5)
SELECT COUNT(*) AS cols FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rag_documents';      -- 9 (법령 칸 없음)
```

## 1. 백업

- phpMyAdmin → `rag_documents` → 내보내기(SQL) → 파일 보관. **법령 단계 전 필수.** (가능하면 DB 전체 내보내기도)
- 제조사 자료: 운영에 테이블이 아직 없어 백업할 것이 없다(0단계 `SHOW TABLES` 0행이 기준점).

## 2. 제조사 자료 (external_products)

| 단계 | 할 일 | 승인 | 기대 |
|---|---|---|---|
| 2-0 | 사용자 PC: `git pull` 후 **다시 내보내기** `DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php scripts/external/export-approved.php --expect=14` | — | 막힘 0 · 14행. 이전 실행 결과(지문 `692f8233…`)는 **폐기**(이름 정리로 지문이 바뀜). 중단되면 20·30 이 지워짐 |
| 2-1 | `data/external/crawl/export/10_create_tables.sql` | **[승인] 구조** | 테이블 2개 생성(다시 실행해도 안전) |
| 2-2 | `20_insert_approved.sql` | **[승인] 데이터** | 14행(한 트랜잭션, 다시 실행해도 중복 없음) |
| 2-3 | `30_post_check_readonly.sql` | [읽기] | approved_rows 14 · 출처별 보고서와 같음(지난 실행: dyhumepipe 1 · gayasteel 5 · sunroad 8) · **fingerprint = 새로 만든 `export-report.md` 의 지문** · matches 0 · priced 0 |

상담 확인(운영 채팅): "스틸 그레이팅은 어떤 특징이 있어요?", "흄관은 어디에 써요?", "보도블록 종류별 차이 알려 주세요" → 제조사 이름을 밝힌 "○○ 자료에 따르면" + 출처 카드. 가격·상품처럼 말하지 않음.

되돌리기: `90_rollback.sql`(SELECT 로 14 확인 → DELETE) **[승인] 데이터** → 테이블까지: `DROP TABLE external_product_matches; DROP TABLE external_products;` **[승인] 구조**(운영에 원래 없던 테이블, 다른 자료가 없을 때만).

## 3. 법령 근거 (rag_documents) — 이 폴더의 파일

| 단계 | 할 일 | 승인 | 기대 |
|---|---|---|---|
| 3-1 | `database/20261102_rag_documents_legal_columns.sql` | **[승인] 구조** | 칸 25개(9+16)·색인 2개, 기존 행 `internal` |
| 3-2 | `10_register_inactive.sql` | **[승인] 데이터** | 66행 추가, 전부 비활성(다시 실행 시 0행) |
| 3-3 | `30_post_check_readonly.sql` | [읽기] | 각 줄 주석의 기대값. 20 전이므로 verified_active 0, 마지막 줄(검토자) 0행 |
| 3-4 | (선택) README (ii) V25·V26 공식 사이트 확인 | — | 확인되면 다시 생성(활성화 대상 22건) |
| 3-5 | 사용자 PC: `php scripts/legal-sources/build_prod_sql.php --reviewer='실제 검토자' --reviewed-on=YYYY-MM-DD --out=backup/legal-stage1` → `backup/legal-stage1/20_activate_ready.sql` 내용 검토(20건, V21·V22 없음) → 실행 | **[승인] 데이터** | "20행 영향". 저장소 밖(`backup/`, .gitignore)에 생성 — 저장소 10·30·90 파일을 다시 쓰지 않음 |
| 3-6 | `30_post_check_readonly.sql` | [읽기] | verified_active 20 · v21_v22_active 0 · public_road_active 0 · source_type: kcs 4 · kds 5 · ks 20 · law 37(활성 20) · internal = 0단계 값 · 검토자 1행 n=20 |

상담 확인(3-2 뒤에는 **변화 없음**이 정상 — 전부 비활성. 3-5 뒤):

| 질문 | 기대 |
|---|---|
| 가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요? (공공 보도) | 보도 설치 및 관리 지침 근거 [L번호] 와 함께 안내 |
| 집 앞 마당에 차 두 대 주차할 건데 블록 두께 몇 T? (사유지) | "저희 권장 기준" 80T — 법정 의무처럼 말하지 않음 |
| 아파트 단지 주차장 블록 80T면 되나요? (주차장) | 80T 권장 + 주차장법 근거가 붙을 수 있음, 공공도로 기준 없음 |
| 시청에서 발주한 도로 공사인데 차도에 80T 인터로킹 블록 깔아도 되나요? (공공도로) | 100mm·KDS/KCS 44 50 25 언급 없이 설계도서·시방서 두께 확인 안내(V21·V22 비활성) |

되돌리기(`90_rollback.sql`, 필요한 단계만, 각각 **[승인]**): [A] 20 되돌리기(활성화 해제) → [B] 10 되돌리기(등록 행 삭제) → [C] 칸 삭제(마이그레이션 머리말의 `ALTER TABLE … DROP …`, 구조). 사내 문서는 어느 단계에서도 바뀌지 않음. 막히면 1단계 백업으로 복원.

## 4. 코드 배포와의 관계

- 이 DB 변경은 코드 배포 없이도 동작한다: 운영 코드(`02a98c2`, 2026-10-30 배포)는 칸·테이블이 없으면 기존대로, 생기면 법령 근거(장소 범위 포함)와 제조사 자료(material_info)를 쓴다 — 리허설에서 단계마다 확인. **다만 권장 순서는 AI 상담 코드 5개 먼저**(제품별 묶음·조건 문장·근거 구분 개선) — 1단계 실행표: [STAGE1-RUNBOOK.md](STAGE1-RUNBOOK.md).
- 최신 코드의 효과 — material_info **basics**(KS 표준 번호·이름 + 토목몰 등록 상품 규격), 상담 화면 이름 정리, 근거 종류(공식 기준·제조사 자료·저희 권장) 구분 프롬프트 — 는 **코드 배포 후**에 나타난다(별도 승인, `docs/DEPLOYMENT.md`). DB 반영과 코드 배포는 어느 순서든 된다.
