# 상용콘크리트(syccrt.com) 상품 표 — syc_categories · syc_products

작성 2026-11-10 · 상태: **표만 만들어 둠(코드에서 아직 사용 안 함)**

| 파일 | 내용 |
|---|---|
| `database/20261110_syc_categories.sql` | 전용 카테고리 표 + 대분류 10 · 품목 81 (91행), 마진율은 기존 `categories` 에서 복사 |
| `database/20261110_syc_products.sql` | 전용 상품 표(행 없음). `products` 구조 참조 |

- 실행 순서: categories → products (외래키). 둘 다 가격 칸이 있어 **phpMyAdmin 에서 사용자 승인 후** 실행(원격 관리 API 는 가격 쓰기를 막음).
- 다음 단계(예정): 디자인 '제품관리(일괄 등록)' — 엑셀(csv·xlsx) + 이미지 ZIP 두 파일을 올리면 `syc_products` 에 **비활성**으로 저장.

## 1. products 와 다른 점

| 항목 | products | syc_products |
|---|---|---|
| 카테고리 | `categories.id` | `syc_categories.id` |
| 이미지 | `image_url`(외부 주소) | `image_file`(이미지 ZIP 에서 꺼내 서버에 저장한 파일 경로) |
| 모델코드 | `spec_code` = 규격 괄호 속 코드 | `spec_code` = 카탈로그 **호칭** |
| 치수 칸 | 케이토목 규격 기준 | 상용콘크리트 카탈로그 머리글 기준(아래 2장) |
| `spec_option` | VARCHAR(80) | VARCHAR(160) — 치수·모델코드 외 나머지 |
| `main_category` | 있음(코드 미사용) | 없음 |
| `is_active` 기본값 | 1 | 0(일괄 등록은 비활성 저장) |

## 2. 카탈로그 머리글 → 칸

숫자 치수는 mm(카탈로그가 m 표기면 ×1000), 중량은 kg(t 표기면 ×1000). 표에 없는 도면 세부 치수(측구수로관 w1·h1~h4·t1~t4, 흄관 D1~D4·l1~l5 등)는 칸을 두지 않는다(도면 참고용).

