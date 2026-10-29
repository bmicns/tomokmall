# 외부 상품 데이터 검증 구역(staging) 설계

목표: 외부에서 확보한 상품 데이터를 **운영 상품 DB(products)에 바로 넣지 않고**, 출처·이용 권한·품질 검증을 통과한 것만 사람이 승인해 반영한다.

## 1. 흐름

```
[확보] 허락·제휴·공개 API·제조사 카탈로그
   ↓ (원본 그대로 보관, 이미지·설명문은 보관만 하고 게시하지 않음)
[검증 구역] 저장소 data/staging/<source>/<수집일>/raw.json + normalized.json
   ↓ scripts/data-quality/check_products.php (개인정보·중복·규격·단위·가격·필드 오염·기존 상품 대조)
[검증 보고서] report.md + issues.csv  →  담당자 검토(승인/보류/제외 표시)
   ↓ 승인분만
[반영 SQL 생성] 승인 목록 → INSERT/UPDATE SQL 파일 (운영 실행은 사용자 승인 후 phpMyAdmin)
```

## 2. 왜 DB 테이블이 아니라 파일로 시작하나

- 운영 DB 구조 변경 없이 바로 시작할 수 있음(승인 부담 최소)
- Git으로 변경 이력·검토 기록이 남음. 단, **개인정보·원본 이미지·저작물 설명문은 커밋하지 않음**(`raw/`는 .gitignore)
- 양이 늘면(수천 건·주기 수집) 그때 `product_staging` 테이블로 옮김 — 아래 3장 초안

## 3. (2단계) staging 테이블 초안 — 실행 안 함

```sql
CREATE TABLE product_staging (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source          VARCHAR(40)  NOT NULL COMMENT '출처 키 (ktomok, 제조사명 등)',
  source_url      VARCHAR(500) NULL,
  license_status  VARCHAR(20)  NOT NULL DEFAULT 'unknown' COMMENT 'unknown/permitted/partner/public_api/denied',
  license_note    VARCHAR(500) NULL COMMENT '허락 근거(메일·계약·약관 조항)',
  fetched_at      DATETIME     NOT NULL,
  name            VARCHAR(200) NOT NULL,
  spec_raw        TEXT         NULL,
  dim_w_mm INT NULL, dim_l_mm INT NULL, dim_t_mm INT NULL, piece_len_mm INT NULL,
  material        VARCHAR(100) NULL,
  unit            VARCHAR(20)  NULL COMMENT '정규화 단위: 개/본/㎡/m/조',
  price           INT          NULL,
  price_basis     VARCHAR(50)  NULL COMMENT '판매가/참고가/조달단가 등',
  matched_product_id INT UNSIGNED NULL COMMENT '기존 products 대조 결과',
  issues_json     TEXT         NULL COMMENT '검증 결과',
  review_status   VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending/approved/rejected',
  reviewed_by     VARCHAR(100) NULL,
  reviewed_at     DATETIME     NULL,
  INDEX idx_source (source), INDEX idx_review (review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 4. 반영 규칙

- `license_status`가 permitted/partner/public_api 인 것만 반영 후보
- 이미지: 허락받은 것만 자체 서버에 저장 후 사용. 외부 URL 핫링크 금지
- 상품 설명문: 원문 복사 대신 규격·재질·용도 같은 사실 정보로 새로 작성
- 가격: 외부 판매가는 **참고가**로만 쓰고 우리 판매가로 자동 반영하지 않음
- 반영 SQL에는 `external_source`, `external_id`, `source_url` 기록(기존 칸 재사용)
