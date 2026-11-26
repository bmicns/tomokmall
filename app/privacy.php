<?php
declare(strict_types=1);
/**
 * 개인정보 파기 — 회원 탈퇴(A안)·견적 개인정보 1년 자동 정리 (사용자 결정 2026-11-26, 개인정보 처리방침 제2조·제5조)
 *
 * 탈퇴(A안): 계정은 행을 지우지 않고 잠근다(주문·견적·이력과의 연결이 끊어지지 않게).
 *   - users: 이름 '탈퇴회원', 이메일 = 다시 가입할 수 있게 겹치지 않는 가짜 주소, 비밀번호 = 아무도 모르는 값,
 *            전화·회사명·주소 NULL, is_active 0  (role·가입일·마지막 로그인은 그대로)
 *   - 그 회원의 견적: 이름·연락처·이메일·회사명·현장 주소·요청 사항을 지움 — 운임·할인·품목 단가 등 금액 칸은
 *            건드리지 않는다(P0-4)
 *   - 현장(sites)·현장 견적 연결(site_orders)·관심상품·재주문 알림·이전 상담(ai_sessions) 삭제
 *   - 상담 로그(chat_logs)는 1년 보관(처리방침) — 회원 연결만 끊음(user_id NULL)
 *   - 상담원 문의 요청(consult_requests): 이름·연락처 지움 + pii_destroyed_at
 *   - 주문(orders·shipping_info)은 회원과 연결되지 않은 거래 기록이라 그대로(전자상거래법 5년)
 *   진행 중인 견적(확정·승인 뒤 납품 전)이 있으면 탈퇴를 막는다(이용약관 제6조 2항).
 *   직원·관리자 계정은 스스로 탈퇴하지 못한다(관리자가 계정 관리로).
 *
 * 견적 1년: 견적 요청일로부터 365일이 지난 견적의 개인정보 칸을 지운다(금액·품목·상태는 그대로).
 *   닷홈 예약 작업이 확실하지 않아 견적 저장·AI 상담 저장 때 가끔(100번에 1번), 한 번에 200행씩 정리한다.
 *
 * 함수:
 *  privacy_withdraw_blocker(int)   — 탈퇴를 막는 이유(없으면 null)
 *  privacy_withdraw_user(int)      — 탈퇴 처리(한 트랜잭션), 지운 행 수 반환
 *  privacy_estimate_purge(bool)    — 1년 지난 견적 개인정보 정리
 *  privacy_withdrawn_email(int)    — 탈퇴 계정의 가짜 이메일
 */

const PRIVACY_ESTIMATE_KEEP_DAYS = 365;     // 견적 요청 정보 보관(처리방침 제2조)
const PRIVACY_PURGE_PCT          = 1;       // 저장 100번에 1번쯤 정리
const PRIVACY_PURGE_MAX          = 200;     // 한 번에 정리하는 최대 행
const PRIVACY_ERASED_NAME        = '(파기)'; // 개인정보를 지운 견적의 이름 칸(NOT NULL)
const PRIVACY_WITHDRAWN_NAME     = '탈퇴회원';
const PRIVACY_WITHDRAW_ROLES     = ['customer', 'corporate'];
const PRIVACY_ACTIVE_ESTIMATE    = ['CONFIRMED', 'APPROVED'];  // 확정·승인 뒤 납품 전이면 진행 중

/** 탈퇴 계정 이메일 — 같은 이메일로 다시 가입할 수 있게 원래 주소를 남기지 않는다(.invalid 는 메일이 가지 않는 주소) */
function privacy_withdrawn_email(int $userId): string
{
    return 'withdrawn-' . $userId . '-' . bin2hex(random_bytes(6)) . '@deleted.invalid';
}

/** 테이블이 있는지(요청마다 한 번) — 로컬·운영의 테이블 구성이 달라도 탈퇴가 멈추지 않게 */
function privacy_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
        $st->execute(['t' => $table]);
        $cache[$table] = (int) $st->fetchColumn() > 0;
    }
    return $cache[$table];
}

