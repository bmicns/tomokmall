# 상용콘크리트(syccrt.com) 상품 표 — syc_categories · syc_products

작성 2026-11-10 · 상태: **표만 만들어 둠(코드에서 아직 사용 안 함)**

| 파일 | 내용 |
|---|---|
| `database/20261110_syc_categories.sql` | 전용 카테고리 표 + 대분류 10 · 품목 81 (91행), 마진율은 기존 `categories` 에서 복사 |
| `database/20261110_syc_products.sql` | 전용 상품 표(행 없음). `products` 구조 참조 |

- 실행 순서: categories → products (외래키). 둘 다 가격 칸이 있어 **phpMyAdmin 에서 사용자 승인 후** 실행(원격 관리 API 는 가격 쓰기를 막음).
- 다음 단계(예정): 디자인 '제품관리(일괄 등록)' — 엑셀(csv·xlsx) + 이미지 ZIP 두 파일을 올리면 `syc_products` 에 **비활성**으로 저장.
- 측구수로관 A형(s1~s3)·B형(s5·s6)은 카탈로그 표대로 카테고리를 나눠 둔다(사용자 결정 2026-11-10).

## 1. products 와 다른 점

| 항목 | products | syc_products |
|---|---|---|
| 카테고리 | `categories.id` | `syc_categories.id` |
| 이미지 | `image_url`(외부 주소) | `image_file`(이미지 ZIP 에서 꺼내 서버에 저장한 파일 경로) |
| 모델코드 | `spec_code` = 규격 괄호 속 코드 | `spec_code` = 카탈로그 **호칭** |
| 치수 칸 | 케이토목 규격 기준 | 상용콘크리트 카탈로그 머리글 기준 + **도면 세부 치수 칸**(아래 2장) |
| `spec_option` | VARCHAR(80) | VARCHAR(160) — 치수·모델코드 외 나머지 |
| `main_category` | 있음(코드 미사용) | 없음 |
| `is_active` 기본값 | 1 | 0(일괄 등록은 비활성 저장) |

## 2. 카탈로그 머리글 → 칸

- 숫자 치수는 mm(카탈로그가 m 표기면 ×1000), 중량은 kg(t 표기면 ×1000) → `weight_kg`.
- **대표 치수**는 뜻 이름 칸(`dim_w`·`dim_h`·`dim_l`·`dim_t` …), **도면 세부 치수**는 카탈로그 기호 그대로 번호 칸
  (`dim_w1`·`dim_w2`, `dim_h1`~`h4`, `dim_t1`·`dim_t2`·`dim_t3`·`dim_t4`, `dim_l1`~`l6`, `dim_d1`~`d5`, `dim_r1`·`r2`, `dim_c`, `dim_x1`~`x4`).
  같은 번호 칸도 품목마다 뜻이 다르므로 이 표가 기준이다.
- 치수·모델코드가 아닌 값(그레이팅 규격, 비고, 재질, 설치방식, 철근 지름·개수, 균열·파괴 하중 등)은 `spec_option`.
- 벤치플륨 Ⅱ·Ⅲ 의 aS·cS·dS·eS·hS·lS·l'(예: '261이상')와 허용차(±3 등)는 치수가 아닌 품질 기준값이라 칸을 두지 않는다(필요하면 `description`).

