# 민간 쇼핑몰·제조사 자재 정보 수집 — 우선순위·자동 수집·상담 연결 (2026-10-29)

목표: 초보 고객이 추가 검색 없이 자재를 이해하고 고를 수 있게, 국내 쇼핑몰·제조사의 **공개 자재 정보(규격·재질·용도·특징·주의·KS)** 를 확보해 AI 상담의 참고 지식으로 쓴다.
판매 상품·가격은 토목몰 DB 기준이며, 외부 자료는 사람이 승인한 것만 상담에 나온다.

조달청 OpenAPI 수집기(`scripts/external/g2b-api.php`)는 코드만 유지 — **공식 명세 확인·실제 호출은 아직 안 됨**, 활용신청·추가 개발은 우선순위를 낮춤(보조 출처).

## 1. 네트워크 진단 (이 개발 환경)

| 항목 | 확인 결과 |
|---|---|
| 실행 환경 | Claude Code 웹(claude.ai/code)의 **클라우드 컨테이너**(로컬 PC 아님). 바깥 연결은 컨테이너 안 프록시(HTTPS_PROXY) → 정책 게이트웨이를 거침 |
| 차단 원인 | **환경의 네트워크 정책(허용 도메인 목록)**. 프록시 상태 기록: `ktomok.com:443`, `www.data.go.kr:443`, `api.openai.com:443`, `enmcon.co.kr:443` 모두 "gateway answered 403 to CONNECT (policy denial)". DNS·프록시 설정 문제가 아님(허용된 pypi.org·npmjs·api.github.com 은 HTTPS 정상) |
| 평문 HTTP | **허용 도메인이어도 `http://` 는 403**(https 만 통과). 제조사 상당수가 http 전용(sunroad·88con·enmcon·dhconc·toamconcrete·kr-con·dyhumepipe 등) → 이 환경에서는 도메인을 허용해도 수집 불가 가능성이 큼 |
| 사용자가 바꿀 수 있나 | 예. 세션 제목줄의 클라우드 환경 메뉴 → **Edit → Network access** 에서 접근 수준을 넓히거나, Limited 수준의 **Allowed domains** 에 도메인 추가('Allow package managers' 는 체크 유지). 안내: https://code.claude.com/docs/en/cloud-environments#network-access . 변경은 새 세션부터 적용될 수 있음 |
| 결론 | 가장 확실한 경로는 **사용자 PC 에서 한 번 실행**(아래 4장). 클라우드에서 하려면 아래 도메인을 허용(https 사이트만 가능) |

허용 후보 도메인과 목적:

| 도메인 | 목적 |
|---|---|
| syccrt.com, kcon.co.kr, xn--2i0bw4q0yi42ecvqlc.kr(가야스틸 한글 도메인) | 제조사 공개 제품 페이지(https 확인된 곳) |
| sunroad.co.kr, www.sunroad.co.kr, 88con.com, www.88con.com, enmcon.co.kr, dhconc.co.kr, www.dhconc.co.kr, gayasteel.kr, toamconcrete.com, www.toamconcrete.com, kr-con.co.kr, dyhumepipe.fzst.kr, greenpipe.kr | 제조사 공개 제품 페이지(http 전용이면 클라우드에서는 실패) |
| www.data.go.kr, apis.data.go.kr | 조달청 공공데이터(보조, 우선순위 낮춤) |
| api.openai.com | 실제 GPT-4o 상담 시험(키는 환경 변수) |
| ktomok.com, dacity.kr | **허락 받은 뒤에만**(지금은 접속 불필요) |

## 2. 15곳 재정리 (기존 조사 `sites.json`·출처 확인 결과 재사용)

판정: **자동 수집** = 제조사 공개 제품 페이지, 실행 때마다 robots.txt 와 이용약관(자동수집·무단 복제 금지 문장)을 자동 확인해 통과하면 사실 정보만 수집 / **허락 필요** = 별도 관리 / **수집 안 함**.

