START TRANSACTION;
INSERT IGNORE INTO categories (name, slug, sort_order, is_active) VALUES
('1련암거', 'dacity-1010', 1000, 1);

INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '0.8*0.8*2.0_130T*130T_구', '동아산업', '중량/단위: 2,640kg / 본 | 상차수량: 10 본 | 운반비예상: 본당 30,000원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581996971', 'https://www.dacity.kr/shop/item.php?it_id=1581996971&ca_id=1010&page=1&page=1', 'http://dacity.kr/data/item/10/1010/1581996969/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '0.8*0.8*2.0_160T*160T', '동아산업', '중량/단위: 3,300kg / 본 | 상차수량: 8 본 | 운반비예상: 본당 37,500원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581990671', 'https://www.dacity.kr/shop/item.php?it_id=1581990671&ca_id=1010&page=1&page=1', 'https://dacity.kr/data/item/10/1010/1581990607/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '0.8*0.8*3.0_130T*130T_구', '동아산업', '중량/단위: 3,966kg / 본 | 상차수량: 7 본 | 운반비예상: 본당 42,900원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581990670', 'https://www.dacity.kr/shop/item.php?it_id=1581990670&ca_id=1010&page=1&page=1', 'http://www.dacity.kr/data/item/10/1010/1581990606/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '1.0*0.8*2.0_130T*130T_구', '동아산업', '중량/단위: 2,900kg / 본 | 상차수량: 9 본 | 운반비예상: 본당 33,300원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581990672', 'https://www.dacity.kr/shop/item.php?it_id=1581990672&ca_id=1010&page=1&page=1', 'https://dacity.kr/data/item/10/1010/1581990608/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '1.0*0.8*2.0_160T*160T', '동아산업', '중량/단위: 3,615kg / 본 | 상차수량: 7 본 | 운반비예상: 본당 42,900원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581990673', 'https://www.dacity.kr/shop/item.php?it_id=1581990673&ca_id=1010&page=1&page=1', 'http://www.dacity.kr/data/item/10/1010/1581990609/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '1.0*1.0*2.0_130T*130T_구', '동아산업', '중량/단위: 3,160kg / 본 | 상차수량: 8 본 | 운반비예상: 본당 37,500원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581990674', 'https://www.dacity.kr/shop/item.php?it_id=1581990674&ca_id=1010&page=1&page=1', 'https://www.dacity.kr/data/item/10/1010/1581990610/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '1.0*1.0*2.0_160T*160T', '동아산업', '중량/단위: 3,935kg / 본 | 상차수량: 7 본 | 운반비예상: 본당 42,900원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581990675', 'https://www.dacity.kr/shop/item.php?it_id=1581990675&ca_id=1010&page=1&page=1', 'https://dacity.kr/data/item/10/1010/1581990611/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '1.0*1.5*2.0_130T*130T_구', '동아산업', '중량/단위: 3,810kg / 본 | 상차수량: 7 본 | 운반비예상: 본당 42,900원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581997641', 'https://www.dacity.kr/shop/item.php?it_id=1581997641&ca_id=1010&page=1&page=1', 'https://dacity.kr/data/item/10/1010/1581990612/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '1.2*1.0*2.0_130T*130T_구', '동아산업', '중량/단위: 3,420kg / 본 | 상차수량: 8 본 | 운반비예상: 본당 37,500원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581990676', 'https://www.dacity.kr/shop/item.php?it_id=1581990676&ca_id=1010&page=1&page=1', 'http://dacity.kr/data/item/10/1010/1581990613/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, '1.2*1.2*2.0_130T*130T_구', '동아산업', '중량/단위: 3,680kg / 본 | 상차수량: 6 본 | 운반비예상: 본당 50,000원 예상 (수도권, 충청, 강원 일부) 25톤 &raquo; 5톤축 변경 시 40% 추가', 'PC공법으로 조인트 개소의최적와 제품의 고품질 실현,기본 단면을 일체화하고내·외부의 하중을 받을 수 있도록처리하여 내·외부 안정성을 확보합니다.',
    0, 0, 0, 'dacity', '1581990677', 'https://www.dacity.kr/shop/item.php?it_id=1581990677&ca_id=1010&page=1&page=1', 'http://www.dacity.kr/data/item/10/1010/1581990614/pcbox_1r_1.jpg', 1
FROM categories c
WHERE c.slug = 'dacity-1010'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;
COMMIT;