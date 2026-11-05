# 거래 제조사 카탈로그 받기 — 허락 기록·가져오기 순서 (2026-11-05)

> 목적: 사용자가 거래 제조사의 카탈로그(PDF·엑셀)와 사용 허락을 주면, 그 자료의 **규격·원문 문장**을 AI 상담 근거(`material_info`, '○○ 자료에 따르면')로 쓴다.
> 지금 승인 자료는 제조사 공개 페이지 수집분 14행뿐이고, 수로관·맨홀·PE/PVC관·경계석·보강토는 승인 문장이 0건이다(`../explanation-data-2026-11-05.md` 4장).
> 운영 반영은 보류 중이다(2026-11-05 사용자 지시). 아래 1~5단계는 로컬 테스트 DB 까지만, 6단계는 승인 후.

## 1. 보내 주실 것 (제조사마다)

| 항목 | 내용 | 비고 |
|---|---|---|
| 카탈로그 파일 | 글자 PDF(복사 가능한 PDF) 또는 엑셀(.xlsx)·CSV | 스캔 이미지 PDF 는 글자를 뽑지 못한다. HWP 는 PDF 로 저장해서 |
| 제조사 정식 이름 | 상담에서 '○○ 자료에 따르면' 으로 나올 이름 | 예: 동양콘크리트산업(주) |
| 허락 날짜 | 회신·계약 날짜 | |
| 허락 방식 | 메일 회신 / 공문 / 계약서 / 구두(날짜·직함 기록) | 원본은 사용자가 보관 — 저장소에 넣지 않는다 |
| 허락 범위 | 쓸 자료(규격·설명 문장·KS 표기), 쓰는 곳(AI 상담 인용·출처 표시), 기간 | 이미지·가격은 범위에 있어도 쓰지 않는다 |
| 허락한 분 | 직함만(예: 영업팀장) | 이름·휴대전화·이메일은 적지 않는다(개인정보) |

허락 요청 글 틀: [`permission-request-template.md`](permission-request-template.md)

## 2. 등록부에 출처 추가 (`data/external/sources.json`)

- 제조사 **공개 페이지 수집 키와 다른 키**로 추가한다(예: `dyhumepipe_catalog`). 허락 근거가 다르고, 같은 키면 `import-crawled.php` 가 출처별 최신 JSON 1개만 읽어 한쪽 자료가 빠진다.
- `permission: "allowed"` 와 `basis` 를 함께 적는다. `basis` 가 비었거나 허락 날짜가 없으면 등록하지 않는다.

```json
{
  "key": "dyhumepipe_catalog",
  "name": "동양콘크리트산업(주) 제품 카탈로그(허락)",
  "maker": "동양콘크리트산업(주)",
  "domain": "",
  "kind": "제조사 제공 파일",
  "priority": 0,
  "permission": "allowed",
  "basis": "2026-11-0X 메일 회신(영업팀장) — 카탈로그 규격·설명 문장·KS 표기를 AI 상담 인용(출처 표시)에 사용 허락, 기간 정함 없음. 이미지·가격 미사용",
  "methods": ["file"],
  "conditions": ["출처 표시: 제조사명·카탈로그 쪽", "이미지·가격 미사용", "요청 시 즉시 제외"]
}
```

## 3. 가져오기 — PDF (`scripts/external/catalog-extract.php`, 2026-11-05 추가)

```bash
php scripts/external/catalog-extract.php --source=dyhumepipe_catalog --file=카탈로그.pdf --dry-run
```

```bash
php scripts/external/catalog-extract.php --source=dyhumepipe_catalog --file=카탈로그.pdf --file=규격표.pdf
```

- 쪽마다 ① 제품 제목(자재 종류가 읽히는 40자 이하 줄) ② 규격 줄(치수 표기가 있는 표 행 — 바로 위 머리글을 칸마다 붙임: `내경 D100 / 외경 118mm`) ③ 원문 문장(용도·특징·주의·인증·강도 — 공개 페이지 수집기와 같은 분류)을 뽑는다.
- 출처 표시는 `catalog:<파일명>#p<쪽>`. 상담 화면 '참고 자료 출처'에는 `제조사명 · 제조사 카탈로그 N쪽` 으로 나온다(링크 없음).
- **저장하지 않는 것**: 가격 칸(머리글 단가·가격, 값 ○○원)·가격 문장, 이미지, 제목 없는 쪽(회사 소개·인증 현황 등), 고쳐 쓴 문장(원문 그대로만).
- 허락 전(`pending`·`blocked`) 출처는 `--dry-run` 분석만 된다. 레코드가 0건이면 저장하지 않는다(이전 자료를 가리지 않게).
- PDF 처리는 `pdftotext`(poppler-utils)가 필요하다. 이 클라우드 세션에는 있으므로 **파일을 세션에 올려 주시면 여기서 처리**한다.

