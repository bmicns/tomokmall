-- Stage 2: 카테고리별 상담 가이드 테이블
CREATE TABLE IF NOT EXISTS category_consult_guide (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_key VARCHAR(50)  NOT NULL UNIQUE COMMENT '카테고리 식별자 (grating, pe_pipe 등)',
    title        VARCHAR(100) NOT NULL COMMENT '한글 카테고리명',
    guide_text   TEXT         NULL     COMMENT 'AI 프롬프트에 주입될 상세 가이드',
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order   INT          NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO category_consult_guide (category_key, title, guide_text, sort_order) VALUES
('grating', '그레이팅',
'【그레이팅 추가 가이드】
차량 통행 여부가 제품 선택의 핵심입니다.
- 차량(지게차·트럭·승용차 등) 통행 → 중하중그레이팅 시리즈. 플라스틱 제품 절대 추천 금지.
- 보행자 전용(인도·공원·농수로) → 그레이팅수로용 또는 안전그레이팅.
- 맨홀·집수정 위 설치 → 중하중그레이팅맨홀.
반드시 확인할 사항: ① 차량 종류·최대 톤수 ② 설치 면적(㎡) ③ 맨홀 유무', 1),

('pe_pipe', 'PE배수관',
'【PE배수관 추가 가이드】
용도에 따라 유공관(구멍 있음)과 무공관(구멍 없음)으로 구분됩니다.
- 농경지 지하 배수: 유공관 추천
- 도로 측구·건물 우수배수: 무공관 추천
- 주요 관경: 100mm, 150mm, 200mm, 250mm, 300mm
반드시 확인할 사항: ① 용도(농경지/도로/건물) ② 필요 연장(m) ③ 관경(mm) ④ 유공·무공 구분', 2),

('manhole', '맨홀·집수정',
'【맨홀·집수정 추가 가이드】
차량 통행 여부에 따라 재질과 등급이 달라집니다.
- 차량 통행 구간: 철근콘크리트 또는 중하중 제품 필수. 플라스틱 맨홀 불가.
- 우수(빗물) 배수: 집수정 + 그레이팅맨홀 세트 구성 일반적.
- 주요 규격: 450mm, 600mm, 900mm, 1200mm (원형 기준)
반드시 확인할 사항: ① 용도(우수/오수) ② 차량 통행 여부·톤수 ③ 규격(mm)·수량', 3),

('culvert', '수로관·암거',
'【수로관·암거 추가 가이드】
형태와 하중 조건에 따라 제품이 달라집니다.
- U형수로관: 개방형 농수로·측구 배수에 적합
- 암거(BOX형·원형): 도로 하부 통과, 복개 구조
- 차량 하중을 받는 경우 반드시 하중 등급·토피(매설 깊이) 확인 필요
반드시 확인할 사항: ① 연장 길이(m) ② 차량 통행 여부 ③ U형/암거 구분 ④ 필요 규격', 4),

('pavement', '보도블록·경계석',
'【보도블록·경계석 추가 가이드】
용도에 맞는 블록 종류와 두께 선택이 중요합니다.
- 보도(인도): 보도블록·점자블록 (두께 60mm 이상)
- 광장·주차장 진입: 인터로킹 또는 중하중 보차도블록 (두께 80mm 이상)
- 경계석: L형(도로 경계)·I형(보도 경계) 구분
반드시 확인할 사항: ① 용도(인도/광장/주차장) ② 면적(㎡) ③ 경계석 필요 여부 ④ 차량 진입 여부', 5);
