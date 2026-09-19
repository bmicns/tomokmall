-- 회원 주소 컬럼 추가 (2026-06-22)
ALTER TABLE users
  ADD COLUMN address_zip     VARCHAR(10)  DEFAULT NULL AFTER phone,
  ADD COLUMN address         VARCHAR(255) DEFAULT NULL AFTER address_zip,
  ADD COLUMN address_detail  VARCHAR(255) DEFAULT NULL AFTER address;