| 순위 | 출처 | 자재 | 확보 가능 정보 | API·제휴·카탈로그 | 자동 수집·조건 | 상담 가치 | 난이도 |
|---|---|---|---|---|---|---|---|
| 1 | 선로드 sunroad.co.kr | 인조화강블록 보도·차도, 투수·보통 | 규격·용도(보도/차도)·공정 설명·조달 식별번호 | 없음 / 카탈로그 요청 가능 | 자동 수집(robots 없음, 약관 링크 없음) | 상(블록 상담 직결) | 중(http) |
| 2 | 88콘크리트 88con.com | 인조화강블록 보도·차도·투수 | 다수 규격·용도 | 없음 | 자동 수집(robots 상품 경로 허용, 약관 링크 있음 — 실행 때 문장 확인) | 상 | 중 |
| 3 | 엔엠콘 enmcon.co.kr | 보도·인조화강·점토·점자블럭 | 치수·중량 | 없음 | 자동 수집(robots: /wp-admin/ 만 금지) | 상 | 하 |
| 4 | 대한산업 dhconc.co.kr | 주철·스틸 그레이팅, 트렌치 | 규격표·재질·용도(측구·집수정) | 없음 | 자동 수집 | 중 | 중 |
| 5 | 가야스틸 gayasteel.kr | 중하중·스틸·디자인 그레이팅 | 높이 규격·재질·대형차 하중 안내 | 하중구조계산서 제공 안내 | 자동 수집 | 중 | 중(도메인 2개) |
| 6 | 토암콘크리트 toamconcrete.com | 스틸 그레이팅(집수정·측구) | 중량 포함 규격표 | 없음 | 자동 수집 | 중 | 중 |
| 7 | 고려콘크리트 kr-con.co.kr | 흄관·VR관(KS)·사각수로관·보도블럭 | 규격표·KS 번호 | 없음 | 자동 수집 | 중 | 중 |
| 8 | 상용콘크리트 syccrt.com | 흄관 A/B·유공·VR관 | 제품 규격 | 없음 | 자동 수집(robots: 회원·장바구니만 금지) | 중 | 하 |
| 9 | 동양콘크리트 dyhumepipe.fzst.kr | 흄관(KS F 4403)·레진·VR·PE·PVC | 규격·KS 표기 | 없음 | 자동 수집(이용약관 링크 있음 — 실행 때 확인) | 중 | 중 |
| 10 | 경북산업 kcon.co.kr | PC암거·수로관·맨홀 | 미확인 | 없음 | 자동 수집 시도(robots 403 — 접속 제한 가능) | 중 | 상 |
| 11 | 그린파이프 greenpipe.kr | PE이중벽관·다중벽관 | 미확인(도메인 미확인) | 물가지 공표가(재게시 불가) | 자동 수집 시도(접속 실패 시 건너뜀) | 중 | 상 |
| — | 케이토목 ktomok.com | 토목자재 전반(우리 상품 출처) | 규격·단가·단위 | 없음 | **허락 필요**(유통몰 상품·단가 DB, 기존 수집분 사후 허락) | 상 | — |
| — | 동아산업 dacity.kr | 암거·수로관·그레이팅·인조화강 | 규격·설명 | 나라장터 등록 | **허락 필요**(ktomok 과 DB 공유 추정, 권리자 확인) | 상 | — |
| — | kcrcic.or.kr | 흄관 카탈로그 PDF | 카탈로그(물품분류번호) | 카탈로그 PDF | **허락 필요**(단체 카탈로그 저작물) | 중 | — |
| — | 토목자재114 tomok114.com | 그레이팅 | 가격·규격 | 없음 | **수집 안 함**(경쟁 유통몰) | — | — |

## 3. 구현