/** 탈퇴를 막는 이유 — null 이면 탈퇴 가능 */
function privacy_withdraw_blocker(int $userId): ?string
{
    $pdo = db();
    if (!$pdo) {
        return '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.';
    }
    $st = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id');
    $st->execute(['id' => $userId]);
    $u = $st->fetch();
    if (!$u || (int) $u['is_active'] !== 1) {
        return '이미 탈퇴했거나 사용할 수 없는 계정입니다.';
    }
    if (!in_array((string) $u['role'], PRIVACY_WITHDRAW_ROLES, true)) {
        return '직원·관리자 계정은 여기서 탈퇴할 수 없습니다. 관리자에게 계정 정리를 요청해 주세요.';
    }
    $in = implode(',', array_fill(0, count(PRIVACY_ACTIVE_ESTIMATE), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM estimates WHERE user_id = ? AND status IN ($in) AND delivery_completed_at IS NULL");
    $st->execute(array_merge([$userId], PRIVACY_ACTIVE_ESTIMATE));
    if ((int) $st->fetchColumn() > 0) {
        return '진행 중인 견적·납품이 있어 지금은 탈퇴할 수 없습니다. 납품이 끝난 뒤 다시 시도하시거나 고객센터로 문의해 주세요.';
    }
    return null;
}

/**
 * 탈퇴 처리 — 성공하면 표별 처리 행 수, 실패하면 예외(트랜잭션 되돌림)
 * @return array<string,int>
 */
function privacy_withdraw_user(int $userId): array
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('db');
    }
    $done = [];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'UPDATE users SET name = :name, email = :email, password_hash = :pw, phone = NULL, company_name = NULL,
                    address_zip = NULL, address = NULL, address_detail = NULL, is_active = 0
              WHERE id = :id AND is_active = 1'
        );
        $st->execute([
            'name'  => PRIVACY_WITHDRAWN_NAME,
            'email' => privacy_withdrawn_email($userId),
            'pw'    => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
            'id'    => $userId,
        ]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('already_withdrawn');
        }
        $done['users'] = 1;

        // 견적: 개인정보 칸만(금액 칸은 건드리지 않음 — P0-4)
        $st = $pdo->prepare(
            'UPDATE estimates SET customer_name = :n, phone = \'\', email = NULL, company_name = NULL, site_address = NULL, memo = NULL
              WHERE user_id = :id'
        );
        $st->execute(['n' => PRIVACY_ERASED_NAME, 'id' => $userId]);
        $done['estimates'] = $st->rowCount();

        if (privacy_table_exists($pdo, 'sites')) {
            if (privacy_table_exists($pdo, 'site_orders')) {
                $st = $pdo->prepare('DELETE so FROM site_orders so JOIN sites s ON s.id = so.site_id WHERE s.user_id = :id');
                $st->execute(['id' => $userId]);
                $done['site_orders'] = $st->rowCount();
            }
            $st = $pdo->prepare('DELETE FROM sites WHERE user_id = :id');
            $st->execute(['id' => $userId]);
            $done['sites'] = $st->rowCount();
        }
        foreach (['favorite_products', 'reorder_alerts', 'ai_sessions'] as $t) {
            if (privacy_table_exists($pdo, $t)) {
                $st = $pdo->prepare("DELETE FROM $t WHERE user_id = :id");
                $st->execute(['id' => $userId]);
                $done[$t] = $st->rowCount();
            }
        }
        if (privacy_table_exists($pdo, 'chat_logs')) {
            $st = $pdo->prepare('UPDATE chat_logs SET user_id = NULL WHERE user_id = :id');
            $st->execute(['id' => $userId]);
            $done['chat_logs'] = $st->rowCount();
        }
        if (privacy_table_exists($pdo, 'consult_requests')) {
            $st = $pdo->prepare(
                'UPDATE consult_requests SET name = NULL, tel = NULL, user_id = NULL, pii_destroyed_at = COALESCE(pii_destroyed_at, NOW())
                  WHERE user_id = :id'
            );
            $st->execute(['id' => $userId]);
            $done['consult_requests'] = $st->rowCount();
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $done;
}

/** 견적 요청일로부터 1년 지난 견적의 개인정보 칸 정리 — $force 가 아니면 100번에 1번쯤, 한 번에 최대 200행 */
function privacy_estimate_purge(bool $force = false): int
{
    if (!$force && random_int(1, 100) > PRIVACY_PURGE_PCT) {
        return 0;
    }
    $pdo = db();
    if (!$pdo) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'UPDATE estimates SET customer_name = :n, phone = \'\', email = NULL, company_name = NULL, site_address = NULL, memo = NULL
              WHERE created_at < (NOW() - INTERVAL ' . PRIVACY_ESTIMATE_KEEP_DAYS . ' DAY) AND customer_name <> :n2
              LIMIT ' . PRIVACY_PURGE_MAX
        );
        $st->execute(['n' => PRIVACY_ERASED_NAME, 'n2' => PRIVACY_ERASED_NAME]);
        return $st->rowCount();
    } catch (\Throwable $e) {
        error_log('privacy estimate purge: ' . $e->getMessage());
        return 0;
    }
}