| 품목(카탈로그 구분자) | spec_code | 치수 칸 | spec_option·기타 |
|---|---|---|---|
| 측구수로관 A·B·AN형 (s1~s7) | 구분(200, 300*400 …) + 형식(A·B) | W→`dim_w`, H→`dim_h`, T→`dim_t`, L→`dim_l` | 그레이팅 규격, 중량→`weight_kg` |
| 그레이팅 높이조절용 측구수로관 (s9) | 호칭 | 폭*깊이*두께 → `dim_w`·`dim_depth`·`dim_t` | 그레이팅 규격·비고 |
| 벤치플륨 Ⅱ·Ⅲ, 환경수로관 (s10·s11·s13) | 호칭 | a→`dim_w`, c(밑나비)→`dim_w_b`, h→`dim_depth`, d→`dim_t`, e→`dim_t2`, L(ℓ)→`dim_l` | 참고중량→`weight_kg` |
| U형 플륨 (s12) | 호칭 | A→`dim_w`, H→`dim_h`, T→`dim_t`, L→`dim_l` | |
| 원형수로관 NS (s14) | 호칭(Ø150) | Ø→`dim_d`, 폭→`dim_w`, 높이→`dim_h`, 길이→`dim_l` | NS-01·02·03 |
| 원형사각수로관 RDG (s15) | 호칭명 | a→`dim_w`, h→`dim_depth`, d→`dim_t`, A→`dim_w_o`, H→`dim_h_o`, L→`dim_l` | 스틸그레이팅 규격 |
| 사각수로관 (s16) | 호칭 | a→`dim_w`, b→`dim_depth`, A→`dim_w_o`, B→`dim_h_o`, L→`dim_l`, c→`dim_t_top`, d→`dim_t_bottom`, e→`dim_t_wall` | |
| 원심력수로관 (s17) | 호칭 | a→`dim_w`, b→`dim_h`, l→`dim_l`, t1→`dim_t`, t2→`dim_t2` | W2·S2·무개·유개, 그레이팅 규격 |
| 알루미늄트렌치 (s18) | | 폭→`dim_w`, 깊이→`dim_depth`, 길이→`dim_l` | 그레이팅·안전커버 |
| 흄관 (s19) / V·R관 (s20) | 호칭 | 인지름·몸체 D→`dim_d_i`, T→`dim_t`, L→`dim_l` | |
| 암거 1련·2련, 개거수로 (s21~s23) | 내공 B×H(mm) | B→`dim_w`, H→`dim_h`, Bo→`dim_w_o`, Ho→`dim_h_o`, L→`dim_l`, T1→`dim_t_top`, T2→`dim_t_wall`, T3→`dim_t_bottom`, T4(2련)→`dim_t_mid` | 헌치·삽입구 치수는 칸 없음 |
| 사각맨홀·콘크리트 빗물받이 (s24·s25) | 호칭 | 내경 가로→`dim_w`, 세로→`dim_v`, 높이→`dim_h`, 벽체→`dim_t_wall`, 기초→`dim_t_bottom`, 슬라브→`dim_t_top` | 그레이팅 규격 |
| 원형맨홀 (s26) | 호칭 | 호칭→`dim_d_i`, 높이→`dim_h`, 벽체·기초·슬라브 두께 위와 같음 | 구분(소형맨홀 등) |
| 조립식 맨홀 (s28) | 규격(S200 …) | Φ→`dim_d_i`, 벽두께→`dim_t_wall` | 상부·연직·하부구체 |
| 전기통신·통신 맨홀 (s29·s30) | 호칭 | A→`dim_w`, B→`dim_v`, H→`dim_h`, T→`dim_t` | |
| 내충격 PVC오수받이 (s36) | | 물받이지름→`dim_d`, 유입측→`dim_d_inlet`, 유출측→`dim_d_outlet` | |
| PE 빗물받이·오수받이·집수정 등 (s41~s48) | | 제품규격 a*b*c → `dim_w`·`dim_v`·`dim_h` | 뚜껑 종류 |
| 주철 COVER (s51~s53) | 호칭 | 원형 A→`dim_d`, 외경→`dim_d_o`, 내경→`dim_d_i`, 높이·H→`dim_h` | 재질(회주철·구상흑연주철), 인도·차도 |
| 보도·옹벽 블록 (s54~s71) | | 가로→`dim_w`, 세로→`dim_v`, 두께(높이)→`dim_t` | 투수/불투수, 색상 |
| 콘크리트·인조 경계석 (s72·s73) | 호칭(A …) | 윗면→`dim_w_t`, 밑면→`dim_w_b`, 높이→`dim_h`, 길이→`dim_l` | 구분(보차도 등) |
| 화강 경계석 (s74) | | 폭→`dim_w`, 높이→`dim_h`, 깊이(1000)→`dim_l` | 직선·곡선 |
| 가로수보호틀 (s75) | 품명(KCI-H1 …) | 보호판 규격 → `dim_w`·`dim_v`·`dim_h` | 품명(형), 받침틀 규격 |
| 파형강관 (s76) | | 관경→`dim_d_i`, 두께→`dim_t` | 파형(60x10 …) |
| 용접철망 (s77) | 품번(#10) | 품번→`spec_wire_no`, 선경→`dim_wire_d`, A×B→`dim_w`·`dim_v`, 망눈간격→`dim_mesh` | |
| 건널목수로관 (w1) | 호칭(500C …) | 폭 a→`dim_w`, h→`dim_depth`, 두께 d→`dim_t`, 길이 l→`dim_l` | 구분(3종), 중량 |
| 스텐볼라드 - HK (w2) | | Ø→`dim_d`, 높이→`dim_h`, 두께(2T)→`dim_t`, U형 폭→`dim_w` | 매립식·이동식·앙카식, U형 |
| 탄성볼라드 - HK (w3) | | Ø→`dim_d`, 높이→`dim_h` | 실리콘·HK-2, 설치방식 |
| 석재볼라드 - SCG (w4) | | (주문제작 — 치수 없음) | 석종·타입·마감 |

## 3. 확인이 필요한 가정

- 암거 T1·T2·T3 을 상판·측벽·하판 두께로 보았다(카탈로그 도면으로 확인 필요).
- 스텐볼라드 U형 'Ø101.6 x 600mm x 1200mm x 2T' 는 높이 600·폭 1200 으로 보았다(사진 기준).
- 마진율 복사 기준(대분류 → 기존 카테고리)은 `20261110_syc_categories.sql` [5] 주석 참고. 2026-11-10 운영 값은 모두 개인 20 / 기업 10.