| 파일 | 내용 |
|---|---|
| `data/external/sources.json` | 제조사 11곳 `facts_if_clear`(시작 URL·우선순위·상담 가치·난이도), ktomok·dacity·kcrcic `pending`, tomok114 `blocked` |
| `scripts/external/crawl-sources.php` | **한 번 실행으로 전부**: 출처별 robots.txt → 이용약관 금지 문장 확인 → 제품 페이지 너비 우선(기본 40쪽, 2초 간격, 같은 사이트·제품 단서 링크만, 로그인·장바구니·관리 경로 제외) → 규격표 행·원문 문장 → `--import` 로 정규화·중복 제거·매칭·검증 구역 → `data/external/crawl/report.md` |
| `app/external_crawl.php` | 규격표 → 상품 행(가격 열은 버림), 원문 문장 분류(용도·시공 / 특징 / 주의 / 인증 / 강도 — 원문 그대로, 200자 이하), 링크 고르기(카탈로그 PDF 는 기록만) |
| `app/external_api.php` | robots.txt·이용약관 판정 공용 함수(이메일 무단수집 거부 문구는 금지 문장으로 보지 않음) |
| `app/external_products.php` | 원문 문장(facts) 보존·개인 연락처 제거, 규격표 '두께 80' 판독, `ext_material_info()`(승인된 자료만) |
| `scripts/external/import.php` | `--approve=외부ID,…` / `--approve-source=출처` / `--reject=` — 상담 반영은 사람이 승인 |
| `app/ai_tools.php` | 새 도구 **`material_info`**(자재 차이·특징·물빠짐·주의 → 승인된 원문 문장 + 출처, 판매 상품·가격 아님), 상담 화면 '참고 자료 출처' 카드 |
| `app/rag.php` | 규칙 1줄: 자재 설명 질문은 material_info 먼저, '제조사 자료에 따르면' 출처와 함께, 없으면 수치 없이 일반 설명, 출처끼리 다르면 단정 금지, 공식 기준 수치는 [L번호]만 |

추측 금지: 원문에 없는 내용은 저장·생성하지 않는다(문장은 원문 그대로, 이미지·가격·설명 전문 복제 없음). 검증 구역(pending) → 사람이 승인(approved)한 것만 상담에 나온다.

## 4. 실행 (사용자 PC — 최소 작업)

```
git pull origin claude/loving-gates-surk6e
export DB_HOST='127.0.0.1;port=3308' DB_NAME=tomokmall_test DB_USER=root DB_PASS=      # MySQL 8.0 테스트 DB(prompt-simulation 7-4장)
/c/xampp/php/php.exe scripts/external/crawl-sources.php --import
```
- 약 11개 출처 × 최대 40쪽 × 2초 ≈ 15~20분. 결과: `data/external/crawl/report.md` + 출처별 `*-import.md`(이 파일들을 보내 주면 검토 후 승인 명령을 드린다).
- 테스트 DB 에 외부 자료 테이블이 없으면 설정 스크립트를 다시 실행(`setup-local-test-db.sh` 가 `database/20261029_external_products.sql` 적용).

## 5. 검증 (실제 외부 사이트 아님)

- 단위: 페이지 추출 13건(원문 문장 분류·규격표 → 상품 행·가격 열 제외·링크 선택·robots·약관 판정).
- 통합(로컬 목업 사이트 3개): A 수집·B 약관 금지 건너뜀·C robots 전체 금지 건너뜀, ktomok·tomok114 접속 없이 기록, robots 금지 경로 미접속, 가격 미저장, 매칭 후보, **승인 전에는 상담 참고 자료 없음 → 승인 후 material_info 가 원문 문장·출처 반환**, products 불변 — 14건 통과.
- 초보 질문 5개(실제 GPT-4o 아님 — 프롬프트·도구 결과 무료 시뮬레이션, 테스트 DB):
  - 발견·수정: "물이 잘 빠지는 블록은?" 이 블록 종류 확인 질문으로 빠지고, '투수' 검색에 '불투수' 제품이 섞여 **불투수가 예상 최저로 표시**되던 문제 → 물빠짐·빗물·투수 표현을 바닥 포장으로 인식, '투수' 검색에서 불투수 제외. "차가 가끔 지나가는 마당에는 어떤 자재가?"(블록 단어 없음)도 보도블록 상담으로 인식.
  - 지금은 승인된 외부 자료가 0건이라 material_info 는 "자료 없음 — 일반 설명만"을 돌려준다(차이·특징 질문의 품질은 수집·승인 후 실제 GPT-4o 로 다시 확인 필요).
