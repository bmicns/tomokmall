-- 규격 통일(S26): 숫자 없는 규격도 밑줄 없이 3단(모델 코드·옵션)으로 — 사용자 요청 2026-11-24. 치수·가격·category_id 변경 없음.
-- 되돌리기: UPDATE products SET spec_code=NULL, spec_option=NULL, spec_dims_src=NULL WHERE id IN (473,474,475,476,477,478,502,543,943,944);
UPDATE products SET spec_code = '5호사', spec_option = '백규사_1.0톤', spec_dims_src = '5호사(백규사)_1.0톤' WHERE id = 473 AND spec = '규격: 5호사(백규사)_1.0톤 | 중량: 1,000kg | 단위: 포' AND spec_dims_src IS NULL;
UPDATE products SET spec_code = '5호사', spec_option = '백규사_1.3톤', spec_dims_src = '5호사(백규사)_1.3톤' WHERE id = 474 AND spec = '규격: 5호사(백규사)_1.3톤 | 중량: 1,300kg | 단위: 포' AND spec_dims_src IS NULL;
UPDATE products SET spec_code = '5호사', spec_option = '백규사_1.4톤', spec_dims_src = '5호사(백규사)_1.4톤' WHERE id = 475 AND spec = '규격: 5호사(백규사)_1.4톤 | 중량: 1,400kg | 단위: 포' AND spec_dims_src IS NULL;
UPDATE products SET spec_code = '5호사', spec_option = '백규사_1.5톤', spec_dims_src = '5호사(백규사)_1.5톤' WHERE id = 476 AND spec = '규격: 5호사(백규사)_1.5톤 | 중량: 1,500kg | 단위: 포' AND spec_dims_src IS NULL;
UPDATE products SET spec_code = '5호사', spec_option = '황규사_1.3톤', spec_dims_src = '5호사(황규사)_1.3톤' WHERE id = 477 AND spec = '규격: 5호사(황규사)_1.3톤 | 중량: 1,300kg | 단위: 포' AND spec_dims_src IS NULL;
UPDATE products SET spec_code = '5호사', spec_option = '황규사_1.4톤', spec_dims_src = '5호사(황규사)_1.4톤' WHERE id = 478 AND spec = '규격: 5호사(황규사)_1.4톤 | 중량: 1,400kg | 단위: 포' AND spec_dims_src IS NULL;
UPDATE products SET spec_code = '축조블록집게', spec_option = NULL, spec_dims_src = '축조블록집게' WHERE id = 502 AND spec = '규격: 축조블록집게 | 단위: 개' AND spec_dims_src IS NULL;
UPDATE products SET spec_code = '보강토집게', spec_option = NULL, spec_dims_src = '보강토집게' WHERE id = 543 AND spec = '규격: 보강토집게 | 단위: 조' AND spec_dims_src IS NULL;
UPDATE products SET spec_code = '노아클립', spec_option = NULL, spec_dims_src = '노아클립' WHERE id = 943 AND spec = '규격: 노아클립 | 단위: 개' AND spec_dims_src IS NULL;
UPDATE products SET spec_code = '노아클립', spec_option = 'SUS', spec_dims_src = 'SUS_노아클립' WHERE id = 944 AND spec = '규격: SUS_노아클립 | 단위: 개' AND spec_dims_src IS NULL;
