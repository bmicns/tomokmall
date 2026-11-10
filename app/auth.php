<?php
declare(strict_types=1);
/**
 * 로그인 상태 헬퍼 (app/auth.php)
 *
 * 책임: 세션에서 로그인 사용자 정보 조회 및 권한 확인
 * 금지: UI 렌더링, DB 쿼리 직접 수행
 *
 * 제공 함수:
 *  - current_user()        로그인 사용자 정보 또는 null
 *  - is_logged_in()        로그인 여부
 *  - is_corporate_member() 기업회원(corporate/staff/admin) 여부
 *  - require_login()       미로그인 시 401 JSON 후 exit, 로그인 시 user 반환
 *  - require_corporate()   기업회원 아닐 시 401 JSON 후 exit
 *  - consult_cta_visible() AI 상담 '상담원 연결 유도' 표시 대상(로그인한 개인·기업회원만)
 *
 * @phpstan-type LoginUser array{
 *     id: int,
 *     role: 'customer'|'corporate'|'staff'|'admin',
 *     name: string,
 *     email: string,
 *     company_name: string|null
 * }
 */

/**
 * @return LoginUser|null
 */
function current_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $u = $_SESSION['user'] ?? null;
    if (!is_array($u) || !isset($u['id'], $u['role'], $u['name'], $u['email'])) {
        return null;
    }
    return $u;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_corporate_member(): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], ['corporate', 'staff', 'admin'], true);
}

/**
 * 미로그인 시 401 JSON 응답 후 exit.
 * 로그인 상태이면 user 배열 반환.
 *
 * @return LoginUser
 */
function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다.']);
        exit;
    }
    return $u;
}

/**
 * 기업회원 아닐 시 JSON 응답 후 exit.
 * - 미로그인 → require_login() 이 401 반환
 * - 로그인했으나 기업회원 아님 → 403 반환 (GAP-V2-04 해소)
 *
 * @return LoginUser
 */
function require_corporate(): array
{
    $u = require_login();
    if (!in_array($u['role'], ['corporate', 'staff', 'admin'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '기업회원 권한이 필요합니다.']);
        exit;
    }
    return $u;
}

/**
 * 관리자 페이지 보호 — 비관리자 접근 시 login.php로 리다이렉트 후 exit.
 */
function require_admin(): void
{
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

/**
 * 직원 페이지 보호 — staff 또는 admin이 아닐 시 login.php로 리다이렉트 후 exit.
 *
 * @return LoginUser
 */
function require_staff(): array
{
    $u = current_user();
    if (!$u || !in_array($u['role'], ['staff', 'admin'], true)) {
        header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    return $u;
}

/**
 * AI 상담 '상담원 연결 유도'(추천표 아래 카카오톡 채널 안내) 표시 대상 — 로그인한 개인회원(customer)·기업회원(corporate)만.
 * 비로그인·직원(staff)·관리자(admin)는 표시하지 않는다(사용자 결정 2026-11-10). 화면은 대상이 아니면 스크립트 자체를 넣지 않는다.
 *
 * @param array<string,mixed>|null $user current_user() 결과
 */
function consult_cta_visible(?array $user): bool
{
    return $user !== null && in_array($user['role'] ?? '', ['customer', 'corporate'], true);
}