| 품목(카탈로그 구분자) | spec_code | 대표 치수 | 세부 치수 | spec_option·기타 |
|---|---|---|---|---|
| 측구수로관 A·AN형 (s1~s4) | 구분(200, 300*400 …) + 형식 | W→`dim_w`, H→`dim_h`, T→`dim_t`, L→`dim_l` | w1·w2→`dim_w1`·`w2`, h1~h4→`dim_h1`~`h4`, t1~t4→`dim_t1`~`t4` | 그레이팅 규격 |
| 측구수로관 B형 (s5·s6) | 〃 | 〃 | w1·w2, h1~h3, T1~T3→`dim_t1`~`t3`, l1·l2→`dim_l1`·`l2` | 그레이팅 규격 |
| 측구수로관 Z형·유공 침투 (s7·s8) | 호칭 | 위와 같음(자료 오면) | | 주문품 |
| 그레이팅 높이조절용 측구수로관 (s9) | 호칭 | 폭*깊이*두께 → `dim_w`·`dim_depth`·`dim_t` | | 그레이팅 규격·비고 |
| 벤치플륨 Ⅱ (s10) | 호칭 | a→`dim_w`, c→`dim_w_b`, h→`dim_depth`, d→`dim_t`, e→`dim_t2`, L→`dim_l` | b→`dim_w1`, r→`dim_r1` | |
| 벤치플륨 Ⅲ (s11) | 호칭 | 〃(l→`dim_l`) | b→`dim_w1`, m→`dim_x1`, i→`dim_x2` | 세로·가로 철근 지름·개수 |
| U형 플륨 (s12) | 호칭 | A→`dim_w`, H→`dim_h`, T→`dim_t`, L→`dim_l` | A'→`dim_w1`, H'→`dim_h1`, h→`dim_h2`, T'→`dim_t1`, L'→`dim_l1`, R→`dim_r1`, R'→`dim_r2` | |
| 환경수로관 (s13) | 호칭 | a→`dim_w`, c(밑나비)→`dim_w_b`, h→`dim_depth`, d→`dim_t`, e→`dim_t2`, ℓ→`dim_l` | b→`dim_w1`, f→`dim_x1`, g→`dim_x2` | 철근·보강근 지름·개수 |
| 원형수로관 NS (s14) | 호칭(Ø150) | Ø→`dim_d`, 폭→`dim_w`, 높이(1)→`dim_h`, 길이→`dim_l` | NS-01 높이2→`dim_h1` | NS-01·02·03 |
| 원형사각수로관 RDG (s15) | 호칭명 | a→`dim_w`, h→`dim_depth`, d→`dim_t`, A→`dim_w_o`, H→`dim_h_o`, L→`dim_l` | L1~L3→`dim_l1`~`l3` | 스틸그레이팅 규격 |
| 사각수로관 (s16) | 호칭 | a→`dim_w`, b→`dim_depth`, A→`dim_w_o`, B→`dim_h_o`, L→`dim_l`, 상부 c→`dim_t_top`, 바닥 d→`dim_t_bottom`, 벽체 e→`dim_t_wall` | | |
| 원심력수로관 (s17) | 호칭 | a→`dim_w`, b→`dim_h`, l→`dim_l`, t1→`dim_t`, t2→`dim_t2` | l1→`dim_l1`, 타공 m·n→`dim_x1`·`x2` | W2·S2·무개·유개, 그레이팅 규격 |
| 알루미늄트렌치 (s18) | | 폭→`dim_w`, 깊이→`dim_depth`, 길이→`dim_l` | 개구부→`dim_x1` | 그레이팅·안전커버 |
| 흄관 (s19) | 호칭 | 인지름→`dim_d_i`, T→`dim_t`, L→`dim_l` | D1~D4→`dim_d1`~`d4`, l1~l5→`dim_l1`~`l5` | 균열·파괴 하중 |
| V·R관 (s20) | 호칭 | 몸체 D→`dim_d_i`, T→`dim_t`, L→`dim_l` | D1~D5→`dim_d1`~`d5`, l1→`dim_l1`, l2=l3→`dim_l2`·`dim_l3`(같은 값), L4~L6→`dim_l4`~`l6` | |
| 암거 1련·2련, 개거수로 (s21~s23) | 내공 B×H(mm) | B→`dim_w`, H→`dim_h`, Bo→`dim_w_o`, Ho→`dim_h_o`, L→`dim_l`, **T1(상판)→`dim_t_top`, T2(하판)→`dim_t_bottom`, T3(측벽)→`dim_t_wall`**, T4(2련)→`dim_t_mid` | 헌치 C→`dim_c`, 삽입구 ℓ1→`dim_l1`, 수구 ℓ2→`dim_l2` | 보강 등 |
| 사각맨홀·콘크리트 빗물받이 (s24·s25) | 호칭 | 내경 가로→`dim_w`, 세로→`dim_v`, 높이→`dim_h`, 벽체→`dim_t_wall`, 기초→`dim_t_bottom`, 슬라브→`dim_t_top` | | 그레이팅 규격 |
| 원형맨홀 (s26) | 호칭 | 호칭→`dim_d_i`, 높이→`dim_h`, 벽체·기초·슬라브 두께 위와 같음 | | 구분(소형맨홀 등) |
| 조립식 맨홀 (s28) | 규격(S200 …) | Φ→`dim_d_i`, 벽두께→`dim_t_wall` | | 상부·연직·하부구체 |
| 전기통신·통신 맨홀 (s29·s30) | 호칭 | A→`dim_w`, B→`dim_v`, H→`dim_h`, T→`dim_t` | | |
| 내충격 PVC오수받이 (s36) | | 물받이지름→`dim_d`, 유입측→`dim_d_inlet`, 유출측→`dim_d_outlet` | | |
| PE 빗물받이·오수받이·집수정 등 (s41~s48) | | 제품규격 a*b*c → `dim_w`·`dim_v`·`dim_h` | A·B→`dim_w1`·`dim_w2` | 뚜껑 종류 |
| 주철 COVER (s51~s53) | 호칭 | 원형 A→`dim_d`, 외경→`dim_d_o`, 내경→`dim_d_i`, H(전체높이)→`dim_h` | B(뚜껑높이)→`dim_h1` | 재질(회주철·구상흑연주철), 인도·차도 |
| 보도·옹벽 블록 (s54~s71) | | 가로→`dim_w`, 세로→`dim_v`, 두께(높이)→`dim_t` | | 투수/불투수, 색상 |
| 콘크리트·인조 경계석 (s72·s73) | 호칭(A …) | 윗면→`dim_w_t`, 밑면→`dim_w_b`, 높이→`dim_h`, 길이→`dim_l` | | 구분(보차도 등) |
| 화강 경계석 (s74) | | 폭→`dim_w`, 높이→`dim_h`, 깊이(1000)→`dim_l` | | 직선·곡선 |
| 가로수보호틀 (s75) | 품명(KCI-H1 …) | 보호판 규격 → `dim_w`·`dim_v`·`dim_h` | | 품명(형), 받침틀 규격 |
| 파형강관 (s76) | | 관경→`dim_d_i`, 두께→`dim_t` | | 파형(60x10 …) |
| 용접철망 (s77) | 품번(#10) | 품번→`spec_wire_no`, 선경→`dim_wire_d`, A×B→`dim_w`·`dim_v`, 망눈간격→`dim_mesh` | | |
| 건널목수로관 (w1) | 호칭(500C …) | 폭 a→`dim_w`, h→`dim_depth`, 두께 d→`dim_t`, 길이 l→`dim_l` | b→`dim_w1` | 구분(3종), 중량 |
| 스텐볼라드 - HK (w2) | | Ø→`dim_d`, 높이→`dim_h`, 두께(2T)→`dim_t`; U형 'Ø101.6×600×1200×2T' → 높이 600·폭 1200(`dim_w`) | | 매립식·이동식·앙카식, U형 |
| 탄성볼라드 - HK (w3) | | Ø→`dim_d`, 높이→`dim_h` | 스텐 기둥 Ø114→`dim_d1` | 실리콘·HK-2, 설치방식 |
| 석재볼라드 - SCG (w4) | | (주문제작 — 치수 없음) | | 석종·타입·마감 |

- `dim_x3`·`dim_x4` 는 예비 칸이다. 쓸 때는 먼저 이 표에 품목별 뜻을 적는다.

## 3. 확인된 사항(2026-11-10)

- 암거·개거 두께: T1 = 상판, T2 = 하판, T3 = 측벽(사용자 제공 도면). 2련 T4 는 중간벽으로 둠(2련 도면 확인 시 수정).
- 스텐볼라드 U형 'Ø101.6 x 600mm x 1200mm x 2T' = 높이 600 · 폭 1200(사용자 확인).
- 마진율 복사 기준(대분류 → 기존 카테고리)은 `20261110_syc_categories.sql` [5] 주석 참고. 2026-11-10 운영 값은 모두 개인 20 / 기업 10.
