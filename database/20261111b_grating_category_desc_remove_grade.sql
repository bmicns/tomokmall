-- 그레이팅 분류 설명 4개에서 하중 등급 문구 빼기 (2026-11-11, 사용자 요청)
--
-- 왜: 분류 설명의 '일반 등급 … (상품명에 중하중·안전중하중 표기 없음)'이 일부 상품 상세 설명('차량 통행 가능·T-25', 사용자 결정으로 유지)과 어긋나 보임.
-- 범위: categories.description 4행(116·117·128·129) — 20261111_grating_category_descriptions.sql 로 넣은 문구 그대로일 때만 바꾼다(그 사이 고쳤으면 0행).
--       중하중·안전중하중 분류(124~127)는 분류 이름 그대로의 등급이라 그대로 둔다.
-- 되돌리기: 20261111_grating_category_descriptions.sql 의 116·117·128·129 문구로 UPDATE(이 파일의 새 문구일 때만).
-- @verify SELECT id, description FROM categories WHERE id IN (116, 117, 128, 129) ORDER BY id

UPDATE categories SET description = '배수로(수로) 위에 덮는 스틸 그레이팅 전 규격을 공급합니다.' WHERE id = 116 AND description = '배수로(수로) 위에 덮는 일반 등급 스틸 그레이팅(상품명에 중하중·안전중하중 표기 없음) 전 규격을 공급합니다.';
UPDATE categories SET description = '집수정(맨홀) 위에 덮는 사각 스틸 그레이팅 전 규격을 공급합니다.' WHERE id = 117 AND description = '집수정(맨홀) 위에 덮는 일반 등급 사각 스틸 그레이팅(상품명에 중하중·안전중하중 표기 없음) 전 규격을 공급합니다.';
UPDATE categories SET description = '배수로(수로) 위에 덮는 우수용 디자인 그레이팅 전 규격을 공급합니다.' WHERE id = 128 AND description = '배수로(수로) 위에 덮는 우수용 일반 등급 디자인 그레이팅(상품명에 중하중·안전중하중 표기 없음) 전 규격을 공급합니다.';
UPDATE categories SET description = '집수정(맨홀) 위에 덮는 우수용 디자인 그레이팅 전 규격을 공급합니다.' WHERE id = 129 AND description = '집수정(맨홀) 위에 덮는 우수용 일반 등급 디자인 그레이팅(상품명에 중하중·안전중하중 표기 없음) 전 규격을 공급합니다.';
