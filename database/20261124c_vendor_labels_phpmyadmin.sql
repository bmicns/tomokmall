-- 업체 상품 규격 정리(S33 보완): 이름표 없는 분류에 이름표 추가 — 2026-11-24 (사용자 요청 '이름표안도 같이 만들어줘')
-- 실행: phpMyAdmin 에서 사용자가 직접. 가격 칸·category_id·상품 행 변경 없음(이름표 표 category_spec_axes 에 행 추가만).
-- 대상 분류는 지금 이름표가 0행이다(아래 ① 확인 결과가 0 이어야 함). 실행 뒤 관리자 제품관리에서 업체 상품 하나를 저장하면 업체 상품이 다시 반영되며 칸이 채워진다.
-- 확인 필요(사용자 확인 후 실행): 1051 휀스기초 = 상부폭·하부폭·높이(다른 안: 가로·세로·높이), 227 통신맨홀몰드 = 가로·세로. 1060 주철뚜껑(사각)은 분류 연결을 진행할 때만 쓰임(케이토목 3개는 값이 이미 있음).

-- ① 확인(0 이어야 함)
SELECT COUNT(*) FROM category_spec_axes WHERE category_id IN (215,216,217,218,219,220,221,222,223,224,225,226,227,228,229,1050,1051,1052,1060);

-- ② 이름표 추가
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (215, 1, 'w', '폭', 'W', 1, 'm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (215, 2, 'h', '높이', 'H', 1, 'm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (215, 3, 'l', '길이', 'L', 1, 'm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (216, 1, 'w', '폭', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (216, 2, 'depth', '깊이', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (217, 1, 'w', '폭', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (217, 2, 'depth', '깊이', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (218, 1, 'w', '폭', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (218, 2, 'depth', '깊이', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (219, 1, 'w', '폭', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (219, 2, 'depth', '깊이', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (220, 1, 'w', '폭', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (220, 2, 'depth', '깊이', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (221, 1, 'w', '폭', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (221, 2, 'depth', '깊이', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (222, 1, 'd_i', '내경', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (223, 1, 'd_i', '적용 관경', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (224, 1, 'd_i', '내경', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (224, 2, 'l', '길이', 'L', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (225, 1, 'w', '가로', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (225, 2, 'v', '세로', 'V', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (225, 3, 'h', '높이', 'H', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (226, 1, 'd_i', '내경', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (226, 2, 'h', '높이', 'H', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (227, 1, 'w', '가로', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (227, 2, 'v', '세로', 'V', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (228, 1, 'w', '가로', 'W', 1, 'mm', 1, 'loose');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (228, 2, 'h', '높이', 'H', 1, 'mm', 1, 'loose');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (229, 1, 'd_i', '내경', 'D', 1, 'mm', 1, 'loose');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (229, 2, 'h', '높이', 'H', 1, 'mm', 1, 'loose');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1050, 1, 'd_i', '가지관 내경', 'D', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1051, 1, 'w_t', '상부폭', 'WT', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1051, 2, 'w_b', '하부폭', 'WB', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1051, 3, 'h', '높이', 'H', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1052, 1, 'w', '폭', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1052, 2, 'l', '길이', 'L', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1052, 3, 't', '두께', 'T', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1060, 1, 'w', '가로', 'W', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1060, 2, 'v', '세로', 'V', 1, 'mm', 1, 'default');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt) VALUES (1060, 3, 'h', '높이', 'H', 1, 'mm', 1, 'default');

-- ③ 되돌리기(필요할 때만)
-- DELETE FROM category_spec_axes WHERE category_id IN (215,216,217,218,219,220,221,222,223,224,225,226,227,228,229,1050,1051,1052,1060);