## 4. 가져오기 — 엑셀·CSV (`scripts/external/import.php`)

```bash
php scripts/external/import.php --show-columns --file=규격표.xlsx
```

```bash
php scripts/external/import.php --source=dyhumepipe_catalog --file=규격표.xlsx --license-checked --md=보고서.md
```

- 머리글 자동 인식: 품명·규격·제조사·재질·용도·인증(KS)·비고. 모르는 열은 버린다.
- **가격 열은 지우고 가져온다.** `단가·가격` 열은 외부 참고가(`external_products.ref_price`, 가격 데이터 — CLAUDE.md 12-2)로 들어가므로, 받은 파일에서 그 열을 뺀 사본으로 가져온다(이 세션에서 처리).
- 엑셀 규격표만으로는 상담 문장(facts)이 생기지 않는다(규격·매칭 후보만). 엑셀에 **설명 문장 열**(특징·용도·주의)이 있으면 실제 파일 구조를 본 뒤 그 열을 원문 문장으로 옮기는 변환을 추가한다(받은 뒤 할 일).

## 5. 정리·검토·승인 (로컬 테스트 DB `*_test`)

```bash
php scripts/external/import-crawled.php --only=dyhumepipe_catalog
```

```bash
php scripts/external/approve-crawled.php --only=dyhumepipe_catalog
```

- `approval-candidates.md` 에서 문장 단위로 확인한다(승인 후보 ✅ / 보류 ⏸ + 사유, 행 점검 ⚠, 우리 상품 매칭 후보와 다른 점). 허락받은 출처는 '이용 허락 기록: (basis)' 이 함께 나온다.
- 검토 후 승인: `php scripts/external/approve-crawled.php --approve=dyhumepipe_catalog` (빼고 싶은 문장은 `--exclude='문장 일부'`).
- 우리 상품 매칭 확정은 따로: `php scripts/external/import.php --confirm=외부ID:상품ID`.

## 6. 운영 반영 (보류 — 승인 후)

`export-approved.php --expect=<승인 행 수>` → 리허설(`scripts/testdb/rehearse-prod-apply.sh --real-export=…`) → 운영 DB 반영 승인(관리자 화면 또는 phpMyAdmin).

## 7. 확인한 것 (2026-11-05, 이 세션)

- 단위 테스트 `php tests/catalog_extract.test.php` 27건 통과. 입력은 가상 카탈로그 글이다(실제 제조사 자료 아님): 쪽·제목·가격 줄, 머리글 붙은 규격, 원문 문장 이어 붙이기, 가격 미저장, 허락 확인(pending 거부·dry-run·엑셀 안내·HWP·0건 미저장·여러 파일 합침).
- 실제 PDF 경로: reportlab 으로 만든 5쪽짜리 가상 카탈로그 PDF(`[테스트용 가상 카탈로그]`, 세션 scratchpad — 저장소에 넣지 않음)를 `pdftotext -layout` 으로 처리했다. 레코드 5건(인터로킹 2·PE관 2·수로관 1)이 나왔고 회사 소개·인증 현황 쪽과 가격 칸·가격 문장은 빠졌다.
- 이어지는 과정: 임시 스키마(`catalog_pipeline_test`, 상품 1509건 복사)에서 `import-crawled.php` → `approve-crawled.php` 보고서·승인(4행 승인, 치수 판독 ⚠ 1행은 대기 유지) → `material_info` 결과(문장·제조사·`catalog:…#p2`) → `export-approved.php` 이관 SQL 생성(적용 안 함). 끝난 뒤 임시 스키마와 출력은 지웠다.
- 한계: 실제 제조사 카탈로그는 아직 받지 않았다. 표가 그림으로 된 PDF, 칸이 공백 1칸으로 붙은 표, 여러 단 편집은 제목·규격 판독이 틀릴 수 있다 — 받은 파일로 `--dry-run` 결과를 먼저 보고 필요하면 규칙을 고친다.
