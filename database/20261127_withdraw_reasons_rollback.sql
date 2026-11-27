-- 되돌리기: 20261127_withdraw_reasons.sql — 탈퇴 사유 표를 지운다(모은 사유도 함께 사라짐, 필요하면 먼저 phpMyAdmin 내보내기).
-- 코드는 표가 없으면 사유를 저장하지 않고 탈퇴만 처리하므로, 코드를 먼저 되돌리지 않아도 탈퇴는 동작한다.
DROP TABLE IF EXISTS withdraw_reasons;
