-- 그레이팅 하위 분류 20개 설명 채우기 (2026-11-11, 사용자 요청)
--
-- 왜: 하위 분류 설명이 모두 비어 있어, 상위 '그레이팅' 설명('평판·수로용·U타입 낮게형 스틸 그레이팅 및 트렌치 커버')이
--     집수정(맨홀)용·메탈망 등 모든 그레이팅 카드의 분류 설명으로 쓰였다 → AI 상담이 모든 제품을 '수로용'처럼 설명(운영 상담 #193~#195),
--     분류 화면(products.php)도 상위 설명을 보여 줌.
-- 근거: 분류 이름과 그 분류 상품의 등록 규격 표기(체크무늬·벌집그레이팅·삼각안전·우수·오수·데크·일반·엠보싱)만 사용.
--       하중 등급은 상품명 표기(중하중·안전중하중)로만 적고 톤수·성능은 쓰지 않는다. 상품이 없는 분류(119·133·134)는 '공급' 문구 없이 이름 풀이만.
-- 범위: categories.description 20행(설명이 비어 있는 행만). 가격 칸·다른 칸 변경 없음.
-- 되돌리기: UPDATE categories SET description = NULL WHERE id BETWEEN 116 AND 135 AND parent_id = 266;  (적용 전 값은 모두 NULL/빈 값)
-- @verify SELECT id, name, description FROM categories WHERE parent_id = 266 ORDER BY id

UPDATE categories SET description = '배수로(수로) 위에 덮는 일반 등급 스틸 그레이팅(상품명에 중하중·안전중하중 표기 없음) 전 규격을 공급합니다.' WHERE id = 116 AND name = '그레이팅수로용' AND (description IS NULL OR description = '');
UPDATE categories SET description = '집수정(맨홀) 위에 덮는 일반 등급 사각 스틸 그레이팅(상품명에 중하중·안전중하중 표기 없음) 전 규격을 공급합니다.' WHERE id = 117 AND name = '그레이팅맨홀(집수정)용' AND (description IS NULL OR description = '');
UPDATE categories SET description = '플륨관(수로관)에 쓰는 날개형(U-TYPE) 스틸 그레이팅 전 규격을 공급합니다.' WHERE id = 118 AND name = '플륨관용(날개형U-TYPE)' AND (description IS NULL OR description = '');
UPDATE categories SET description = '톱니 모양 바(세레트바)를 쓴 스틸 그레이팅입니다.' WHERE id = 119 AND name = '톱니바그레이팅(세레트바)' AND (description IS NULL OR description = '');
UPDATE categories SET description = '벌집형·삼각안전형 스틸 안전그레이팅 전 규격을 공급합니다.' WHERE id = 120 AND name = '안전그레이팅' AND (description IS NULL OR description = '');
UPDATE categories SET description = '악취차단·오물망 스틸 그레이팅 전 규격을 공급합니다.' WHERE id = 121 AND name = '악취차단오물망그레이팅' AND (description IS NULL OR description = '');
UPDATE categories SET description = 'EX메탈망(확장 금속망) 스틸 그레이팅 전 규격을 공급합니다.' WHERE id = 122 AND name = 'EX메탈망그레이팅' AND (description IS NULL OR description = '');
UPDATE categories SET description = '구멍을 뚫은(타공) 엠보싱 스틸 판 그레이팅 전 규격을 공급합니다.' WHERE id = 123 AND name = '스틸타공그레이팅' AND (description IS NULL OR description = '');
UPDATE categories SET description = '배수로(수로) 위에 덮는 중하중 등급 스틸 그레이팅 전 규격을 공급합니다.' WHERE id = 124 AND name = '중하중그레이팅수로용' AND (description IS NULL OR description = '');
UPDATE categories SET description = '집수정(맨홀) 위에 덮는 중하중 등급 사각 스틸 그레이팅 전 규격을 공급합니다.' WHERE id = 125 AND name = '중하중그레이팅맨홀(집수정)용' AND (description IS NULL OR description = '');
UPDATE categories SET description = '배수로(수로) 위에 덮는 안전중하중 등급 스틸 그레이팅(체크무늬 포함) 전 규격을 공급합니다.' WHERE id = 126 AND name = '안전중하중그레이팅수로용' AND (description IS NULL OR description = '');
UPDATE categories SET description = '집수정(맨홀) 위에 덮는 안전중하중 등급 사각 스틸 그레이팅 전 규격을 공급합니다.' WHERE id = 127 AND name = '안전중하중그레이팅맨홀(집수정)용' AND (description IS NULL OR description = '');
UPDATE categories SET description = '배수로(수로) 위에 덮는 우수용 일반 등급 디자인 그레이팅(상품명에 중하중·안전중하중 표기 없음) 전 규격을 공급합니다.' WHERE id = 128 AND name = '디자인그레이팅수로용' AND (description IS NULL OR description = '');
UPDATE categories SET description = '집수정(맨홀) 위에 덮는 우수용 일반 등급 디자인 그레이팅(상품명에 중하중·안전중하중 표기 없음) 전 규격을 공급합니다.' WHERE id = 129 AND name = '디자인그레이팅맨홀(집수정)용' AND (description IS NULL OR description = '');
UPDATE categories SET description = '원형(지름 D 표기) 디자인 그레이팅(우수·오수용) 전 규격을 공급합니다.' WHERE id = 130 AND name = '원형디자인' AND (description IS NULL OR description = '');
UPDATE categories SET description = '칼라(색상) 맨홀 뚜껑(우수·오수용) 전 규격을 공급합니다.' WHERE id = 131 AND name = '칼라맨홀뚜껑' AND (description IS NULL OR description = '');
UPDATE categories SET description = '나무 둘레 바닥을 덮는 스틸 수목보호판 전 규격을 공급합니다.' WHERE id = 132 AND name = '수목보호판' AND (description IS NULL OR description = '');
UPDATE categories SET description = '스테인리스(SUS) 그레이팅입니다.' WHERE id = 133 AND name = 'SUS그레이팅' AND (description IS NULL OR description = '');
UPDATE categories SET description = '구멍을 뚫은(타공) 스테인리스(SUS) 그레이팅입니다.' WHERE id = 134 AND name = 'SUS그레이팅(타공)' AND (description IS NULL OR description = '');
UPDATE categories SET description = '알루미늄 그레이팅(일반형·데크형) 전 규격을 공급합니다.' WHERE id = 135 AND name = '알루미늄그레이팅' AND (description IS NULL OR description = '');
